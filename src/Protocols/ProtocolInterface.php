<?php

namespace MeanEVO\Swoolient\Protocols;

interface ProtocolInterface {

	/**
	 * Decode package and emit onMessage($message) callback, $message is the result that decode returned.
	 *
	 * @param string. $buffer
	 * @param Client $client
	 * @return mixed
	 */
	public function decode($buffer);

	/**
	 * Encode package brefore sending to client.
	 *
	 * @param mixed  $data
	 * @param Client $client
	 * @return string
	 */
	public function encode($buffer);

}
