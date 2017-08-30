<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace KD2;

class XML_RPC_Server
{
	protected $server = null;

	/**
	 * Constructs a XML_RPC_Server object.
	 */
	public function __construct()
	{
		if (!function_exists('xmlrpc_server_create'))
		{
			throw new \Exception('XML-RPC extension is not installed.');
		}

		$this->server = xmlrpc_server_create();
	}

	/**
	 * Destroys the server.
	 */
	public function __destruct()
	{
		xmlrpc_server_destroy($this->server);
	}

	/**
	 * Register a method name to a callback.
	 * @param  string $name     Name of the server method
	 * @param  mixed  $callback Callback
	 * @return boolean 			TRUE if successful
	 */
	public function registerMethod($name, $callback)
	{
		if (!is_callable($callback))
		{
			throw new \InvalidArgumentException('Callback argument is not a valid callback.');
		}

		return xmlrpc_server_register_method($this->server, $name, $callback);
	}

	/**
	 * Start the server: parse the client request and calls the callback linked to the method
	 * @return boolean TRUE if successful, FALSE if there was no request
	 */
	public function start()
	{
		if ($response = xmlrpc_server_call_method($xmlrpc_server_handler, $HTTP_RAW_POST_DATA, null))
	    {
    		header('Content-Type: text/xml');
    		echo $response;
    		return true;
    	}
    	else
    	{
    		return false;
    	}
	}

	public function returnError($str, $code = 42)
	{
		header('Content-Type: text/xml');
		echo xmlrpc_encode([
        	'faultCode'		=>	$code,
        	'faultString'	=>	$str
        ]);
        exit;
	}

	/**
	 * Encodes a string in a base64 XML-RPC data type
	 * @param  string $binary Input binary string
	 * @return object         XML-RPC object
	 */
	static public function base64($binary)
	{
		if (xmlrpc_set_type($binary, 'base64'))
		{
			return $binary;
		}

		return false;
	}

	/**
	 * Encodes a string in a datetime XML-RPC data type
	 * @param  string $timestamp 	Input UNIX timestamp
	 * @return object         		XML-RPC object
	 */
	static public function datetime($timestamp)
	{
		$timestamp = date('c', $timestamp);

		if (xmlrpc_set_type($timestamp, 'datetime'))
		{
			return $timestamp;
		}

		return false;
	}
}