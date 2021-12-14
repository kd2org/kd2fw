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

namespace KD2;

use LogicException;
use RuntimeException;
use Phar;
use PharData;
use FilesystemIterator;

/**
 * Very simple ZIP Archive reader
 * Uses PharData
 *
 * for specs see http://www.pkware.com/appnote
 */
class ZipReader
{
	protected ?PharData $phar;
	protected string $file;
	protected string $path;

	/**
	 * Max. allowed uncompressed size: 5 GB
	 * @var int
	 */
	protected int $max_size = 1024*1024*1024*5;

	/**
	 * Max. allowed number of files
	 * @var integer
	 */
	protected int $max_files = 50000;

	/**
	 * Max. allowed levels of subdirectories
	 */
	protected int $max_levels = 10;

	protected bool $security_check = true;

	/**
	 * Create a new ZIP file
	 *
	 * @param string $file
	 * @throws RuntimeException
	 */
	public function __construct(string $file)
	{
		if (!is_readable($file))
		{
			throw new RuntimeException('Could not open ZIP file for reading: ' . $file);
		}

		$this->file = $file;
	}

	public function setMaxUncompressedSize(int $size): void
	{
		$this->max_size = $size;
	}

	public function setMaxFiles(int $files): void
	{
		$this->max_files = $files;
	}

	public function setMaxDirectoryLevels(int $levels): void
	{
		$this->max_levels = $levels;
	}

	public function enableSecurityCheck(bool $enable): void
	{
		$this->security_check = $enable;
	}

	protected function phar(): PharData
	{
		if (!isset($this->phar)) {
			$this->phar = new PharData($this->file,
				FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_PATHNAME
				| FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
				null,
				Phar::ZIP);

			$this->path = dirname($this->phar->getPathName());

			if ($this->security_check) {
				$this->securityCheck();
			}
		}

		return $this->phar;
	}

	public function securityCheck(): void
	{
		$size = 0;
		$files = 0;
		$levels = 0;

		foreach ($this->iterate() as $path => $file) {
			$size += $file->getSize();
			$files++;
			$levels = max($levels, substr_count($path, '/'));

			if ($size > $this->max_size) {
				throw new \OutOfBoundsException(sprintf('Uncompressed size is larger than max. allowed (%d bytes).', $this->max_size));
			}

			if ($files > $this->max_files) {
				throw new \OutOfBoundsException(sprintf('The archive contains more than files than allowed (max. %d files).', $this->max_files));
			}

			if ($levels > $this->max_levels) {
				throw new \OutOfBoundsException(sprintf('The archive contains more levels of subdirectories than allowed (max. %d levels).', $this->max_levels));
			}
		}
	}

	public function __destruct()
	{
		$this->phar = null;
	}

	public function iterate(): \Generator
	{
		$phar = $this->phar();
		$parent_l = strlen($this->path);
		foreach (new \RecursiveIteratorIterator($phar) as $path => $file) {
			$relative_path = substr($path, $parent_l + 1);
			yield $relative_path => $file;
		}
	}

	public function fetch(string $path): string
	{
		return file_get_contents($this->phar()[$path]);
	}

	public function extract(string $destination_dir): int
	{
		$count = 0;

		foreach ($this->iterate() as $path => $file) {
			$dest = $destination_dir . str_replace('/', DIRECTORY_SEPARATOR, $path);
			copy($file->pathName(), $dest);
			$count++;
		}

		return $count;
	}

	public function uncompressedSize(): int
	{
		$size = 0;

		foreach ($this->iterate() as $file) {
			$size += $file->getSize();
		}

		return $size;
	}
}
