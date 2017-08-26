<?php

namespace MeanEVO\Swoolient\Workers;

use Swoole\Client;
use MeanEVO\Swoolient\Protocols\ProtocolInterface;

abstract class AbstractClient extends AbstractWorker {

	const RETRY_CONN_INTERVAL = 15;
	const RETRY_SEND_INTERVAL = 5;
	const CONN_ARGS = [
		'package_max_length' => 2048000,	// Maximum protocol context length
		'socket_buffer_size' => 1024 * 1024 * 2,	// 2MB buffer
	];
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
	const RESEND_ERRNO = [
		SOCKET_ECONNRESET,		// Connection reset by peer
		// SOCKET_EISCONN,		// Socket is already connected
		SOCKET_ENOTCONN,		// Socket is not connected
		// SOCKET_ESHUTDOWN,	// Cannot send after socket shutdown
		SOCKET_ETIMEDOUT,		// Connection timed out
		SOCKET_ECONNREFUSED,	// Connection refused
		SOCKET_EHOSTDOWN,		// Host is down
		SOCKET_EHOSTUNREACH,	// No route to host
	];

	/**
	 * The destination address to connect.
	 *
	 * @var array
	 */
	protected $dsn;

	/**
	 * The connection protocol to use.
	 *
	 * @var ProtocolInterface
	 */
	protected $protocol;

	/**
	 * The client instance.
	 *
	 * @var Client
	 */
	protected $client;

	public function __construct() {
		parent::__construct(...func_get_args());
		// Initialize protocol if exists
		if (is_subclass_of($this->protocol, ProtocolInterface::class)) {
			$this->protocol = new $this->protocol();
		} else {
			unset($this->protocol);
		}
		$scheme = $this->setAddress($this->dsn);
		// Initialize client
		$this->client = new Client($scheme, SWOOLE_SOCK_ASYNC);
		$this->client->set($this->protocol->arguments ?? [] + self::CONN_ARGS);
		$this->client->on('connect', [$this, 'onConnect']);
		$this->client->on('receive', [$this, 'onReceive']);
		$this->client->on('error', [$this, 'onError']);
		if (!in_array($scheme, [SWOOLE_UDP, SWOOLE_UDP6])) {
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
			$interval = self::RETRY_CONN_INTERVAL;
			swoole_timer_after($interval * 1000, [$this, 'connect']);
			$this->logger->error(socket_strerror($client->errCode) . '{retry}', [
				'retry' => ", reconnecting in ${interval} seconds",
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
		$this->connect();
	}

	/**
	 * Connect to endpoint.
	 *
	 * @return bool
	 */
	public function connect() {
		return $this->client->connect(...$this->dsn);
	}

	/**
	 * Send message(pending encode) to endpoint.
	 *
	 * @param mixed $message The message to send(encode)
	 * @param bool $handleResending Whether resending message on failure
	 * @return bool|int
	 */
	protected function send($message, bool $handleResending = true) {
		// Encode message if protocol encoder exists
		if (isset($this->protocol)) {
			// Pass multiple arguments as array to encoder
			$message = call_user_func([$this->protocol, 'encode'], $message);
		}
		return $this->sendBuffer($message, $handleResending);
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
	public function setAddress($dsn) {
		if (is_array($dsn)) {
			$dsn = $dsn[mt_rand(0, count($dsn) - 1)];
		}
		if (!filter_var($dsn, FILTER_VALIDATE_URL)) {
			if (!filter_var(env($dsn), FILTER_VALIDATE_URL)) {
				return;
			}
			$dsn = env($dsn);
		}
		list($host, $port, $scheme) = parse_url_swoole($dsn);
		$this->dsn = [$host, $port];
		return $scheme;
	}

	/**
	 * Send/Schedule buffer(encoded message) to endpoint.
	 *
	 * @param string $buffer The buffer to send
	 * @param bool $resending Whether to resend message on failure
	 * @return bool|int
	 */
	final public function sendBuffer(string $buffer, bool $resending = true) {
		if ($result = @$this->client->send($buffer)) {
			$this->logger->debug('Buffer sent, length {length}', [
				'length' => strlen($buffer),
				'buffer' => $buffer,
			]);
			return $result;
		}
		if (!in_array($this->client->errCode, self::RESEND_ERRNO)) {
			if ($this->client->errCode !== 0) {
				// Override resending to false due to errCode dissatisfaction
				$resending = false;
			}
		}
		if ($resending) {
			// Schedule buffer resending
			$interval = self::RETRY_SEND_INTERVAL;
			// TODO: Out of memory(128M) after approx. 550 scheduled resendings
			swoole_timer_after($interval * 1000, [$this, 'sendBuffer'], $buffer);
			$retryMsg = ", resending in ${interval} seconds";
		}
		$this->logger->error('Send failed: {reason}{retry}', [
			'reason' => socket_strerror($this->client->errCode),
			'retry' => $retryMsg ?? null,
		]);
		return false;
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

}
