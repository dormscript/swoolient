<?php

function parse_url_swoole($url = null, $socketScheme = SWOOLE_UNIX_STREAM) {
	if (!is_string($url) || !$url = parse_url($url)) {
		return false;
	}
	extract($url);
	if (!empty($path)) {
		return [$path, 0, $socketScheme];
	}
	if (empty($scheme)) {
		return false;
	}
	if ($scheme !== 'tcp' && $scheme !== 'udp') {
		return [$host, $port ?? null, $scheme];
	}
	if (!filter_var($host, FILTER_VALIDATE_IP)) {
		// Try to resolve ${host} to IP address
		$host = gethostbyname($host);
	}
	if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		$scheme = $scheme === 'tcp' ? SWOOLE_TCP : SWOOLE_UDP;
	} else {
		$scheme = $scheme === 'tcp' ? SWOOLE_TCP6 : SWOOLE_UDP6;
	}
	return [$host, $port, $scheme];
}

if (! function_exists('env')) {
	/**
	 * Gets the value of an environment variable.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	function env($key, $default = null) {
		$value = getenv($key, true);

		if ($value === false) {
			return value($default);
		}

		switch (strtolower($value)) {
			case 'true':
			case '(true)':
				return true;
			case 'false':
			case '(false)':
				return false;
			case 'empty':
			case '(empty)':
				return '';
			case 'null':
			case '(null)':
				return;
		}

		if (strlen($value) > 1) {
			return trim($value, '"');
		}

		return $value;
	}
}

if (! function_exists('value')) {
	/**
	 * Return the default value of the given value.
	 *
	 * @param  mixed  $value
	 * @return mixed
	 */
	function value($value) {
		return $value instanceof Closure ? $value() : $value;
	}
}
