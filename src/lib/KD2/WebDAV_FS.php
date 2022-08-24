<?php

namespace KD2;

/**
 * This is mostly an example of an implementation of WebDAV over the local filesystem.
 *
 * Demo:
 *
 * $fs = new WebDAV_FS('/home/user/files');
 * $fs->route('/files/');
 */
class WebDAV_FS extends WebDAV
{
	protected string $path;
	const LOCK = false;

	protected function lock(string $uri): void {}
	protected function unlock(string $uri): void {}

	protected function log(string $message, ...$params)
	{
		error_log(vsprintf($message, $params));
	}

	public function __construct(string $path, ?string $lockdb = null)
	{
		$this->path = rtrim($path, '/') . '/';
		$lockdb_init = $lockdb && !file_exists($lockdb);
		$this->lockdb = $lockdb ? new \SQLite3($lockdb) : null;

		if ($lockdb_init) {
			$this->lockdb->exec('CREATE TABLE locks (file TEXT PRIMARY KEY);');
		}
	}

	protected function get(string $uri): ?array
	{
		if (!file_exists($this->path . $uri)) {
			return null;
		}

		return ['path' => $this->path . $uri];
	}

	protected function metadata(string $uri, bool $all = false): ?array
	{
		$target = $this->path . $uri;

		if (!file_exists($target)) {
			return null;
		}

		$meta = [
			'modified' => filemtime($target),
			'size'     => filesize($target),
			'type'     => mime_content_type($target),
			'is_dir'   => is_dir($target),
		];

		if ($all) {
			$meta['created'] = filectime($target);
			$meta['accessed'] = fileatime($target);
			$meta['hidden'] = basename($target)[0] == '.';
		}

		return $meta;
	}

	protected function put(string $uri, $pointer): bool
	{
		$target = $this->path . $uri;
		$parent = dirname($target);

		if (is_dir($target)) {
			throw new WebDAV_Exception('Target is a directory', 409);
		}

		if (!file_exists($parent)) {
			mkdir($parent, 0770, true);
		}

		$new = !file_exists($target);

		$out = fopen($target, 'w');
		stream_copy_to_stream($pointer, $out);
		fclose($out);
		fclose($pointer);

		return $new;
	}

	protected function delete(string $uri): void
	{
		$target = $this->path . $uri;

		if (!file_exists($target)) {
			throw new WebDAV_Exception('Target does not exist', 404);
		}

		if (is_dir($target)) {
			foreach (glob($target . '/*') as $file) {
				$this->delete(substr($file, strlen($this->path)));
			}

			rmdir($target);
		}
		else {
			unlink($target);
		}
	}

	protected function copymove(bool $move, string $uri, string $destination): bool
	{
		$source = $this->path . $uri;
		$target = $this->path . $destination;
		$parent = dirname($target);

		if (!file_exists($source)) {
			throw new WebDAV_Exception('File not found', 404);
		}

		$overwritten = file_exists($target);

        if (!is_dir(dirname($target))) {
            throw new WebDAV_Exception('Target parent directory does not exist', 409);
        }

		if ($overwritten) {
			$this->delete($target);
		}

		$method = $move ? 'rename' : 'copy';

		if ($method == 'copy' && is_dir($source)) {
			@mkdir($target, 0770, true);

			foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source), \RecursiveIteratorIterator::SELF_FIRST) as $item)
			{
				if ($item->isDir()) {
					@mkdir($target . DIRECTORY_SEPARATOR . $iterator->getSubPathname());
				} else {
					copy($item, $target . DIRECTORY_SEPARATOR . $iterator->getSubPathname());
				}
			}
		}
		else {
			$method($source, $target);
		}

		return $overwritten;
	}

	protected function copy(string $uri, string $destination): bool
	{
		return $this->copymove(false, $uri, $destination);
	}

	protected function move(string $uri, string $destination): bool
	{
		return $this->copymove(true, $uri, $destination);
	}

	protected function mkdir(string $uri): void
	{
		$target = $this->path . $uri;
		$parent = dirname($target);

		if (file_exists($target)) {
			throw new WebDAV_Exception('There is already a file with that name', 405);
		}

		if (!file_exists($parent)) {
			throw new WebDAV_Exception('The parent directory does not exist', 409);
		}

		mkdir($target, 0770);
	}
}
