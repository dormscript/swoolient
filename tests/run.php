<?php

namespace Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use MeanEVO\Swoolient\Helpers\LoggerFactory;
use MeanEVO\Swoolient\Workers\AbstractMaster;

(new Dotenv(__DIR__))->load();

new class extends AbstractMaster {

	public function __construct() {
		$this->setLogger(LoggerFactory::create('Master'));
		parent::__construct();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function startAll() {
		return [
			$this->startOne(TestNode1::class),
			$this->startOne(TestNode2::class),
			$this->startOne(TestNode3::class),
			$this->startOne(TestNode4::class),
		];
	}

};
