<?php

namespace KD2;

use KD2\HTTP;
use KD2\Security;

/**
 * FossilInstaller
 *
 * This is useful to fetch and install .tar.gz (or .zip) updates from a Fossil repository
 * using the Unversioned files feature.
 *
 * This also implements PGP signature verification and can display a summary of changed to the user.
 *
 * Copyright (C) 2021 BohwaZ <https://bohwaz.net/>
 */

class FossilInstaller
{
	const DEFAULT_REGEXP = '/app-(?P<version>.*)\.tar\.gz/';

	protected array $releases;
	protected string $app_path;
	protected string $tmp_path;
	protected string $fossil_url;
	protected string $release_name_regexp;
	protected array $ignored_paths = [];
	protected string $gpg_pubkey_file;

	public function __construct(string $fossil_repo_url, string $app_path, string $tmp_path, ?string $release_name_regexp = null)
	{
		$this->fossil_url = $fossil_repo_url;
		$this->app_path = $app_path;
		$this->tmp_path = $tmp_path;
		$this->release_name_regexp = $release_name_regexp;
	}

	public function __destruct()
	{
		$this->prune();
	}

	public function setPublicKeyFile(string $file)
	{
		$this->gpg_pubkey_file = $file;
	}

	public function addIgnoredPath(string $path)
	{
		$this->ignored_paths[] = $path;
	}

	public function listReleases(): array
	{
		if (isset($this->releases)) {
			return $this->releases;
		}

		$list = (new HTTP)->GET($this->fossil_url . 'juvlist');

		if (!$list) {
			return [];
		}

		$list = json_decode($list);

		if (!$list) {
			return [];
		}

		$this->releases = [];

		foreach ($list as $item) {
			if (!isset($item->name, $item->hash, $item->size, $item->mtime)) {
				continue;
			}

			if (!preg_match($this->release_name_regexp, $item->name, $match)) {
				continue;
			}

			list(, $version) = $match;

			$item->signed = false;
			$item->stable = preg_match('/alpha|dev|rc|beta/', $version) ? false : true;
			$this->releases[$version] = $item;
		}

		// Add signed information
		foreach ($list as $item) {
			if (substr($item->name, -4) !== '.asc') {
				continue;
			}

			$name = substr($item->name, 0, -4);

			foreach ($this->releases as &$r) {
				if ($r->name == $name) {
					$r->signed = true;
				}
			}
		}

		unset($r);

		return $this->releases;
	}

	public function latest(bool $stable_only = true): ?string
	{
		$releases = $this->listReleases();

		$latest = null;

		foreach ($releases as $version => $r) {
			if ($stable_only && !$r->stable) {
				continue;
			}

			if (!$latest || version_compare($version, $latest, '>')) {
				$latest = $version;
			}
		}

		return $latest;
	}

	public function download(string $version): string
	{
		if (!isset($this->releases[$version])) {
			throw new \InvalidArgumentException('Unknown release');
		}

		$release = $this->releases[$version];

		$url = sprintf('%suv/%s', $this->fossil_url, $release->name);
		$tmpfile = $this->_getTempFilePath($version);
		$r = (new HTTP)->GET($url);

		if (!$r->fail && $r->body) {
			file_put_contents($tmpfile, $r->body);
			touch($tmpfile);
		}

		if (!file_exists($tmpfile)) {
			throw new \RuntimeException('Error while downloading file');
		}

		$can_check_hash = in_array('sha3-256', hash_algos());

		if ($can_check_hash && !hash_equals(hash_file('sha3-256', $tmpfile), $release->hash)) {
			@unlink($tmpfile);
			throw new \RuntimeException('Error while downloading file: invalid hash');
		}

		return $tmpfile;
	}

	protected function _getTempFilePath(string $version): string
	{
		return $this->tmp_path . '/tmp-release-' . sha1($version) . '.tar.gz';
	}

	public function verify(string $version): ?bool
	{
		if (!isset($this->releases[$version])) {
			throw new \InvalidArgumentException('Unknown release');
		}

		$tmpfile = $this->_getTempFilePath($version);

		if (!file_exists($tmpfile)) {
			throw new \LogicException('This release has not been downloaded yet');
		}

		$release = $this->releases[$version];

		$can_check_hash = in_array('sha3-256', hash_algos());

		if ($can_check_hash && !hash_equals(hash_file('sha3-256', $tmpfile), $release->hash)) {
			@unlink($tmpfile);
			throw new \RuntimeException('Error while downloading file: invalid hash');
		}

		if (!$release->signed) {
			return null;
		}

		if (!Security::canUseEncryption()) {
			return null;
		}

		$url = sprintf('%suv/%s.asc', $this->fossil_url, $release->name);
		$r = (new HTTP)->GET($url);

		if ($r->fail || !$r->body) {
			return null;
		}

		$key = file_get_contents($this->gpg_pubkey_file);
		$data = file_get_contents($tmpfile);

		return Security::verifyWithPublicKey($key, $data, $r->body);
	}

	/**
	 * Remove old stale downloaded files
	 * @return void
	 */
	public function prune(): void
	{
		$files = self::recursiveList($this->tmp_path, 'tmp-release-*');
		$dirs = [];

		foreach ($files as $file) {
			if (is_dir($file)) {
				$dirs[] = $file;
				continue;
			}

			if (filemtime($file) < (time() - 3600 * 24)) {
				@unlink($file);
			}
		}

		// Try to remove directories
		foreach ($dirs as $dir) {
			@rmdir($dir);
		}
	}

	public function clean(string $version): void
	{
		$path = $this->_getTempFilePath($version);
		self::recursiveDelete(dirname($path), basename($path) . '*');
	}

	static protected function recursiveDelete(string $path, string $pattern = '*') {
		$files = self::recursiveList($path, $pattern);

		$dirs = [];

		foreach ($files as $file) {
			if (is_dir($file)) {
				$dirs[] = $file;
				continue;
			}

			@unlink($file);
		}

		foreach ($dirs as $dir) {
			@rmdir($dir);
		}
	}

	public function diff(string $version): \stdClass
	{
		if (!isset($this->releases[$version])) {
			throw new \InvalidArgumentException('Unknown release');
		}

		$tmpfile = $this->_getTempFilePath($version);

		if (!file_exists($tmpfile)) {
			throw new \LogicException('This release has not been downloaded yet');
		}

		$release = $this->releases[$version];

		$phar = new \PharData($tmpfile,
			\FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_PATHNAME
			| \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS);


		// List existing files
		$existing_files = [];
		$l = strlen($this->app_path);

		foreach (self::recursiveList($this->app_path) as $path) {
			// Skip ignored files
			foreach ($this->ignored_paths as $ignored_path) {
				if (0 === strpos($path, $ignored_path)) {
					continue(2);
				}
			}

			if (is_dir($path)) {
				continue;
			}

			$file = substr($path, $l + 1);
			$existing_files[$file] = $path;
		}

		// List files
		$release_files = [];
		$update = [];

		$parent = $phar->getPathName();
		$parent_l = strlen($parent);

		foreach (new \RecursiveIteratorIterator($phar) as $path => $file) {
			if ($file->isDir()) {
				// Skip directories
				continue;
			}

			$relative_path = substr($path, $parent_l + 1);
			$release_files[$relative_path] = $path;

			if (!array_key_exists($relative_path, $existing_files)) {
				continue;
			}

			$existing_path = $existing_files[$relative_path];

			if ($file->getSize() != filesize($existing_path)
				|| sha1_file($existing_path) != sha1_file($path)) {
				$update[$relative_path] = $path;
			}
		}

		$create = array_diff_key($release_files, $existing_files);
		$delete = array_diff_key($existing_files, $release_files);

		ksort($create);
		ksort($delete);
		ksort($update);

		return (object) compact('delete', 'create', 'update');
	}

	public function upgrade(string $version): void
	{
		$diff = $this->diff($version);

		foreach ($diff->delete as $file => $path) {
			@unlink($path);
		}

		// FIXME: Clean up empty directories

		foreach ($diff->create as $file => $source) {
			copy($source, $this->app_path . DIRECTORY_SEPARATOR . $file);
		}

		foreach ($diff->update as $file => $source) {
			copy($source, $this->app_path . DIRECTORY_SEPARATOR . $file);
		}

		$this->clean($version);
	}

	public function install(string $version)
	{
		if (!isset($this->releases[$version])) {
			throw new \InvalidArgumentException('Unknown release');
		}

		$tmpfile = $this->_getTempFilePath($version);

	}

	public function autoinstall(?string $version = null): void
	{
		$version ??= $this->latest();

		if (!$version) {
			return;
		}

		$this->download($version);

		if (isset($this->gpg_pubkey_file)) {
			$this->checkSignature($version);
		}

		$this->install($version);
		$this->clean($version);
	}

	static protected function recursiveList(string $path, string $pattern = '*')
	{
		$out = [];
		$length = strlen($path);

		foreach (glob($path . DIRECTORY_SEPARATOR . $pattern, \GLOB_NOSORT) as $subpath) {
			$out[] = $subpath;

			if (is_dir($subpath)) {
				$out = array_merge($out, self::recursiveList($subpath));
			}
		}

		return $out;
	}
}
