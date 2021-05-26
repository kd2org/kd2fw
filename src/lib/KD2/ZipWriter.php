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

/**
 * Very simple ZIP Archive writer
 *
 * for specs see http://www.pkware.com/appnote
 * Inspired by https://github.com/splitbrain/php-archive/blob/master/src/Zip.php
 */
class ZipWriter
{
	protected $compression = 0;
	protected $pos = 0;
	protected $handle;
	protected $directory = [];
	protected $closed = false;

	/**
	 * Create a new ZIP file
	 *
	 * @param string $file
	 * @throws RuntimeException
	 */
	public function __construct($file)
	{
		$this->handle = fopen($file, 'wb');

		if (!$this->handle)
		{
			throw new RuntimeException('Could not open ZIP file for writing: ' . $file);
		}
	}

	/**
	 * Sets compression rate (0 = no compression)
	 *
	 * @param integer $compression 0 to 9
	 * @return void
	 */
	public function setCompression($compression)
	{
		$compression = (int) $compression;
		$this->compression = max(min($compression, 9), 0);
	}

	/**
	 * Write to the current ZIP file
	 * @param string $data
	 * @return void
	 */
	protected function write($data)
	{
		// We can't use fwrite and ftell directly as ftell doesn't work on some pointers
		// (eg. php://output)
		fwrite($this->handle, $data);
		$this->pos += strlen($data);
	}

	/**
	 * Returns the content of the ZIP file
	 * 
	 * @return string
	 */
	public function get()
	{
		fseek($this->handle, 0);
		return stream_get_contents($this->handle);
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Add a file to the current Zip archive using the given $data as content
	 *
	 * @param string $file File name
	 * @param string|null $data binary content of the file to add
	 * @param string|null $source Source file to use if no data is supplied
	 * @throws LogicException
	 * @throws RuntimeException
	 */
	public function add($file, $data = null, $source = null)
	{
		if ($this->closed)
		{
			throw new LogicException('Archive has been closed, files can no longer be added');
		}

		if (null === $data && null === $source) {
			throw new LogicException('No source file or data has been supplied');
		}

		$source_handle = null;

		if ($data === null)
		{
			$csize = $size = filesize($source);
			list(, $crc) = unpack('N', hash_file('crc32b', $source, true));
			$source_handle = fopen($source, 'r');

			if ($this->compression)
			{
				// Unfortunately it's not possible to use stream_filter_append
				// to compress data on the fly, as it's not working correctly
				// with php://output, php://temp and php://memory streams
				throw new RuntimeException('Compression is not supported with external files');
			}
		}
		else
		{
			$size = strlen($data);
			$crc  = crc32($data);

			if ($this->compression)
			{
				// Compress data
				$data = gzdeflate($data, $this->compression);
			}

			$csize  = strlen($data);
		}

		$offset = $this->pos;

		// write local file header
		$this->write($this->makeRecord(false, $file, $size, $csize, $crc, null));

		// we store no encryption header

		// Store uncompressed external file
		if ($source_handle)
		{
			$this->pos += stream_copy_to_stream($source_handle, $this->handle);
			fclose($source_handle);
		}
		// Store compressed or uncompressed file
		// that was supplied
		else
		{
			// write data
			$this->write($data);
		}

		// we store no data descriptor

		// add info to central file directory
		$this->directory[] = $this->makeRecord(true, $file, $size, $csize, $crc, $offset);
	}

	/**
	 * Add the closing footer to the archive
	 * @throws LogicException
	 */
	public function finalize()
	{
		if ($this->closed)
		{
			throw new LogicException('The ZIP archive has been closed. Files can no longer be added.');
		}

		// write central directory
		$offset = $this->pos;
		$directory = implode('', $this->directory);
		$this->write($directory);

		$end_record = "\x50\x4b\x05\x06" // end of central dir signature
			. "\x00\x00" // number of this disk
			. "\x00\x00" // number of the disk with the start of the central directory
			. pack('v', count($this->directory)) // total number of entries in the central directory on this disk
			. pack('v', count($this->directory)) // total number of entries in the central directory
			. pack('V', strlen($directory)) // size of the central directory
			. pack('V', $offset) // offset of start of central directory with respect to the starting disk number
			. "\x00\x00"; // .ZIP file comment length
		$this->write($end_record);

		$this->directory = [];
		$this->closed = true;
	}

	/**
	 * Close the file handle
	 * @return void
	 */
	public function close()
	{
		if (!$this->closed)
		{
			$this->finalize();
		}

		if ($this->handle)
		{
			fclose($this->handle);
		}

		$this->handle = null;
	}

	/**
	 * Creates a record, local or central
	 * @param  boolean $central  TRUE for a central file record, FALSE for a local file header
	 * @param  string  $filename File name
	 * @param  integer $size     File size
	 * @param  integer $compressed_size
	 * @param  string  $crc      CRC32 of the file contents
	 * @param  integer|null  $offset
	 * @return string
	 */
	protected function makeRecord($central = false, $filename, $size, $compressed_size, $crc, $offset)
	{
		$header = ($central ? "\x50\x4b\x01\x02\x0e\x00" : "\x50\x4b\x03\x04");

		list($filename, $extra) = $this->encodeFilename($filename);

		$header .=
			"\x14\x00" // version needed to extract - 2.0
			. "\x00\x08" // general purpose flag - bit 11 set = enable UTF-8 support
			. ($this->compression ? "\x08\x00" : "\x00\x00") // compression method - none
			. "\x01\x80\xe7\x4c" //  last mod file time and date
			. pack('V', $crc) // crc-32
			. pack('V', $compressed_size) // compressed size
			. pack('V', $size) // uncompressed size
			. pack('v', strlen($filename)) // file name length
			. pack('v', strlen($extra)); // extra field length

		if ($central)
		{
			$header .=
				"\x00\x00" // file comment length
				. "\x00\x00" // disk number start
				. "\x00\x00" // internal file attributes
				. "\x00\x00\x00\x00" // external file attributes  @todo was 0x32!?
				. pack('V', $offset); // relative offset of local header
		}

		$header .= $filename;
		$header .= $extra;

		return $header;
	}

	protected function encodeFilename($original)
	{
		if (utf8_decode($original) === $original) {
			return [$original, ''];
		}

		$data = "\x01" // version
			. pack('V', crc32($original))
			. $original;

		return [
			$original,
			"\x70\x75" // tag
			. pack('v', strlen($data)) // length of data
			. $data
		];
	}
}
