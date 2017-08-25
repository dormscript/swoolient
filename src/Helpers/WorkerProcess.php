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

	const PARENT_CHECK_INTERVAL = 3;

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
		// Validate error code with exit code
		parent::exit($code % 256);
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
			$this->signal(SIGTERM, function () {
				// Returns 0 for normal termination process
				$this->exit(0);
			});
			$logger = LoggerFactory::create($this->name);
			try {
				$instance = $class->newInstance($process, ...$arguments);
				$instance->setLogger($logger);
				$instance->onWorkerStart();
			} catch (Exception $e) {
				$logger->error($e);
				$this->exit($e->getCode());
				return;
			}
			// Worker successfully initiated
			$this->setUpListeners($instance);
		};
	}

	protected function setUpListeners($worker) {
		register_shutdown_function(function () use ($worker) {
			$worker->onWorkerStop();
		});
		// Check parent process existence periodically
		$interval = self::PARENT_CHECK_INTERVAL * 1000;
		swoole_timer_tick($interval, function () use ($worker) {
			if (!$this->kill($this->ppid, 0)) {
				// Parent missing, exit manually as processes are not related
				$worker->onWorkerStop();
				$this->exit(3);
			}
		});
	}

}
