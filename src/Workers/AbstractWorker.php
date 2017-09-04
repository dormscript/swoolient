<?php

namespace MeanEVO\Swoolient\Workers;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Swoole\Event;

abstract class AbstractWorker implements WorkerInterface {

	use LoggerAwareTrait;

	/**
	 * The process worker running with.
	 *
	 * @var \Helpers\WorkerProcess
	 */
	protected $process;

	/**
	 * The pipe message listener.
	 *
	 * @var array
	 */
	private $waitingForPipe = [];

	public function __construct($process) {
		@cli_set_process_title(env('APP_NAME') . ':' . strtoupper($process->name));
		$this->process = $process;
		// Pipe listener
		Event::add($process->pipe, [$this, 'onPipe']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStart() {
		if (!$this->logger) {
			echo 'No logger defined for ' . $this->process->name . PHP_EOL;
			$this->setLogger(new NullLogger);
		}
		$this->logger->info('Worker online');
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStop() {
		$this->logger->info('Worker shutting down');
	}

	/**
	 * Listen on pipe received.
	 * If ${message[0]} is callable in current scope, call it with args from index 1 and later;
	 * else redirect to onPipe method with message's payload (normal piping).
	 *
	 * @param int $pipe
	 * @return void
	 */
	final public function onPipe($pipe) {
		$payload = $this->process->read();
		$message = $payload[0];
		$this->notifyOnMessage($message);
		if (is_callable([$this, $message])) {
			// Call function named ${message} if exists
			call_user_func_array([$this, $message], array_slice($payload, 1));
		} else {
			$this->onPipeMessage($message);
		}
	}

	/**
	 * Listen on process pipe message received.
	 *
	 * @param string $message The received message
	 * @return void
	 */
	protected function onPipeMessage($message) {
		$this->logger->debug('Pipe-{number} received: {message}', [
			'number' => $this->process->pipe,
			'message' => $message,
		]);
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	public function callWorkerFunction(
		array $dst,
		array $args = null,
		string $expected = null,
		float $timeout = 3,
		callable $onTimeout = null
	) {
		$caller = function ($dst, $args = null) {
			list($worker, $function) = $dst;
			$this->process->write($worker, $function, ...(array) $args);
			return false;
		};
		$callerArgs = array_slice(func_get_args(), 0, 2);
		if (!empty($expected) && $timeout > 0) {
			// Register onTimeout callback
			$timerId = swoole_timer_tick(
				$timeout * 1000,
				function ($timerId, $callback) use ($callerArgs, $expected, $timeout) {
					$msg = <<<EOF
f({f}) expected response "{e}" not arriving in {i}s, trying to recover
EOF;
					$this->logger->warn($msg, [
						'f' => $callerArgs[0][1],
						'e' => $expected,
						'i' => $timeout,
						'action' => ', reying to recover'
					]);
					if (call_user_func_array($callback, $callerArgs)) {
						// Returns true => solved, stop ticking for one-off callback
						swoole_timer_clear($timerId);
					}
				},
				is_callable($onTimeout) ? $onTimeout : $caller
			);
			$this->registerOnPipeMessage($expected, function () use ($timerId) {
				@swoole_timer_clear($timerId);
			}, true);
		}
		call_user_func_array($caller, $callerArgs);
	}

	/**
	 * Register a callback on specified pipe message.
	 *
	 * @param string $message
	 * @param callable callback
	 * @param bool|null $onetime Whether is onetime listener or not,
	 * leaving blank => let callback returning decide
	 * @return void
	 */
	protected function registerOnPipeMessage(
		string $message,
		callable $callback,
		bool $onetime = null
	) {
		if (is_bool($onetime)) {
			$callback = function () use ($callback, $onetime) {
				call_user_func($callback);
				return $onetime;
			};
		}
		$this->waitingForPipe[$message][] = $callback;
	}

	protected function notifyOnMessage(string $message) {
		// Notify registered timers on message arriving
		foreach ($this->waitingForPipe[$message] ?? [] as &$listener) {
			if (call_user_func($listener)) {
				// Onetime listener, deregister
				unset($listener);
			}
		}
	}

}
