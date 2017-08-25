<?php

namespace MeanEVO\Swoolient\Workers;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

abstract class AbstractWorker implements WorkerInterface {

	use LoggerAwareTrait;

	/**
	 * The process worker running with.
	 *
	 * @var \Helpers\WorkerProcess
	 */
	protected $process;

	public function __construct($process) {
		@cli_set_process_title(env('APP_NAME') . ':' . strtoupper($process->name));
		$this->process = $process;
		// Pipe listener
		swoole_event_add($process->pipe, function ($pipe) {
			$this->onPipeMessage($this->process->read());
		});
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
	 * Listen on process pipe message received.
	 * If ${message[0]} is callable, call it with args from index 1 and later,
	 * else redirect to onPipe method with message's payload (normal piping).
	 *
	 * @param string $message The received message
	 * @return void
	 */
	protected function onPipeMessage($message) {
		$callable = [$this, $message[0]];
		if (is_callable($callable)) {
			// Call function named ${message} if exists
			call_user_func($callable, ...array_slice($message, 1));
			return;
		}
		$this->logger->warn('Pipe-{number} received: {message}', [
			'number' => $this->process->pipe,
			'message' => $message,
		]);
	}

}
