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

	const LOCAL_FORMATTER = "[%datetime%] > %channel%.%level_name% - %message%\n";
	const REPORT_FORMATTER = "%channel%: %level_name% - %message%\n";

	/**
	 * Initialize logger for master, worker process.
	 *
	 * @param string $name The logger channel
	 * @return Psr\Log\LoggerInterface
	 */
	public static function create(string $channel) {
		$logger = new Logger($channel);
		// Local
		$formatterOptions = [self::LOCAL_FORMATTER, null, true, true];
		$localHandler = new StreamHandler(env('LOG_PATH'), env('LOG_LEVEL'));
		if (substr(env('LOG_PATH'), 0, 6) === 'php://'
			&& class_exists(ColoredLineFormatter::class)) {
			// Output to a stream, colorize it if possible
			$formatter = new ColoredLineFormatter(null, ...$formatterOptions);
		} else {
			$formatter = new LineFormatter(...$formatterOptions);
		}
		$handlers[] = $localHandler->setFormatter($formatter);
		// Report
		if (($dsn = env('SENTRY_DSN')) && class_exists(RavenClient::class)) {
			// Report to sentry enabled
			$remoteHandler = new RavenHandler(
				new RavenClient($dsn)
			);
			$formatter = env('APP_NAME') . ' - ' . self::REPORT_FORMATTER;
			$formatterOptions = [$formatter, null, true, true];
			$handlers[] = $remoteHandler->setFormatter(
				new LineFormatter(...$formatterOptions)
			);
		}
		$logger->setHandlers($handlers);
		$logger->pushProcessor(new PsrLogMessageProcessor());
		return $logger;
	}

}
