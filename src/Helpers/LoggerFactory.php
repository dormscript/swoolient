<?php

namespace MeanEVO\Swoolient\Helpers;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

class LoggerFactory {

	const FORMATTER = "[%datetime%] > %channel%.%level_name% - %message%\n";

	/**
	 * Initialize logger for master, worker process.
	 *
	 * @param string $name The logger channel
	 * @return Psr\Log\LoggerInterface
	 */
	public static function create(string $channel) {
		$logger = new Logger($channel);
		$handler = new StreamHandler(getenv('LOG_PATH'), getenv('LOG_LEVEL'));
		$handlerArguments = [self::FORMATTER, null, true, true];
		$handler->setFormatter(substr(getenv('LOG_PATH'), 0, 6) === 'php://' ? (
			new ColoredLineFormatter(null, ...$handlerArguments)) : (
			new LineFormatter(...$handlerArguments)
		));
		$logger->pushHandler($handler);
		$logger->pushProcessor(new PsrLogMessageProcessor());
		return $logger;
	}

}
