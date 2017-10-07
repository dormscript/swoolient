<?php

namespace MeanEVO\Swoolient\Workers;

use Psr\Log\LoggerAwareTrait;
use Swoole\Process;
use MeanEVO\Swoolient\Helpers\LoggerFactory;
use MeanEVO\Swoolient\Helpers\WorkerProcess;

abstract class AbstractMaster implements WorkerInterface {

	use LoggerAwareTrait;
	use HasSubworkerTrait, CanForwardPipe;

	public function __construct(WorkerProcess $process = null) {
		$name = empty($process) ? 'master' : $process->name;
		@cli_set_process_title(vsprintf('%s:%s', [
			env('APP_NAME'),
			strtoupper($name)
		]));
		$this->setLogger($this->makeLogger(ucfirst($name)));
		if (empty($process)) {
			// Running as dedicated process
			// Trigger worker start event manually
			$this->onWorkerStart();
			// Handle sigterm manually
			Process::signal(SIGTERM, [$this, 'onWorkerStop']);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStart() {
		$workers = $this->startAll();
		$this->daemonWorkers();
		foreach ($workers as $worker) {
			$this->registerPipeForwarder($worker);
			$this->workers[$worker->pid] = $worker;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	protected function makeLogger(string $name) {
		return LoggerFactory::create($name);
	}

}
