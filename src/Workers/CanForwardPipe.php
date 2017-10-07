<?php

namespace MeanEVO\Swoolient\Workers;

use Swoole\Event;
use MeanEVO\Swoolient\Helpers\WorkerProcess;

trait CanForwardPipe {

	/**
	 * @var array<int => WorkerProcess>
	 */
	protected $workers;

	private function registerPipeForwarder(WorkerProcess $process) {
		// Set a message listener for pipe
		Event::add($process->pipe, function ($pipe) use ($process) {
			// Strip out worker name, then forward message to the worker involved
			$message = $process->read();
			if ($worker = $this->getWorkerByName($message[0])) {
				$this->logger->debug('Forwarding message {message} to {worker}', [
					'message' => $message[1],
					'worker' => substr(strrchr($message[0], '\\'), 1),
				]);
				$worker->write(...array_slice($message, 1));
			} else {
				$this->logger->warn('{0} is not a valid message destination', $message);
			}
		});
	}

	/**
	 * @param string $name The worker's fqn.
	 * @return null|WorkerProcess
	 */
	private function getWorkerByName(string $name) {
		foreach ($this->workers as $pid => $worker) {
			if ($name !== $worker->fqn) {
				continue;
			}
			return $worker;
		}
	}

}