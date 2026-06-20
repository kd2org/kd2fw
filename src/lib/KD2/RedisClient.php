<?php

namespace KD2;

/**
 * Minimalist Redis client
 * (C) 2019 BohwaZ
 * Inspired by https://github.com/ptrofimov/tinyredisclient
 * Redis protocol: https://redis.io/topics/protocol
 */
class RedisClient
{
	protected $flags, $timeout, $server, $socket;

	public function __construct(string $server = 'tcp://localhost:6379', int $timeout = 10, int $flags = STREAM_CLIENT_CONNECT)
	{
		$this->server = $server;
		$this->flags = $flags;
		$this->timeout = $timeout;
	}

	public function flushall()
	{
		throw new \RuntimeException('Forbidden');
	}

	public function __call(string $method, array $args)
	{
		$method = strtoupper($method);

		array_unshift($args, $method);

		$cmd = '*' . count($args) . "\r\n";

		foreach ($args as $item)
		{
			if (null === $item) {
				$cmd = "\$-1\r\n";
			}
			else {
				$cmd .= '$' . strlen($item) . "\r\n" . $item . "\r\n";
			}
		}

		fwrite($this->getSocket(), $cmd);

		return $this->parseResponse();
	}

	protected function getSocket()
	{
		if (null === $this->socket) {
			$this->socket = stream_socket_client($this->server, $errno, $errstr, $this->timeout, $this->flags);

			if (!$this->socket) {
				throw new \RuntimeException(sprintf('Cannot connect to Redis server at %s: %s - %s', $this->server, $errno, $errstr));
			}

			stream_set_timeout($this->socket, $this->timeout);
		}

		return $this->socket;
	}

	protected function parseResponse()
	{
		$line = fgets($this->getSocket());

		$type = substr($line, 0, 1);
		$result = substr($line, 1, strlen($line) - 3);

		if ($type == '-') {
			// error message
			throw new \RuntimeException('Redis error reply: ' . $result);
		}
		elseif ($type == '$') {
			// bulk string
			if ($result == -1)
			{
				// NULL bulk string
				return null;
			}

			$line = fread($this->getSocket(), $result + 2);
			$result = substr($line, 0, $result);
		}
		elseif ($type == '*') {
			if ($result == -1)
			{
				// NULL array
				return null;
			}

			// multi-bulk reply / arrays
			$count = (int) $result;
			$result = [];

			for ($i = 0; $i < $count; $i++)
			{
				$result[] = $this->parseResponse();
			}
		}
		elseif ($type == ':') {
			// integer
			$result = (int) $result;
		}
		elseif ($type == '+') {
			// Simple string
			return $result;
		}
		elseif (!feof($this->socket) && trim($line) === '') {
			// In some very rare instances, Redis can return an empty line
			// in that case let's just go to the next line
			return $this->parseResponse();
		}
		else {
			throw new \RuntimeException(sprintf('Invalid response from Redis server: (%d) "%s"', strlen($line), $line));
		}

		return $result;
	}
}
