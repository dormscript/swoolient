<?php

namespace MeanEVO\Swoolient\Workers;

interface WorkerInterface {

	/**
	 * Callback when worker finished initialisation.
	 *
	 * @return void
	 */
	public function onWorkerStart();

	/**
	 * Callback when worker is shutting down.
	 *
	 * @return void
	 */
	public function onWorkerStop();

}
