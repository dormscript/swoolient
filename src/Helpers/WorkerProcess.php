<?php

namespace MeanEVO\Swoolient\Helpers;

use Swoole\Process;
use Exception;
use RuntimeException;
use InvalidArgumentException;
use ReflectionClass;
use Swoole\Serialize;
use MeanEVO\Swoolient\Helpers\LoggerFactory;
use MeanEVO\Swoolient\Workers\WorkerInterface;

class WorkerProcess extends Process {

	const PARENT_CHECK_INTERVAL = 0.1;

	/**
	 * The process payload's fully qualified name.
	 *
	 * @var string
	 */
	public $fqn;

	/**
	 * The process payloads' short name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The parent process id.
	 *
	 * @var int
	 */
	private $ppid;

	/**
	 * Initiate a helper which extends process with worker helpers.
	 *
	 * @param string $fqn The worker class's fully qualified name
	 * @param boolean $redirect @inheritdoc
	 * @param int $pipe @inheritdoc
	 */
	public function __construct($fqn, $redirect = false, $pipe = SOCK_DGRAM) {
		// Retain a pid of self as we are currently running in parent scope
		$this->ppid = posix_getpid();
		// Worker construction
		$reference = new ReflectionClass($fqn);
		if (!$reference->implementsInterface(WorkerInterface::class)) {
			throw new InvalidArgumentException(
				'This helper only handles class which implemented WorkerInterface'
			);
		}
		$this->fqn = $fqn;
		$this->name = ucwords($reference->getShortName());
		$arguments = array_slice(func_get_args(), 3);
		$bootstrap = $this->makeWorker($reference, $arguments);
		return parent::__construct($bootstrap, $redirect, $pipe);
	}

	/**
	 * {@inheritdoc}
	 */
	public function read($size = 2048) {
		$message = parent::read($size);
		// TODO: get rid of serialisation
		return Serialize::unpack($message) ?: $message;
	}

	/**
	 * {@inheritdoc}
	 */
	public function write($data) {
		// Stream may got merged in underlayer => usleep(10) or $pipe => SOCK_DGRAM;
		return parent::write(Serialize::pack(func_get_args(), true));
	}

	/**
	 * {@inheritdoc}
	 */
	public function exit($code = null) {
		if ($code < 0) {
			// Fatal error, shutdown all processes
			$this->exitAll(abs($code) % 256);
			return;
		}
		$this->kill($this->pid, SIGABRT);
		swoole_timer_after(1, function () use ($code) {
			// Validate error code with exit code
			parent::exit($code % 256);
		});
	}

	/**
	 * Exit all process in application.
	 *
	 * @param int|null $code
	 * @return void
	 */
	public function exitAll(int $code = 0) {
		$this->close();
		$this->kill($this->ppid, SIGTERM);
		$this->exit($code);
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	protected function makeWorker($class, $arguments) {
		return function ($process) use ($class, $arguments) {
			/*
			|-----------------------------------
			| === Sub-process context scope ===
			|-----------------------------------
			*/
			$logger = LoggerFactory::create($this->name);
			try {
				$instance = $class->newInstance($process, ...$arguments);
				$instance->setLogger($logger);
				// Worker successfully initiated
				$this->setUpListeners($instance);
				$instance->onWorkerStart();
			} catch (Exception $e) {
				$logger->error($e);
				$this->exit($e->getCode());
				return;
			}
		};
	}

	protected function setUpListeners($worker) {
		$this->signal(SIGABRT, function () use ($worker) {
			// Deregister signal handler as we have been notified
			$this->signal(SIGABRT, null);
			$worker->onWorkerStop();
			$this->exit(0);
		});
		// TODO: BUGGY - Capture variable not working with SIGTERM signal on macOS
		// Alternate 1: use exit function from swoole_process plus shutdown handler
		// Alternate 2: disable SIGTERM listener, use SIGABRT for graceful shutdown
		// $this->signal(SIGTERM, function () {
		// 	$this->exit(0);
		// });
		// register_shutdown_function(function () use ($worker) {
		// 	$worker->onWorkerStop();
		// });
		// Check parent process existence periodically
		$interval = env('PPID_CHECK_INTERVAL', self::PARENT_CHECK_INTERVAL);
		swoole_timer_tick($interval * 1000, function () use ($worker) {
			if (!$this->kill($this->ppid, 0)) {
				// Parent missing, terminate manually as processes are not related
				// $this->kill($this->pid, SIGABRT);
				$worker->onWorkerStop();
				$this->exit(3);
			}
		});
	}

}
