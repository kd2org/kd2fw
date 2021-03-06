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

class EXIF
{
	/**
	 * File source
	 * @var null|string
	 */
	protected $source = null;

	/**
	 * File EXIF data
	 * @var array
	 */
	protected $exif = [];

	/**
	 * Reads EXIF data from $source file
	 * @param string $source Source file
	 */
	public function __construct($source)
	{
		if (!function_exists('exif_read_data'))
		{
			throw new \RuntimeException('PHP EXIF extension is not installed.');
		}

		$this->exif = exif_read_data($source, 0, true, true);
		$this->source = $source;
	}

	/**
	 * Returns all EXIF data
	 * @return array
	 */
	public function get()
	{
		return $this->exif;
	}

	/**
	 * Returns JPEG thumbnail binary content
	 * @return string
	 */
	public function getThumbnail()
	{
		if (empty($this->exif['THUMBNAIL']['THUMBNAIL']))
		{
			return false;
		}

		return $this->exif['THUMBNAIL']['THUMBNAIL'];
	}

	/**
	 * Returns JPEG thumbnails details (size, etc.)
	 * @return array
	 */
	public function getThumbnailDetails()
	{
		$exif = $this->get();

		if (empty($exif['THUMBNAIL']))
		{
			return false;
		}

		unset($exif['THUMBNAIL']['THUMBNAIL']);
		return $exif['THUMBNAIL'];
	}

	/**
	 * Save the JPEG thumbnail to a file
	 * @param  string $destination Destination file
	 * @return boolean
	 */
	public function saveThumbnail($destination)
	{
		if (empty($this->exif['THUMBNAIL']['THUMBNAIL']))
		{
			return false;
		}

		return file_put_contents($destination, $this->exif['THUMBNAIL']['THUMBNAIL']);
	}

	/**
	 * Returns a rotation angle from the EXIF orientation tag
	 * @return integer
	 */
	public function getRotation()
	{
		$exif = $this->get();

		if (!$exif)
			return false;

		if (isset($exif['Orientation']))
		{
			$exif_orientation = $exif['Orientation'];
		}

		switch ($exif_orientation)
		{
			case 3:
				return 180;
			case 6:
				return 90;
			case 8:
				return -90;
		}

		return 0;
	}

	public function getFocalLength()
	{
		return self::normalizeFocalLength($this->exif);
	}

	public function getExposureTime()
	{
		if (empty($this->exif['EXIF']['ExposureTime']))
		{
			return false;
		}

		return self::normalizeExposureTime($this->exif['EXIF']['ExposureTime']);
	}

	/**
	 * Returns a normalized focal length from EXIF data
	 * inspired by code from jhead
	 * @param  array $exif  Photo EXIF data from exif_read_data
	 * @return float focal length in mm
	 */
	static public function normalizeFocalLength($exif)
	{
		if (!empty($exif['FocalLengthIn35mmFilm']))
		{
			if (preg_match('!^(\d+)/(\d+)$!', $exif['FocalLengthIn35mmFilm'], $match)
				&& (int)$match[1] && (int)$match[2])
			{
				return round((int)$match[1] / (int)$match[2], 1);
			}
			elseif (is_numeric($exif['FocalLengthIn35mmFilm']))
			{
				return round($exif['FocalLengthIn35mmFilm'], 1);
			}
		}

		if (empty($exif['FocalLength']))
		{
			return null;
		}

		$width = $height = $res = $unit = null;

		if (!empty($exif['ExifImageWidth']))
			$width = (int)$exif['ExifImageWidth'];
		
		if (!empty($exif['ExifImageLength']))
			$height = (int)$exif['ExifImageLength'];

		if (!empty($exif['FocalPlaneXResolution']))
		{
			if (preg_match('!^(\d+)/(\d+)$!', $exif['FocalPlaneXResolution'], $match)
				&& (int)$match[1] && (int)$match[2])
			{
				$res = (int)$match[1] / (int)$match[2];
			}
			elseif (is_numeric($exif['FocalPlaneXResolution']))
			{
				$res = (float) $exif['FocalPlaneXResolution'];
			}
		}

		if (!empty($exif['FocalPlaneResolutionUnit']))
		{
			switch ((int)$exif['FocalPlaneResolutionUnit'])
			{
				case 1: $unit = 25.4; break; // inch
				case 2: $unit = 25.4; break; // supposed to be meters but actually inches
				case 3: $unit = 10;   break;  // centimeter
				case 4: $unit = 1;    break;  // millimeter
				case 5: $unit = .001; break;  // micrometer
			}
		}

		if ($width && $height && $res && $unit)
		{
			$size = max($width, $height);
			$ccd_width = (float)($size * $unit / $res);

			return round($exif['FocalLength'] / $ccd_width * 36 + 0.5, 1);
		}

		if (preg_match('!^([0-9.]+)/([0-9.]+)$!', $exif['FocalLength'], $match)
			&& (int)$match[1] && (int)$match[2])
		{
			return round($match[1] / $match[2], 1);
		}

		if (is_numeric($exif['FocalLength']))
			return round($exif['FocalLength'], 1);

		return null;
	}

	/**
	 * Returns a normalized exposure time from EXIF ExposureTime tag
	 * @param  string $time ExposureTime tag content
	 * @return string       exposure time in seconds or fraction of a second
	 */
	static public function normalizeExposureTime($time)
	{
		if ($time >= 1)
			return round($time, 2);

		if (!is_numeric($time))
			return $time;

		$fractions = array(1.3, 1.6, 2, 2.5, 3.2, 4, 5, 6, 8,
			10, 13, 15, 20, 25, 30, 40, 50, 60, 80, 100, 125, 160, 200,
			250, 320, 400, 500, 640, 800, 1000, 1300, 1600, 2000, 2500,
			3200, 4000, 8000);

		reset($fractions);
		$f = 1;

		while ($f)
		{
			$n = next($fractions);
			
			if ($time >= (1/$n) && $time <= (1/$f))
				return '1/' . $f;

			$f = $n;
		}

		return round($f, 2);
	}
}