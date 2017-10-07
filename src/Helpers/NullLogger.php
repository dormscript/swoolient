<?php

namespace MeanEVO\Swoolient\Helpers;

use ReflectionClass;
use Psr\Log\NullLogger as PsrNullLogger;

class NullLogger extends PsrNullLogger {

	public function __construct($class = null) {
		if (!empty($class)) {
			$className = (new ReflectionClass($class))->getShortName();
		} else {
			$className = 'Master';
		}
		printf("%s - No valid logger defined\n", $className);
	}

}
