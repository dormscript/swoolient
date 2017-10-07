<?php

namespace MeanEVO\Swoolient\Workers;

use ReflectionClass;
use Swoole\Process;
use MeanEVO\Swoolient\Helpers\WorkerProcess;
use Swoole\Timer;

trait HasSubworkerTrait {

	/**
	 * @var array<int => WorkerProcess>
	 */
	protected $workers;

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStart() {
		$this->daemonWorkers();
		foreach ($this->startAll() as $worker) {
			$this->workers[$worker->pid] = $worker;
		}
		if ($this instanceof WorkerInterface && get_parent_class()) {
			parent::onWorkerStart();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStop() {
		$this->notifyAll(WorkerProcess::EXIT_SIGNAL);
		if ($this instanceof WorkerInterface && get_parent_class()) {
			parent::onWorkerStop();
		} else {
			exit();
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

	private function notifyAll(int $signo) {
		foreach ($this->workers as $worker) {
			Process::kill($worker->pid, $signo);
		}
	}

	private function daemonWorkers() {
		Process::signal(SIGCHLD, function () {
			// Daemon child workers, restart process if needed
			while ($info = Process::wait(false)) {
				/**
				 * @var int $code from $info
				 * @var int $pid from $info
				 * @var int $signal from $info
				 */
				extract($info);
				/** @var WorkerProcess $worker */
				$worker = $this->workers[$pid];
				// Remove exited worker handler reference
				unset($this->workers[$pid]);
				if ($signal > 0) {
					$reason = 'signal ' . $signal;
				} else {
					$reason = 'code ' . $code;
				}
				// if (empty($reason)) {
				// 	// Worker initiated exit aka graceful shutdown
				// 	$this->logger->notice('Worker-{name}({pid}) exited gracefully', [
				// 		'name' => $worker->name,
				// 		'pid' => $pid,
				// 	]);
				// 	break;
				// }
				// Schedule process restarting
				$this->logger->critical(
					'Worker-{name}({pid}) exited with {reason}, restarting...',
					[
						'name' => $worker->name,
						'pid' => $pid,
						'reason' => $reason ?? 'exit code 0',
					]
				);
				// $worker = $this->startOne($worker->fqn);
				// $this->workers[$worker->pid] = $worker;
				Timer::after(100, function () use ($worker) {
					$this->workers[$worker->start()] = $worker;
				});
			};
		});
	}

}