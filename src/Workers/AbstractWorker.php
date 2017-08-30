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
	private $pipeListeners;

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
	public function onPipe($pipe) {
		$payload = $this->process->read();
		$message = $payload[0];
		// Notify registered timer on message arriving
		foreach ($this->pipeListeners[$message] ?? [] as &$listener) {
			if (call_user_func($listener)) {
				continue;
			}
			// Returns false => one-off listener, deregister
			unset($listener);
		}
		if (is_callable([$this, $message])) {
			// Call function named ${message} if exists
			call_user_func([$this, $message], ...array_slice($payload, 1));
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
		$this->logger->warn('Pipe-{number} received: {message}', [
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
		int $timeout = 3000,
		callable $onTimeout = null
	) {
		$caller = function ($args) {
			list($worker, $function) = array_shift($args);
			$this->process->write($worker, $function, ...$args);
		};
		$callerArgs = array_slice(func_get_args(), 0, 1);
		// Register onTimeout callback
		if (!empty($expected) && $timeout > 0) {
			$callback = function ($timerId, $callback) use ($expected, $timeout) {
				$this->logger->warn('f({f}) expected response "{e}" not arriving in {i}s', [
					'f' => $callback[1][0][1],
					'e' => $expected,
					'i' => $timeout / 1000,
				]);
				call_user_func(...$callback);
			};
			$onTimeout = is_callable($onTimeout) ? $onTimeout : $caller;
			$timerId = swoole_timer_tick($timeout, $callback, [$onTimeout, $callerArgs]);
			$this->pipeListeners[$expected][] = function () use ($timerId) {
				@swoole_timer_clear($timerId);
			};
		}
		$caller($callerArgs);
	}

}
