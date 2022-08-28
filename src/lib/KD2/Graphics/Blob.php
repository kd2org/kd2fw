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

namespace KD2\Graphics;

class Blob
{
	/**
	 * Returns image size and type from binary blob
	 * (only works with JPEG, PNG and GIF)
	 * @param  string $data Binary data from file (24 bytes minimum for PNG, 10 bytes for GIF and about 250 KB for JPEG)
	 * @return mixed		Array(Width, Height, Mime-Type) or FALSE if unknown file type and size
	 */
	static public function getSize($data)
	{
		$types = ['JPEG', 'PNG', 'GIF'];

		// Try every format until it works
		foreach ($types as $type)
		{
			$func = 'getSize' . $type;
			$size = call_user_func([self::class, $func], $data);

			if ($size)
			{
				return array_merge($size, ['image/' . strtolower($type)]);
			}
		}

		return false;
	}

	static public function getType($data)
	{
		if (substr($data, 0, 3) == "\xff\xd8\xff") {
			return 'image/jpeg';
		}
		if (substr($data, 0, 4) == 'RIFF'
			&& is_int(unpack('V', substr($data, 4, 4))[1])
			&& substr($data, 8, 4) == 'WEBP') {
			return ['image/webp'];
		}
		elseif ($info = self::getSize($data)) {
			return $info[2];
		}

		return null;
	}

	/**
	 * In case we need to read directly from the file
	 * @return string Binary blob
	 */
	static public function getFileHeader($source, $format = null)
	{
		if ($format == 'jpeg' || !$format)
		{
			// How many bytes should we read from the file?
			// In JPEG, the canvas size is not always at the beginning so we need to leave some slack
			// if this data is not in the first 256 KB it probably means something wrong
			// but this could fail il case there is a lot of other data before the canvas size
			$bytes = 1024*256;
		}
		else if ($format == 'png')
		{
			// PNG requires 24 bytes
			$bytes = 24;
		}
		else if ($format == 'gif')
		{
			$bytes = 12;
		}

		return file_get_contents($source, false, null, 0, $bytes);
	}

	/**
	 * Returns JPEG image size directly from a binary string
	 * @link https://web.archive.org/web/20130921073544/http://www.64lines.com:80/
	 * @link https://github.com/threatstack/libmagic/blob/master/magic/Magdir/jpeg
	 * @param  string $data JPEG Binary string
	 * @return mixed        array(Width, Height) or FALSE if not a JPEG or no size information found
	 */
	static public function getSizeJPEG($data)
	{
		if (substr($data, 0, 3) != "\xff\xd8\xff")
		{
			return false;
		}

		$i = 4;
		$size = strlen($data);

		// Get segment length
		$info = unpack('nlength', substr($data, $i, 2));
		$segment_length = $info['length'];

		while ($i < $size)
		{
			$i += $segment_length;

			// End of file, or invalid JPEG segment
			if ($i >= $size || $data[$i] != "\xFF")
			{
				return false;
			}

			// Stop when we meet a SOIn marker, supporting most common encodings
			// Baseline || Extended Sequential || Progressive
			if ($data[$i + 1] == "\xC0" || $data[$i + 1] == "\xC1" || $data[$i + 1] == "\xC2")
			{
				$info = unpack('nY/nX', substr($data, $i + 5, 4));
				return [$info['X'], $info['Y']];
			}
			// Skip to next segment
			else
			{
				$i += 2;
				$info = unpack('nlength', substr($data, $i, 2));
				$segment_length = $info['length'];
			}
		}

		return false;
	}

	/**
	 * Extracts PNG image size directly from binary blob (24 bytes minimum)
	 * Source: https://www.w3.org/TR/PNG/
	 * and https://mtekk.us/archives/guides/check-image-dimensions-without-getimagesize/
	 * @param  string $data Binary PNG blob
	 * @return mixed        Array [Width, Height] or FALSE if not a PNG file
	 */
	static public function getSizePNG($data)
	{
		if (strlen($data) < 24)
		{
			return false;
		}

		// Check if the file is really a PNG
		if (substr($data, 0, 8) !== "\x89PNG\x0d\x0a\x1a\x0a")
		{
			return false;
		}

		// Check if first block is IHDR
		if (substr($data, 12, 4) !== 'IHDR')
		{
			return false;
		}

		$xy = unpack('NX/NY', substr($data, 16, 8));
		return array_values($xy);
	}

	/**
	 * Extracts GIF image size directly from binary blob
	 * Source: http://giflib.sourceforge.net/whatsinagif/bits_and_bytes.html
	 * @param  string $data Binary GIF blob (10 bytes minimum)
	 * @return mixed        Arry [Width, Height] or FALSE if not a GIF file
	 */
	static public function getSizeGIF($data)
	{
		if (strlen($data) < 10)
		{
			return false;
		}

		$header = substr($data, 0, 6);

		if ($header !== 'GIF87a' && $header !== 'GIF89a')
		{
			return false;
		}

		$xy = unpack('vX/vY', substr($data, 6, 4));
		return array_values($xy);
	}

	/**
	 * Returns orientation of a JPEG file according to its EXIF tag
	 * @link  http://magnushoff.com/jpeg-orientation.html See to interpret the orientation value
	 * @param  string $data File contents
	 * @return integer|boolean An integer between 1 and 8 or false if no orientation tag have been found
	 */
	static public function getOrientationJPEG($data)
	{
		$offset = 2;
		$length = strlen($data);
		$sign = 'n';

		if (substr($data, 0, 2) != "\xff\xd8")
		{
			return false;
		}

		while ($offset < $length)
		{
			$marker = substr($data, $offset, 2);
			$info = unpack('nlength', substr($data, $offset + 2, 2));
			$section_length = $info['length'];
			$offset += 4;

			if ($marker == "\xff\xe1")
			{
				if (substr($data, $offset, 6) != "Exif\x00\x00")
				{
					return false;
				}

				$offset += 6;

				if (substr($data, $offset, 2) == "\x49\x49")
				{
					$sign = 'v';
				}

				$info =  unpack(strtoupper($sign) . 'offset', substr($data, $offset + 4, 4));
				$offset += $info['offset'];

				$info = unpack($sign . 'tags', substr($data, $offset, 2));
				$tags = $info['tags'];

				$offset += 2;

				for ($i = 0; $i < $tags; $i++)
				{
					$info = unpack(sprintf('%stag', $sign), substr($data, $offset + ($i * 12), 2));

					if ($info['tag'] == 0x0112)
					{
						$info = unpack(sprintf('%sorientation', $sign), substr($data, $offset + ($i * 12) + 8, 2));
						return $info['orientation'];
					}
				}
			}
			else if (is_numeric($marker) && ($marker & 0xFF00) && $marker != "\xFF\x00")
			{
				break;
			}
			else
			{
				$offset += $section_length - 2;
			}
		}

		return false;
	}
}