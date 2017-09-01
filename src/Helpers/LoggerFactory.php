<?php

namespace MeanEVO\Swoolient\Helpers;

use Raven_Client as RavenClient;
use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RavenHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

class LoggerFactory {

	const LOG_FORMAT = "[%datetime%] > %channel%.%level_name% - %message%\n";
	const REPORT_FORMAT = "%channel%: %level_name% - %message%\n";

	/**
	 * Initialize logger for master, worker process.
	 *
	 * @param string $name The logger channel
	 * @return Psr\Log\LoggerInterface
	 */
	public static function create(string $channel) {
		$logger = new Logger($channel);
		// Logger
		$logHandler = new StreamHandler(
			$dsn = env('LOG_DSN', 'php://stdout'),
			env('LOG_LEVEL', Logger::INFO)
		);
		$formatter = env('LOG_FORMAT', self::LOG_FORMAT);
		$formatterOptions = [null, true, true];
		if (substr($dsn, 0, 6) === 'php://'
			&& class_exists(ColoredLineFormatter::class)) {
			// Output to a stream, colorize it if possible
			$formatter = new ColoredLineFormatter(
				null,
				$formatter,
				...$formatterOptions
			);
		} else {
			$formatter = new LineFormatter($formatter, ...$formatterOptions);
		}
		$handlers[] = $logHandler->setFormatter($formatter);
		// Reporter
		if (($dsn = env('SENTRY_DSN')) && class_exists(RavenClient::class)) {
			// Report to sentry enabled
			$reportHandler = new RavenHandler(
				new RavenClient($dsn),
				env('REPORT_LEVEL', Logger::ERROR)
			);
			$formatter = env('REPORT_FORMAT', self::REPORT_FORMAT);
			$handlers[] = $reportHandler->setFormatter(
				new LineFormatter($formatter, ...$formatterOptions)
			);
		}
		$logger->setHandlers($handlers);
		$logger->pushProcessor(new PsrLogMessageProcessor());
		return $logger;
	}

}
