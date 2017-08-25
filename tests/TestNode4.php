<?php

namespace Tests;

use MeanEVO\Swoolient\Workers\AbstractWorker;

class TestNode4 extends AbstractWorker {

	public function onWorkerStart() {
		parent::onWorkerStart();
		$payload = mt_rand(0.1 * 1000 ** 2, 0.5 * 1000 ** 2);
		printf("%s start processing in workspace for %dms\n", __CLASS__, $payload / 1000);
		usleep($payload);
	}

}
