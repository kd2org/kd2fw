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

class ErrorManager
{
	const PRODUCTION = 1;
	const DEVELOPMENT = 2;

	const RED = '[1;41m';
	const RED_FAINT = '[1m';
	const YELLOW = '[33m';

	/**
	 * true = catch exceptions, false = do nothing
	 * @var null
	 */
	static protected $enabled = null;

	/**
	 * HTML template used for displaying production errors
	 * @var string
	 */
	static protected $production_error_template = '';

	/**
	 * E-Mail address where to send errors
	 * @var boolean
	 */
	static protected $email_errors = false;

	/**
	 * Custom exception handlers
	 * @var array
	 */
	static protected $custom_handlers = [];

	/**
	 * Additional debug environment information that should be included in logs
	 * @var array
	 */
	static protected $debug_env = [];

	/**
	 * Does the terminal support ANSI colors
	 * @var boolean
	 */
	static protected $term_color = false;

	/**
	 * Will be set to true when catching an exception to avoid double catching
	 * with the shutdown function
	 * @var boolean
	 */
	static protected $catching = false;

	/**
	 * Handles PHP shutdown on fatal error to be able to catch the error
	 * @return void
	 */
	static public function shutdownHandler()
	{
		// Stop here if disabled or if the script ended with an exception
		if (!self::$enabled || self::$catching)
			return false;

		$error = error_get_last();
		
		if (in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR, E_USER_ERROR], TRUE))
		{
			self::exceptionHandler(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), false);
		}
	}

	/**
	 * Internal error handler to throw them as exceptions
	 * (private use)
	 */
	static public function errorHandler($severity, $message, $file, $line)
	{
		if (!(error_reporting() & $severity)) {
			// Don't report this error (for example @unlink)
			return;
		}

		throw new \ErrorException($message, 0, $severity, $file, $line);
	}

	/**
	 * Print to terminal with colors if available
	 * @param  string $message Message to print
	 * @param  const  $pipe    UNIX pipe to outpit to (STDOUT, STDERR...)
	 * @param  string $color   One of self::COLOR constants
	 * @return void
	 */
	static public function termPrint($message, $pipe = STDOUT, $color = null)
	{
		if ($color)
		{
			$message = chr(27) . $color . $message . chr(27) . "[0m";
		}

		fwrite($pipe, $message . PHP_EOL);
	}

	/**
	 * Main exception handler
	 * @param  object  $e    Exception or Error (PHP 7) object
	 * @param  boolean $exit Exit the script at the end
	 * @return void
	 */
	static public function exceptionHandler($e, $exit = true)
	{
		self::$catching = true;
		
		foreach (self::$custom_handlers as $class=>$callback)
		{
			if ($e instanceOf $class)
			{
				call_user_func($callback, $e);
				$e = false;
				break;
			}
		}

		if ($e !== false)
		{
			$file = str_replace($_SERVER['DOCUMENT_ROOT'], '', $e->getFile());
			$ref = null;
			$log = self::exceptionAsLog($e, $ref);

			if (PHP_SAPI == 'cli')
			{
				self::termPrint(get_class($e) . ' [Code: ' . $e->getCode() . ']', STDERR, self::RED);
				self::termPrint($e->getMessage(), STDERR, self::RED_FAINT);
				self::termPrint('Line ' . $e->getLine() . ' in ' . $file, STDERR, self::YELLOW);
				self::termPrint(PHP_EOL . $e->getTraceAsString(), STDERR);
			}
			else
			{
				echo ini_get('error_prepend_string');

				while ($e)
				{
					self::htmlException($e);
					$e = $e->getPrevious();
				}
			}

			if (ini_get('error_log'))
			{
				error_log($log);
			}

			if (self::$email_errors)
			{
				$from = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : basename($_SERVER['DOCUMENT_ROOT']);

				$headers = [
					'Subject'	=>	'Error ref# ' . $ref,
					'From' 		=>	'"' . $from . '" <' . self::$email_errors . '>',
				];

				error_log($log, 1, self::$email_errors, implode("\r\n", $headers));
			}
		}

		if ($exit)
		{
			exit(1);
		}
	}

	static public function exceptionAsLog($e, &$ref)
	{
		$out = '';

		if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI']))
			$out .= 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']."\n\n";

		while ($e)
		{
			$out .= get_class($e) 
				. ' [Code ' . $e->getCode() . '] '
				. $e->getMessage() . "\n"
				. str_replace($_SERVER['DOCUMENT_ROOT'], '', $e->getFile())
				 . ':' . $e->getLine() . "\n\n";

			$out .= $e->getTraceAsString();
			$out .= "\n\n";

			$e = $e->getPrevious();
		}

		foreach (self::$debug_env as $key=>$value)
		{
			$out .= $key . ': ' . $value . "\n";
		}

		$out .= 'PHP version: ' . phpversion() . "\n";

		foreach ($_SERVER as $key=>$value)
		{
			if (is_array($value))
				$value = json_encode($value);

			$out .= $key . ': ' . $value . "\n";
		}

		$out = str_replace("\r", '', $out);

		$ref = base_convert(substr(sha1($out), 0, 10), 16, 36);
		$out = '----- Bug report ref #' . $ref . " -----\n" . $out;

		return $out;
	}

	static public function htmlException($e)
	{
		echo '<section>';
		echo '<header><h1>' . get_class($e) . '</h1><h2>' . $e->getMessage() . '</h2></header>';

		foreach ($e->getTrace() as $i=>$t)
		{
			$nb_args = count($t['args']);

			echo '<article><h3>' . htmlspecialchars(dirname($t['file'])) . '/<b>' . htmlspecialchars(basename($t['file'])) . '</b>:<i>' . (int) $t['line'] . '</i> &rarr; <u>' . $t['function'] . '</u></h3>';
			echo '<h4>' . htmlspecialchars($t['function']) . ' <i>(' . (int) $nb_args . ' arg.)</i></h4>';

			if ($nb_args)
			{
				echo '<table>';

				foreach ($t['args'] as $name => $value)
				{
					echo '<tr><th>' . htmlspecialchars($name) . '</th><td>' . htmlspecialchars(print_r($value, true)) . '</td>';
				}

				echo '</table>';
			}

			echo self::htmlSource($t['file'], $t['line']);
			echo '</article>';
		}

		echo '</section>';
	}

	static public function htmlSource($file, $line)
	{
		$out = '';
		$start = max(0, $line - 5);

		$file = new \SplFileObject($file);
		$file->seek($start);

		for ($i = $start + 1; $i < $start+10; $i++)
		{
			if ($file->eof())
				break;

			$code = trim($file->current(), "\r\n");
			$html = '<b>' . ($i) . '</b>' . htmlspecialchars($code, ENT_QUOTES);

			if ($i == $line)
			{
				$html = '<u>' . $html . '</u>';
			}

			$out .= $html . PHP_EOL;
			$file->next();
		}

		return '<pre><code>' . $out . '</code></pre>';
	}

	/**
	 * Enable error manager
	 * @param  integer $type Type of error management (ErrorManager::PRODUCTION or ErrorManager::DEVELOPMENT)
	 * @return void
	 */
	static public function enable($type = self::DEVELOPMENT)
	{
		if (self::$enabled)
			return true;

		self::$enabled = $type;

		self::$term_color = function_exists('posix_isatty') && @posix_isatty(STDOUT);

		ini_set('display_errors', $type == self::DEVELOPMENT);
		ini_set('log_errors', false);
		ini_set('html_errors', false);
		ini_set('error_reporting', $type == self::DEVELOPMENT ? -1 : E_ALL & ~E_DEPRECATED & ~E_STRICT);

		if ($type == self::DEVELOPMENT && PHP_SAPI != 'cli')
		{
			self::setHtmlHeader('<!DOCTYPE html><meta charset="utf-8" /><style type="text/css">
			body { font-family: sans-serif; } * { margin: 0; padding: 0; }
			u, code b, i, h3 { font-style: normal; font-weight: normal; text-decoration: none; }
			#icn { color: #fff; font-size: 2em; float: right; margin: 1em; padding: 1em; background: #900; border-radius: 50%; }
			section header { background: #fdd; padding: 1em; }
			section article { margin: 1em; }
			section article h3 { font-size: 1em; }
			code { border: 1px dotted #ccc; display: block; }
			code b { margin-right: 1em; color: #999; }
			code u { display: block; background: #fcc; }
			table { border-collapse: collapse; margin: 1em; } td, th { border: 1px solid #ccc; padding: .2em .5em; }
			</style>
			<pre id="icn"> \__/<br /> (xx)<br />//||\\\\</pre>');
		}

		// For PHP7 we don't need to throw ErrorException as all errors are thrown as Error
		// see https://secure.php.net/manual/en/language.errors.php7.php
		if (!class_exists('\Error'))
		{
			set_error_handler([__CLASS__, 'errorHandler']);
		}

		register_shutdown_function([__CLASS__, 'shutdownHandler']);

		return set_exception_handler([__CLASS__, 'exceptionHandler']);
	}

	/**
	 * Reset error management to PHP defaults
	 * @return boolean
	 */
	static public function disable()
	{
		self::$enabled = false;

		ini_set('error_prepend_string', null);
		ini_set('error_append_string', null);
		ini_set('log_errors', false);
		ini_set('display_errors', false);
		ini_set('error_reporting', E_ALL & ~E_DEPRECATED & ~E_STRICT);

		restore_error_handler();
		return restore_exception_handler();
	}

	/**
	 * Sets a log file to record errors
	 * @param string $file Error log file
	 */
	static public function setLogFile($file)
	{
		ini_set('log_errors', true);
		return ini_set('error_log', $file);
	}

	/**
	 * Sets an email address that should receive the logs
	 * Set to FALSE to disable email sending (default)
	 * @param string $email Email address
	 */
	static public function setEmail($email)
	{
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			throw new \InvalidArgumentException('Invalid email address: ' . $email);
		}

		self::$email_errors = $email;
	}

	static public function setExtraDebugEnv($env)
	{
		self::$debug_env = $env;
	}

	static public function setHtmlHeader($html)
	{
		ini_set('error_prepend_string', $html);
	}

	static public function setHtmlFooter($html)
	{
		ini_set('error_append_string', $html);
	}

	static public function setProductionErrorTemplate($html)
	{
		$this->production_error_template = $html;
	}

	static public function setCustomExceptionHandler($class, Callable $callback)
	{
		$this->custom_handlers[$class] = $callback;
	}
}