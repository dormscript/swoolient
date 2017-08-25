<?php

namespace Tests;

use MeanEVO\Swoolient\Workers\AbstractWorker;

class TestNode2 extends AbstractWorker {

	public function __construct($process) {
		parent::__construct($process);
		$payload = mt_rand(0.1 * 1000 ** 2, 0.5 * 1000 ** 2);
		printf("%s start processing in constructor for %dms\n", __CLASS__, $payload / 1000);
		usleep($payload);
	}

}
