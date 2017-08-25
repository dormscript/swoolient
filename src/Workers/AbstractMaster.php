<?php

namespace MeanEVO\Swoolient\Workers;

use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Swoole\Process;
use MeanEVO\Swoolient\Helpers\WorkerProcess;
use MeanEVO\Swoolient\Workers\WorkerInterface;

abstract class AbstractMaster {

	use LoggerAwareTrait;

	protected $workers;

	public function __construct() {
		@cli_set_process_title(env('APP_NAME') . ':MASTER');
		$workers = $this->startAll();
		$this->setUpListeners();
		foreach ($workers as $worker) {
			$this->setUpPipeForwarder($worker);
			$this->workers[$worker->pid] = $worker;
		}
		if (!$this->logger) {
			echo 'No logger defined'. PHP_EOL;
			$this->setLogger(new NullLogger);
		}
	}

	/**
	 * Start worker by fully qualified class name.
	 *
	 * @param string $fqn The worker class's fully qualified name
	 * @return WorkerProcess
	 */
	public function startOne(string $fqn) {
		$process = new WorkerProcess($fqn);
		$process->start();
		return $process;
	}

	/**
	 * Start all workers.
	 * Normally by calling startOne($fqn) several times.
	 *
	 * @return array<WorkerProcess>
	 */
	abstract protected function startAll();

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	private function setUpPipeForwarder($process) {
		// Set a message listener for pipe
		swoole_event_add($process->pipe, function ($pipe) use ($process) {
			// Strip out worker name, then forward message to the worker involved
			$message = $process->read();
			if ($worker = $this->getWorkerByName($message[0])) {
				$this->logger->debug('Forwarding message {1} to {0}', $message);
				$worker->write(...array_slice($message, 1));
			} else {
				$this->logger->warn('{0} is not a valid message destination', $message);
			}
		});
	}

	private function getWorkerByName(string $name) {
		foreach ($this->workers as $pid => $worker) {
			if ($name !== $worker->fqn) {
				continue;
			}
			return $worker;
		}
	}

	private function setUpListeners() {
		Process::signal(SIGTERM, function () {
			// Deregister callback on SIGCHLD signal
			// Process::signal(SIGCHLD, null);
			foreach ($this->workers as $worker) {
				Process::kill($worker->pid, SIGTERM);
			}
			exit();
		});
		// Daemon child workers, restart process if needed
		Process::signal(SIGCHLD, function () {
			while ($info = Process::wait(false)) {
				extract($info);	// ['code' => int, 'pid' => int, 'signal' => int]
				$worker = $this->workers[$pid];
				// Remove exited worker handler reference
				unset($this->workers[$pid]);
				if ($code !== 0) {
					$reason = 'code ' . $code;
				} elseif ($signal !== 0) {
					$reason = 'signal ' . $signal;
				}
				if (empty($reason)) {
					// Worker initiated exit aka graceful shutdown
					$this->logger->notice('Worker-{name}({pid}) exited gracefully', [
						'name' => $worker->name,
						'pid' => $pid,
					]);
					break;
				}
				// Schedule process restarting
				$this->logger->critical(
					'Worker-{name}({pid}) exited by {reason}, restarting...',
					[
						'name' => $worker->name,
						'pid' => $pid,
						'reason' => $reason,
					]
				);
				$pid = $worker->start();
				$this->workers[$pid] = $worker;
			}
		});
	}

}
