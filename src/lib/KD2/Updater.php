<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

/**
 * Updater: an application updater
 *
 * Can be used to create an archive that includes any code or binary change
 * between two versions of a server-side app. This archive will then be signed.
 *
 * Can also be used to check for a new version of the app
 */

namespace KD2;

use KD2\Delta;
use KD2\HTTP;

class Updater
{
	const MANIFEST_FLAGS = [
		'D' => 'Deleted',
		'A' => 'Added',
		'M' => 'Modified'
	];

	protected $version_file_url = null;
	protected $public_key_file = null;
	protected $https_certificate = null;

	public function __construct($version_file_url, $public_key_file, $temp_dir = null, $https_certificate = null)
	{
		if (!filter_var($version_file_url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED | FILTER_FLAG_PATH_REQUIRED))
		{
			throw new \InvalidArgumentException('Invalid version file URL: ' . $version_file_url);
		}

		if (!is_readable($public_key_file))
		{
			throw new \InvalidArgumentException('Public key file cannot be read: ' . $public_key_file);
		}

		if (null === $temp_dir)
		{
			$temp_dir = sys_get_temp_dir();
		}

		if (!is_writeable($temp_dir))
		{
			throw new \InvalidArgumentException('Temporary directory is not writeable: ' . $temp_dir);
		}

		if (null !== $https_certificate && !is_readable($https_certificate))
		{
			throw new \InvalidArgumentException('Invalid server HTTPS certificate, cannot read file: ' . $https_certificate);
		}

		$this->version_file_url = $version_file_url;
		$this->public_key_file = $public_key_file;
		$this->https_certificate = $https_certificate;
		$this->temp_dir = rtrim($temp_dir, '/\\' . DIRECTORY_SEPARATOR);
	}

	protected function fetchUpdatesList()
	{
		if (null === $this->updates_list)
		{
			$list = $this->httpRequest($this->version_file_url);
			$list = str_replace("\r", $list);
			$list = explode("\n", $list);

			// Sort from latest version to first version
			usort($list, function ($a, $b) {
				return version_compare($a, $b, '<=');
			});

			$this->updates_list = [];

			foreach ($list as $line)
			{
				// format is: [version][space][url]
				$line = preg_split('/\s+/', $line);
				$this->updates_list[$line[0]] = $line[1];
			}
		}

		reset($this->updates_list);
		return $this->updates_list;
	}

	protected function httpRequest($url)
	{
		$http = new HTTP;
		$http->http_options['ignore_errors'] = false;

		if (null !== $this->https_certificate)
		{
			$http->ssl_options['cafile'] = $this->https_certificate;
			$http->ssl_options['capath'] = '';
		}

		$http->user_agent = 'KD2_Updater/0.1';

		$response = $http->GET($url);

		return $response->body;
	}

	public function check($current_version)
	{
		$list = $this->fetchUpdatesList();
		$last_version = key($list);

		return version_compare($last_version, $current_version, '>');
	}

	public function auto($current_version)
	{
		if (!$this->check($current_version))
		{
			return false;
		}

		$list = $this->fetchUpdatesList();
		$list = array_reverse($list, true);

		foreach ($list as $version=>$url)
		{
			if (version_compare($current_version, $version, '<='))
			{
				// Ignore previous versions
				continue;
			}

			$url = HTTP::mergeURLs($this->version_file_url, $url);

			if (!filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED | FILTER_FLAG_PATH_REQUIRED))
			{
				throw new \RuntimeException('Invalid update file URL: ' . $url);
			}

			$this->apply($url);
		}

		return true;
	}

	public function pharErrorToException($errno, $errstr)
	{
		throw new \RuntimeException($errstr);
	}

	public function apply($source_url, $root_directory)
	{
		if (!filter_var($source_url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED | FILTER_FLAG_PATH_REQUIRED))
		{
			throw new \InvalidArgumentException('Invalid update source URL: ' . $source_url);
		}

		$update_file = $this->temp_dir . DIRECTORY_SEPARATOR . 'update.' . sha1($source_url) . '.phar';

		file_put_contents($update_file, $this->httpRequest($source_url));

		if ($this->public_key_file)
		{
			// Copy public key for check
			copy($this->public_key_file, $update_file . '.pubkey');
		}

		// When the public key is invalid, openssl throws a
		// 'supplied key param cannot be coerced into a public key' warning
		// and phar ignores sig verification.
		// We need to protect from that by catching the warning
		set_error_handler([$this, 'pharErrorToException']);

		try {
			$phar = new \Phar($update_file);
		}
		catch (\Exception $e)
		{
			// Will be thrown if archive is corrupted or signature doesn't match
			// remove phar archive
			@unlink($update_file);
			throw $e;
		}
		finally
		{
			restore_error_handler();

			// remove public key
			@unlink($update_file . '.pubkey');
		}

		$sig = $phar->getSignature();

		if ($this->public_key_file && strtolower($sig['hash_type']) !== 'openssl')
		{
			throw new \RuntimeException(sprintf('Phar file is not signed with OpenSSL: %s', $update_file));
		}

		// Get manifest
		$manifest = $phar['manifest'];

		// One line = one file
		$manifest = str_replace("\r", $manifest);
		$manifest = explode("\n", $manifest);

		// Check manifest
		foreach ($manifest as $i=>&$line)
		{
			// format is: [A|D|M] [path] [optional content hash]
			$line = preg_split('/\s+/', $line, 3);

			if (count($line) < 2)
			{
				throw new \RuntimeException(sprintf('Invalid manifest on line %d: missing file path', $i));
			}

			if (!array_key_exists($line[0], self::MANIFEST_FLAGS))
			{
				throw new \RuntimeException(sprintf('Invalid manifest on line %d: unknown flag "%s"', $i, $line[0]));
			}

			// Require artifacts except for deletions
			if ($line[0] !== 'D' && empty($line[2]))
			{
				throw new \RuntimeException(sprintf('Invalid manifest on line %d: missing artifact hash for "%s"', $i, $line[1]));
			}
			elseif ($line[0] === 'M')
			{
				$file = $root_directory . DIRECTORY_SEPARATOR . ltrim($line[1], '/\\');

				if (!file_exists($file))
				{
					throw new \RuntimeException(sprintf('Cannot apply delta to non-existing file: %s', $line[1]));
				}
			}
		}

		$delta = new Delta;

		// Process every line of the manifest
		foreach ($manifest as $line)
		{
			if ($line[0] == 'D')
			{
				$this->_remove($root_directory, $line[1]);
			}
			elseif ($line[0] == 'A')
			{
				$file = $root_directory . DIRECTORY_SEPARATOR . ltrim($line[1], '/\\');
				$dir = dirname($file);

				if (!file_exists($dir))
				{
					mkdir($dir, 0777, true);
				}

				file_put_contents($file, $phar['artifacts/' . $line[2]]);
			}
			elseif ($line[0] == 'M')
			{
				$file = $root_directory . DIRECTORY_SEPARATOR . ltrim($line[1], '/\\');
				$src = file_get_contents($file);
				$delta = $phar['artifacts/' . $line[2]];

				$target = $delta->apply($src, $delta);

				//$rollback = $delta->create($target, $src);

				file_put_contents($file, $target);
			}
		}

		return true;
	}

	static public function make($source_dir, $target_dir, $update_file, $private_key)
	{
		$manifest = [];

		$phar = new \Phar($update_file);
		$phar->addEmptyDir('artifacts');
		$delta = new Delta;

		$source_files = self::listFiles($source_dir);
		$target_files = self::listFiles($target_dir);

		foreach ($source_files as $hash=>$file)
		{
			if (!in_array($file, $target_files))
			{
				$manifest[] = 'D ' . $file;
			}
			elseif ($hash !== array_search($file, $target_files))
			{
				$manifest[] = 'M ' . $file . ' ' . $hash;

				$delta_file = $delta->create(file_get_contents($source_dir . $file), file_get_contents($target_dir . $file));
				$delta_hash = sha1($delta_file);
				$phar->addFromString('artifacts/' . $delta_hash, $delta_file);
			}
		}

		foreach ($target_files as $hash=>$file)
		{
			if (!in_array($file, $source_files))
			{
				$manifest[] = 'A ' . $file . ' ' . $hash;
				$phar->addFile('artifacts/' . $hash, $target_dir . $file);
			}
		}

		$phar->setSignatureAlgorithm(\Phar::OPENSSL, file_get_contents($private_key));
	}
}