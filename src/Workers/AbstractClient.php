<?php

namespace MeanEVO\Swoolient\Workers;

use ReflectionClass;
use Swoole\Client;
use MeanEVO\Swoolient\Protocols\ProtocolInterface;

abstract class AbstractClient extends AbstractWorker {

	const DSN_STRING = '';
	const PROTOCOL_CLASS = null;
	const RETRY_CONN_INTERVAL = 3;
	const RECOVER_ERRNO = [
		SOCKET_ENETDOWN,		// Network is down
		SOCKET_ENETUNREACH,		// Network is unreachable
		SOCKET_ENETRESET,		// Network dropped connection on reset
		SOCKET_ECONNRESET,		// Connection reset by peer
		SOCKET_ENOBUFS,			// No buffer space available
		SOCKET_ETIMEDOUT,		// Connection timed out
		SOCKET_ECONNREFUSED,	// Connection refused
		SOCKET_EHOSTDOWN,		// Host is down
		SOCKET_EHOSTUNREACH,	// No route to host
	];

	/**
	 * The client instance.
	 *
	 * @var Client
	 */
	protected $client;

	/**
	 * The internet protocol.
	 *
	 * @var oneof SWOOLE_TCP(6)|UDP(6)
	 */
	protected $scheme = SWOOLE_TCP;

	/**
	 * The destination address to connect.
	 *
	 * @var array
	 */
	private $dsn;

	/**
	 * The connection protocol instance.
	 *
	 * @var ProtocolInterface
	 */
	private $protocol;

	/**
	 * The onConnect event listener.
	 *
	 * @var array
	 */
	private $waitingForConnection = [];

	public function __construct() {
		parent::__construct(...func_get_args());
		// Initialize protocol if valid		// Initialize protocol if exists
		if ($protocolClass = static::PROTOCOL_CLASS) {
			if (is_subclass_of($protocolClass, ProtocolInterface::class)) {
				$this->protocol = new $protocolClass();
			}
		}
		$this->setDestination(static::DSN_STRING);
		// Initialize client
		$this->client = new Client($this->scheme, SWOOLE_SOCK_ASYNC);
		$this->client->set($this->protocol->arguments ?? []);
		$this->client->on('connect', [$this, 'onConnect']);
		$this->client->on('receive', [$this, 'onReceive']);
		$this->client->on('error', [$this, 'onError']);
		if (!in_array($this->scheme, [SWOOLE_UDP, SWOOLE_UDP6])) {
			$this->client->on('close', [$this, 'onClose']);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStart() {
		parent::onWorkerStart();
		$this->connect();
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStop() {
		$this->close();
		parent::onWorkerStop();
	}

	/**
	 * Listen on client connected.
	 *
	 * @param Swoole\Client $client The connected client
	 * @return void
	 */
	public function onConnect(Client $client) {
		$this->logger->debug('Connection established with {dsn} via {src}', [
			'dsn' => vsprintf('%1$s:%2$d', $this->dsn),
			'src' => vsprintf('%2$s:%1$d', $this->client->getSockName()),
		]);
		$this->notifyOnConnect();
	}

	/**
	 * Listen on buffer received.
	 *
	 * @param Swoole\Client $client The connected client
	 * @param string $buffer The received buffer
	 * @return void
	 */
	final public function onReceive(Client $client, $buffer) {
		// Decode buffer
		if (isset($this->protocol)) {
			$buffer = call_user_func([$this->protocol, 'decode'], $buffer);
		}
		$this->onMessage($client, $buffer);
	}

	/**
	 * Listen on message(decoded buffer) received.
	 *
	 * @param Swoole\Client $client The connected client
	 * @param mixed $message The received message
	 * @return void
	 */
	abstract protected function onMessage(Client $client, $message);

	/**
	 * Listen on client error.
	 *
	 * @param Swoole\Client $client The connected client
	 * @return void
	 */
	public function onError(Client $client) {
		if (in_array($client->errCode, self::RECOVER_ERRNO)) {
			// Schedule client reconnecting
			if ($interval = static::RETRY_CONN_INTERVAL ?: false) {
				swoole_timer_after($interval * 1000, [$this, 'connect']);
			} else {
				$this->connect();
			}
			$this->logger->error('{reason}, reconnecting{after}', [
				'reason' => socket_strerror($client->errCode),
				'after' => $interval ? " in ${interval} seconds" : null,
			]);
		} else {
			$this->logger->error(socket_strerror($client->errCode));
			$this->process->exit($client->errCode);
		}
	}

	/**
	 * Listen on client close.
	 *
	 * @param Swoole\Client $client The connected client
	 * @return void
	 */
	public function onClose(Client $client) {
		if ($this->isClosedGracefully()) {
			return;
		}
		if (empty($this->client->errCode)) {
			$this->process->exit(SOCKET_ECONNABORTED);
		}
		$this->connect();
	}

	/**
	 * Connect to endpoint.
	 *
	 * @return bool
	 */
	public function connect() {
		if (!is_array($this->dsn)) {
			$this->logger->alert('Connection destination not set');
			return false;
		}
		return $this->client->connect(...$this->dsn);
	}

	/**
	 * Send message(pending encode) to endpoint.
	 *
	 * @param mixed $message The message to send(encode)
	 * @return bool|int
	 */
	protected function send($message) {
		// Encode message(s) if protocol encoder exists
		if (isset($this->protocol)) {
			$buffer = call_user_func_array(
				[$this->protocol, 'encode'],
				func_get_args()
			);
		} else {
			// Raw message as buffer to send
			$buffer = $message;
		}
		if ($result = @$this->client->send($buffer)) {
			$this->logger->debug('Buffer sent, length {length}', [
				'length' => strlen($buffer),
				'buffer' => $buffer,
			]);
		} else {
			$this->logger->error('Send failed: {reason}', [
				'reason' => socket_strerror($this->client->errCode),
			]);
		}
		return $result;
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Set the connection destination address.
	 *
	 * @param array|string $dsn
	 * @return void|int SWOOLE_SOCK_X(SCHEME)
	 */
	public function setDestination($dsn) {
		if (is_array($dsn)) {
			$dsn = $dsn[array_rand($dsn)];
		}
		if (!filter_var($dsn, FILTER_VALIDATE_URL)) {
			if (!filter_var(env($dsn), FILTER_VALIDATE_URL)) {
				return;
			}
			$dsn = env($dsn);
		}
		list($host, $port, $scheme) = parse_url_swoole($dsn);
		$this->dsn = [$host, $port];
		$this->scheme = $scheme;
	}

	/**
	 * Close client connection gracefully.
	 *
	 * @param bool $graceful Whether to handle reconnect.
	 * @return void
	 */
	protected function close(bool $graceful = true) {
		if ($graceful === true) {
			$this->client->errCode = -1;
		}
		@$this->client->close();
	}

	protected function isClosedGracefully() {
		if ($this->client->errCode === -1) {
			// Intended close, reset errCode and do nothing
			$this->client->errCode = swoole_errno();
			return true;
		}
		return false;
	}

	/**
	 * Reconnect to endpoint
	 *
	 * @return bool
	 */
	public function reconnect() {
		if ($this->client->isConnected()) {
			$this->close();
		}
		$this->connect();
	}

	/**
	 * TODO: [registerOnConnect description]
	 * @param  callable  $callback [description]
	 * @param  bool|null $onetime  [description]
	 * @return [type]              [description]
	 */
	protected function registerOnConnect(callable $callback, bool $onetime = null) {
		if (is_bool($onetime)) {
			$callback = function () use ($callback, $onetime) {
				call_user_func($callback);
				return $onetime;
			};
		}
		$this->waitingForConnection[] = $callback;
	}

	protected function notifyOnConnect() {
		// Notify registered listeners on connect
		foreach ($this->waitingForConnection as &$listener) {
			if (call_user_func($listener)) {
				unset($listener);
			}
		}
	}

	/**
	 * Register a ticker runs periodically while client is connected.
	 *
	 * @param int $interval
	 * @param callable $callback
	 * @param array|null $args
	 * @return void
	 */
	protected function registerOnlineTicker(
		int $interval,
		callable $callback,
		array $args = null
	) {
		$this->logger->debug('Scheduled an online time ticker');
		swoole_timer_tick($interval, function ($timerId, $args) use ($callback) {
			if (!$this->client->isConnected()) {
				// Skip ticking at once
				$this->logger->warn(
					'Ticker skipped at once due to connectivity issues'
				);
				return false;
			}
			call_user_func_array($callback, $args ?: []);
		}, $args);
	}

}
