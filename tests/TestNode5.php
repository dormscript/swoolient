<?php

namespace Tests;

use MeanEVO\Swoolient\Workers\AbstractWorker;

class TestNode5 extends AbstractWorker {

	public function onWorkerStart() {
		parent::onWorkerStart();
		$payload = mt_rand(0.01 * 1000 ** 2, 0.05 * 1000 ** 2);
		printf("%s start processing in workspace for %dms\n", __CLASS__, $payload / 1000);
		swoole_timer_after($payload / 1000, function () {
			$this->process->exit();
			// $this->process->kill(posix_getpid(), SIGTERM);
			// exit();
		});
	}

}
