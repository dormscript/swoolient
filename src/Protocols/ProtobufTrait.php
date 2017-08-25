<?php

namespace MeanEVO\Swoolient\Protocols;

trait ProtobufTrait {

	protected function getPayload($message) {
		while (method_exists($message, 'getType')) {
			$getter = 'get' . ucfirst($message->getType());
			if (!method_exists($message, $getter)) {
				break;
			}
			$payload = call_user_func([$message, $getter]);
			if (is_scalar($payload)) {
				// Leave at least one level unpacked with scalar data
				break;
			}
			$message = $payload;
		}
		return $message;
	}

}
