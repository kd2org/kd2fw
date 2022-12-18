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

/*
	Generic image resize library
	Copyleft (C) 2005-17 BohwaZ <http://bohwaz.net/>
*/

use KD2\Graphics\Blob;

class Image
{
	static private $init = false;

	protected $libraries = [];

	protected $path = null;
	protected $blob = null;

	protected $width = null;
	protected $height = null;
	protected $type = null;
	protected $format = null;

	protected $pointer = null;
	protected $library = null;

	public $use_gd_fast_resize_trick = true;

	/**
	 * JPEG quality, from 1 to 100
	 * @var integer
	 */
	public $jpeg_quality = 90;

	/**
	 * Progressive JPEG output?
	 * Only supported by GD and Imagick!
	 * You can also use the command line tool jpegtran (package libjpeg-progs)
	 * to losslessly convert to and from progressive.
	 * @var boolean
	 */
	public $progressive_jpeg = true;

	/**
	 * LZW compression index, used by TIFF and PNG, from 0 to 9
	 * @var integer
	 */
	public $compression = 9;

	public function __construct($path = null, $library = null)
	{
		$this->libraries = [
			'epeg'    => function_exists('\epeg_open'),
			'imagick' => class_exists('\Imagick', false),
			'gd'      => function_exists('\imagecreatefromjpeg'),
		];

		if (!self::$init)
		{
			if (empty($path))
			{
				throw new \InvalidArgumentException('Empty source file argument passed');
			}

			if (!is_readable($path))
			{
				throw new \InvalidArgumentException(sprintf('Can\'t read source file: %s', $path));
			}
		}

		if ($library && !self::$init)
		{
			if (!isset($this->libraries[$library]))
			{
				throw new \InvalidArgumentException(sprintf('Library \'%s\' is not supported.', $library));
			}

			if (!$this->libraries[$library])
			{
				throw new \RuntimeException(sprintf('Library \'%s\' is not installed and can not be used.', $library));
			}
		}

		if (!self::$init)
		{
			$this->path = $path;

			$info = getimagesize($path);

			if (!$info && function_exists('mime_content_type'))
			{
				$info = ['mime' => mime_content_type($path)];
			}

			if (!$info)
			{
				throw new \RuntimeException(sprintf('Invalid image format: %s', $path));
			}

			$this->init($info, $library);
		}
	}

	static public function getBytesFromINI($size_str)
	{
		if ($size_str == -1)
		{
			return null;
		}

		$unit = strtoupper(substr($size_str, -1));

		switch ($unit)
		{
			case 'G': return (int) $size_str * pow(1024, 3);
			case 'M': return (int) $size_str * pow(1024, 2);
			case 'K': return (int) $size_str * 1024;
			default:  return (int) $size_str;
		}
	}

	static public function getMaxUploadSize($max_user_size = null)
	{
		$sizes = [
			ini_get('upload_max_filesize'),
			ini_get('post_max_size'),
			ini_get('memory_limit'),
			$max_user_size,
		];

		// Convert to bytes
		$sizes = array_map([self::class, 'getBytesFromINI'], $sizes);

		// Remove sizes that are null or -1 (unlimited)
		$sizes = array_filter($sizes, function ($size) {
			return !is_null($size);
		});

		// Return maximum file size allowed
		return min($sizes);
	}

	protected function init(array $info, $library = null)
	{
		if (isset($info[0]))
		{
			$this->width = $info[0];
			$this->height = $info[1];
		}

		$this->type = $info['mime'];
		$this->format = $this->getFormatFromType($this->type);

		if (!$this->format)
		{
			throw new \RuntimeException('Not an image format: ' . $this->type);
		}

		if ($library)
		{
			$supported_formats = call_user_func([$this, $library . '_formats']);

			if (!in_array($this->format, $supported_formats))
			{
				throw new \RuntimeException(sprintf('Library \'%s\' doesn\'t support files of type \'%s\'.', $library, $this->type));
			}
		}
		else
		{
			foreach ($this->libraries as $name => $enabled)
			{
				if (!$enabled)
				{
					continue;
				}

				$supported_formats = call_user_func([$this, $name . '_formats']);

				if (in_array($this->format, $supported_formats))
				{
					$library = $name;
					break;
				}
			}

			if (!$library)
			{
				throw new \RuntimeException('No suitable image library found for type: ' . $this->type);
			}
		}

		$this->library = $library;

		if (!$this->width && !$this->height)
		{
			$this->open();
		}
	}

	public function __get($key)
	{
		if (!property_exists($this, $key))
		{
			throw new \RuntimeException('Unknown property: ' . $key);
		}

		return $this->$key;
	}

	public function __set($key, $value)
	{
		$this->key = $value;
	}

	static public function createFromBlob($blob, $library = null)
	{
		// Trick to allow empty source in constructor
		self::$init = true;
		$obj = new Image(null, $library);

		$info = getimagesizefromstring($blob);

		// Find MIME type
		if (!$info && function_exists('finfo_open'))
		{
			$f = finfo_open(FILEINFO_MIME);
			$info = ['mime' => strstr(finfo_buffer($f, $blob), ';', true)];
			finfo_close($f);
		}

		if (!$info)
		{
			throw new \RuntimeException('Invalid image format, couldn\'t be read: from string');
		}

		$obj->blob = $blob;
		$obj->init($info, $library);

		self::$init = false;

		return $obj;
	}

	/**
	 * Open an image file
	 */
	public function open()
	{
		if ($this->pointer !== null)
		{
			return true;
		}

		if ($this->path)
		{
			call_user_func([$this, $this->library . '_open']);
		}
		else
		{
			call_user_func([$this, $this->library . '_blob'], $this->blob);
			$this->blob = null;
		}

		if (!$this->pointer)
		{
			throw new \RuntimeException('Invalid image format, couldn\'t be read: ' . $this->path);
		}

		call_user_func([$this, $this->library . '_size']);

		return $this;
	}

	public function __destruct()
	{
		$this->blob = null;
		$this->path = null;

		if ($this->pointer)
		{
			call_user_func([$this, $this->library . '_close']);
		}
	}

	/**
	 * Returns image width and height
	 * @return array            array(ImageWidth, ImageHeight)
	 */
	public function getSize()
	{
		return [$this->width, $this->height];
	}

	/**
	 * Crop the current image to this dimensions
	 * @param  integer $new_width  Width of the desired image
	 * @param  integer $new_height Height of the desired image
	 * @return Image
	 */
	public function crop($new_width = null, $new_height = null)
	{
		$this->open();

		if (!$new_width)
		{
			$new_width = $new_height = min($this->width, $this->height);
		}

		if (!$new_height)
		{
			$new_height = $new_width;
		}

		$method = $this->library . '_crop';

		if (!method_exists($this, $method))
		{
			throw new \RuntimeException('Crop is not supported by the current library: ' . $this->library);
		}

		$this->$method((int) $new_width, (int) $new_height);
		call_user_func([$this, $this->library . '_size']);

		return $this;
	}

	public function resize($new_width, $new_height = null, $ignore_aspect_ratio = false)
	{
		$this->open();

		if (!$new_height)
		{
			$new_height = $new_width;
		}

		if ($this->width <= $new_width && $this->height <= $new_height)
		{
			// Nothing to do
			return $this;
		}

		$new_height = (int) $new_height;
		$new_width = (int) $new_width;

		call_user_func([$this, $this->library . '_resize'], $new_width, $new_height, $ignore_aspect_ratio);
		call_user_func([$this, $this->library . '_size']);

		return $this;
	}

	public function rotate($angle)
	{
		$this->open();

		if (!$angle)
		{
			return $this;
		}

		$method = $this->library . '_rotate';

		if (!method_exists($this, $method))
		{
			throw new \RuntimeException('Rotate is not supported by the current library: ' . $this->library);
		}

		call_user_func([$this, $method], $angle);
		call_user_func([$this, $this->library . '_size']);

		return $this;
	}

	public function autoRotate()
	{
		$orientation = $this->getOrientation();

		if (!$orientation)
		{
			return $this;
		}

		if (in_array($orientation, [2, 4, 5, 7]))
		{
			$this->flip();
		}

		switch ($orientation)
		{
			case 3:
			case 4:
				return $this->rotate(180);
			case 5:
			case 8:
				return $this->rotate(270);
			case 7:
			case 6:
				return $this->rotate(90);
		}

		return $this;
	}

	public function flip()
	{
		$this->open();
		$method = $this->library . '_flip';

		if (!method_exists($this, $method))
		{
			throw new \RuntimeException('Flip is not supported by the current library: ' . $this->library);
		}

		call_user_func([$this, $method]);

		return $this;
	}

	public function cropResize($new_width, $new_height = null)
	{
		$this->open();

		if (!$new_height)
		{
			$new_height = $new_width;
		}

		$source_aspect_ratio = $this->width / $this->height;
		$desired_aspect_ratio = $new_width / $new_height;

		if ($source_aspect_ratio > $desired_aspect_ratio)
		{
			$temp_height = $new_height;
			$temp_width = (int) ($new_height * $source_aspect_ratio);
		}
		else
		{
			$temp_width = $new_width;
			$temp_height = (int) ($new_width / $source_aspect_ratio);
		}

		return $this->resize($temp_width, $temp_height)->crop($new_width, $new_height);
	}

	public function save($destination, $format = null)
	{
		$this->open();

		if (is_null($format))
		{
			$format = $this->format;
		}

		if (!in_array($format, call_user_func([$this, $this->library . '_formats'])))
		{
			throw new \InvalidArgumentException('The specified format ' . $format . ' can not be used by ' . $this->library);
		}

		return call_user_func([$this, $this->library . '_save'], $destination, $format);
	}

	public function output($format = null, $return = false)
	{
		$this->open();

		if (is_null($format))
		{
			$format = $this->format;
		}

		if (!in_array($format, call_user_func([$this, $this->library . '_formats'])))
		{
			throw new \InvalidArgumentException('The specified format ' . $format . ' can not be used by ' . $this->library);
		}

		return call_user_func([$this, $this->library . '_output'], $format, $return);
	}

	public function format()
	{
		return $this->format;
	}

	protected function getCropGeometry($w, $h, $new_width, $new_height)
	{
		$proportion_src = $w / $h;
		$proportion_dst = $new_width / $new_height;

		$x = $y = 0;
		$out_w = $new_width;
		$out_h = $new_height;

		if ($proportion_src > $proportion_dst)
		{
			$out_w = $out_h * $proportion_dst;
			$x = round(($w - $out_w) / 2);
		}
		else
		{
			$out_h = $out_h / $proportion_dst;
			$y = round(($h - $out_h) / 2);
		}

		return [$x, $y, round($out_w), round($out_h)];
	}

	/**
	 * Returns the format name from the MIME type
	 * @param  string $type MIME type
	 * @return Format: jpeg, gif, svg, etc.
	 */
	public function getFormatFromType($type)
	{
		switch ($type)
		{
			// Special cases
			case 'image/svg+xml':	return 'svg';
			case 'application/pdf':	return 'pdf';
			case 'image/vnd.adobe.photoshop': return 'psd';
			case 'image/x-icon': return 'bmp';
			default:
				if (preg_match('!^image/([\w\d]+)$!', $type, $match))
				{
					return $match[1];
				}

				return false;
		}
	}

	static public function getLibrariesForFormat($format)
	{
		self::$init = true;
		$im = new Image;
		self::$init = false;

		$libraries = [];

		foreach ($im->libraries as $name => $enabled)
		{
			if (!$enabled)
			{
				continue;
			}

			if (in_array($format, call_user_func([$im, $name . '_formats'])))
			{
				$libraries[] = $name;
			}
		}

		return $libraries;
	}

	/**
	 * Returns orientation of a JPEG file according to its EXIF tag
	 * @link  http://magnushoff.com/jpeg-orientation.html See to interpret the orientation value
	 * @return integer|boolean An integer between 1 and 8 or false if no orientation tag have been found
	 */
	public function getOrientation()
	{
		if ($this->format != 'jpeg') {
			return false;
		}

		if (null !== $this->blob) {
			return Blob::getOrientationJPEG($this->blob);
		}

		$file = fopen($this->path, 'rb');
		rewind($file);

		// Get length of file
		fseek($file, 0, SEEK_END);
		$length = ftell($file);
		rewind($file);

		$sign = 'n';

		if (fread($file, 2) != "\xff\xd8")
		{
			return false;
		}

		while (!feof($file))
		{
			$marker = fread($file, 2);
			$info = unpack('nlength', fread($file, 2));
			$section_length = $info['length'];

			if ($marker == "\xff\xe1")
			{
				if (fread($file, 6) != "Exif\x00\x00")
				{
					return false;
				}

				if (fread($file, 2) == "\x49\x49")
				{
					$sign = 'v';
				}

				fseek($file, 2, SEEK_CUR);

				$info = unpack(strtoupper($sign) . 'offset', fread($file, 4));
				fseek($file, $info['offset'] - 8, SEEK_CUR);

				$info = unpack($sign . 'tags', fread($file, 2));
				$tags = $info['tags'];

				for ($i = 0; $i < $tags; $i++)
				{
					$info = unpack(sprintf('%stag', $sign), fread($file, 2));

					if ($info['tag'] == 0x0112)
					{
						fseek($file, 6, SEEK_CUR);
						$info = unpack(sprintf('%sorientation', $sign), fread($file, 2));
						return $info['orientation'];
					}
					else
					{
						fseek($file, 10, SEEK_CUR);
					}
				}
			}
			else if (is_numeric($marker) && $marker & 0xFF00 && $marker != "\xFF\x00")
			{
				break;
			}
			else
			{
				fseek($file, $section_length - 2, SEEK_CUR);
			}
		}

		return false;
	}

	// EPEG methods //////////////////////////////////////////////////////////
	protected function epeg_open()
	{
		$this->pointer = new \Epeg($this->path);
		$this->format = 'jpeg';
	}

	protected function epeg_formats()
	{
		return ['jpeg'];
	}

	protected function epeg_blob($data)
	{
		$this->pointer = \Epeg::openBuffer($data);
	}

	protected function epeg_size()
	{
		// Do nothing as it only returns the original size of the JPEG
		// not the resized size
		/*
		$size = $this->pointer->getSize();
		$this->width = $size[0];
		$this->height = $size[1];
		*/
	}

	protected function epeg_close()
	{
		$this->pointer = null;
	}

	protected function epeg_save($destination, $format)
	{
		$this->pointer->setQuality($this->jpeg_quality);
		return $this->pointer->encode($destination);
	}

	protected function epeg_output($format, $return)
	{
		$this->pointer->setQuality($this->jpeg_quality);

		if ($return)
		{
			return $this->pointer->encode();
		}

		echo $this->pointer->encode();
		return true;
	}

	protected function epeg_crop($new_width, $new_height)
	{
		if (!method_exists($this->pointer, 'setDecodeBounds'))
		{
			throw new \RuntimeException('Crop is not supported by EPEG');
		}

		$x = floor(($this->width - $new_width) / 2);
		$y = floor(($this->height - $new_height) / 2);

		$this->pointer->setDecodeBounds($x, $y, $new_width, $new_height);
	}

	protected function epeg_resize($new_width, $new_height, $ignore_aspect_ratio)
	{
		if (!$ignore_aspect_ratio)
		{
			$in_ratio = $this->width / $this->height;

			$out_ratio = $new_width / $new_height;

			if ($in_ratio >= $out_ratio)
			{
				$new_height = $new_width / $in_ratio;
			}
			else
			{
				$new_width = $new_height * $in_ratio;
			}
		}

		$this->width = $new_width;
		$this->height = $new_height;

		$this->pointer->setDecodeSize($new_width, $new_height, true);
	}

	// Imagick methods ////////////////////////////////////////////////////////
	protected function imagick_open()
	{
		try {
			$this->pointer = new \Imagick($this->path);
		}
		catch (\ImagickException $e)
		{
			throw new \RuntimeException('Unable to open file: ' . $this->path, false, $e);
		}

		$this->format = strtolower($this->pointer->getImageFormat());
	}

	protected function imagick_formats()
	{
		return array_map('strtolower', (new \Imagick)->queryFormats());
	}

	protected function imagick_blob($data)
	{
		try {
			$this->pointer = new \Imagick;
			$this->pointer->readImageBlob($data);
		}
		catch (\ImagickException $e)
		{
			throw new \RuntimeException('Unable to open data string of length ' . strlen($data), false, $e);
		}

		$this->format = strtolower($this->pointer->getImageFormat());
	}

	protected function imagick_size()
	{
		$this->width = $this->pointer->getImageWidth();
		$this->height = $this->pointer->getImageHeight();
	}

	protected function imagick_close()
	{
		$this->pointer->destroy();
	}

	protected function imagick_save($destination, $format)
	{
		$this->pointer->setImageFormat($format);

		if ($format == 'png')
		{
			$this->pointer->setOption('png:compression-level', 9);
			$this->pointer->setImageCompression(\Imagick::COMPRESSION_LZW);
			$this->pointer->setImageCompressionQuality($this->compression * 10);
		}
		else if ($format == 'jpeg')
		{
			$this->pointer->setImageCompression(\Imagick::COMPRESSION_JPEG);
			$this->pointer->setImageCompressionQuality($this->jpeg_quality);
			$this->pointer->setInterlaceScheme($this->progressive_jpeg ? \Imagick::INTERLACE_PLANE : \Imagick::INTERLACE_NO);
		}
		else if ($format == 'webp') {
			$this->pointer->setImageCompressionQuality($this->jpeg_quality);
		}

		$this->pointer->stripImage();

		if ($format == 'gif' && $this->pointer->getNumberImages() > 1) {
			// writeImages is buggy in old versions of Imagick
			return file_put_contents($destination, $this->pointer->getImagesBlob());
		}
		else {
			return $this->pointer->writeImage($destination);
		}
	}

	protected function imagick_output($format, $return)
	{
		$this->pointer->setImageFormat($format);

		if ($format == 'png')
		{
			$this->pointer->setOption('png:compression-level', 9);
			$this->pointer->setImageCompression(\Imagick::COMPRESSION_LZW);
			$this->pointer->setImageCompressionQuality($this->compression * 10);
			$this->pointer->stripImage();
		}
		else if ($format == 'jpeg')
		{
			$this->pointer->setImageCompression(\Imagick::COMPRESSION_JPEG);
			$this->pointer->setImageCompressionQuality($this->jpeg_quality);
			$this->pointer->setInterlaceScheme($this->progressive_jpeg ? \Imagick::INTERLACE_PLANE : \Imagick::INTERLACE_NO);
		}

		if ($format == 'gif' && $this->pointer->getNumberImages() > 1) {
			$res = $this->pointer->getImagesBlob();
		}
		else {
			$res = (string) $this->pointer;
		}

		if ($return) {
			return $res;
		}

		echo $res;
		return true;
	}

	protected function imagick_crop($new_width, $new_height)
	{
		$src_x = floor(($this->width - $new_width) / 2);
		$src_y = floor(($this->height - $new_height) / 2);

		// Detect animated GIF
		if ($this->format == 'gif')
		{
			$this->pointer = $this->pointer->coalesceImages();

			do {
				$this->pointer->cropImage($new_width, $new_height, $src_x, $src_y);
				$this->pointer->setImagePage($new_width, $new_height, 0, 0);
			} while ($this->pointer->nextImage());

			$this->pointer = $this->pointer->deconstructImages();
		}
		else
		{
			$this->pointer->cropImage($new_width, $new_height, $src_x, $src_y);
			$this->pointer->setImagePage($new_width, $new_height, 0, 0);
		}
	}

	protected function imagick_resize($new_width, $new_height, $ignore_aspect_ratio = false)
	{
		// Detect animated GIF
		if ($this->format == 'gif' && $this->pointer->getNumberImages() > 1)
		{
			$image = $this->pointer->coalesceImages();

			foreach ($image as $frame)
			{
				$frame->thumbnailImage($new_width, $new_height, !$ignore_aspect_ratio);
				$frame->setImagePage($new_width, $new_height, 0, 0);
			}

			$this->pointer = $image->deconstructImages();
		}
		else
		{
			$this->pointer->resizeImage($new_width, $new_height, \Imagick::FILTER_CATROM, 1, !$ignore_aspect_ratio, false);
		}
	}

	protected function imagick_rotate($angle)
	{
		$pixel = new \ImagickPixel('#00000000');

		if ($this->format == 'gif' && $this->pointer->getNumberImages() > 1) {
			$image = $this->pointer->coalesceImages();

			foreach ($image as $frame) {
				$frame->rotateImage($pixel, $angle);
				$frame->setImageOrientation(\Imagick::ORIENTATION_UNDEFINED);
			}

			$this->pointer = $image->deconstructImages();
		}
		else {
			$this->pointer->rotateImage($pixel, $angle);
			$this->pointer->setImageOrientation(\Imagick::ORIENTATION_UNDEFINED);
		}
	}

	protected function imagick_flip()
	{
		if ($this->format == 'gif' && $this->pointer->getNumberImages() > 1) {
			$image = $this->pointer->coalesceImages();

			foreach ($image as $frame) {
				$frame->flopImage();
			}

			$this->pointer = $image->deconstructImages();
		}
		else {
			$this->pointer->flopImage();
		}
	}

	// GD methods /////////////////////////////////////////////////////////////
	protected function gd_open()
	{
		$this->pointer = call_user_func('imagecreatefrom' . $this->format, $this->path);

		if ($this->format == 'png' || $this->format == 'gif') {
			imagealphablending($this->pointer, false);
			imagesavealpha($this->pointer, true);
		}
	}

	protected function gd_formats()
	{
		$supported = imagetypes();
		$formats = [];

		if (\IMG_PNG & $supported)
			$formats[] = 'png';

		if (\IMG_GIF & $supported)
			$formats[] = 'gif';

		if (\IMG_JPEG & $supported)
			$formats[] = 'jpeg';

		if (\IMG_WBMP & $supported)
			$formats[] = 'wbmp';

		if (\IMG_XPM & $supported)
			$formats[] = 'xpm';

		if (function_exists('imagecreatefromwebp'))
			$formats[] = 'webp';

		return $formats;
	}

	protected function gd_blob($data)
	{
		$this->pointer = imagecreatefromstring($data);

		if ($this->format == 'png' || $this->format == 'gif') {
			imagealphablending($this->pointer, false);
			imagesavealpha($this->pointer, true);
		}	}

	protected function gd_size()
	{
		$this->width = imagesx($this->pointer);
		$this->height = imagesy($this->pointer);
	}

	protected function gd_close()
	{
		return imagedestroy($this->pointer);
	}

	protected function gd_save($destination, $format)
	{
		if ($format == 'jpeg')
		{
			imageinterlace($this->pointer, (int)$this->progressive_jpeg);
		}

		switch ($format)
		{
			case 'png':
				return imagepng($this->pointer, $destination, $this->compression, PNG_NO_FILTER);
			case 'gif':
				return imagegif($this->pointer, $destination);
			case 'jpeg':
				return imagejpeg($this->pointer, $destination, $this->jpeg_quality);
			case 'webp':
				return imagewebp($this->pointer, $destination, $this->jpeg_quality);
			default:
				throw new \InvalidArgumentException('Image format ' . $format . ' is unknown.');
		}
	}

	protected function gd_output($format, $return)
	{
		if ($return)
		{
			ob_start();
		}

		$res = $this->gd_save(null, $format);

		if ($return)
		{
			return ob_get_clean();
		}

		return $res;
	}

	protected function gd_create($w, $h)
	{
		$new = imagecreatetruecolor((int)$w, (int)$h);

		if ($this->format == 'png' || $this->format == 'gif')
		{
			imagealphablending($new, false);
			imagesavealpha($new, true);
			imagefilledrectangle($new, 0, 0, (int)$w, (int)$h, imagecolorallocatealpha($new, 255, 255, 255, 127));
		}

		return $new;
	}

	protected function gd_crop($new_width, $new_height)
	{
		$new = $this->gd_create($new_width, $new_height);

		$src_x = floor(($this->width - $new_width) / 2);
		$src_y = floor(($this->height - $new_height) / 2);

		imagecopy($new, $this->pointer, 0, 0, $src_x, $src_y, (int)$new_width, (int)$new_height);
		imagedestroy($this->pointer);
		$this->pointer = $new;
	}

	protected function gd_resize($new_width, $new_height, $ignore_aspect_ratio)
	{
		if (!$ignore_aspect_ratio)
		{
			$in_ratio = $this->width / $this->height;

			$out_ratio = $new_width / $new_height;

			if ($in_ratio >= $out_ratio)
			{
				$new_height = $new_width / $in_ratio;
			}
			else
			{
				$new_width = $new_height * $in_ratio;
			}
		}

		$new = $this->gd_create((int)$new_width, (int)$new_height);

		if ($this->use_gd_fast_resize_trick)
		{
			$this->gd_fastimagecopyresampled($new, $this->pointer, 0, 0, 0, 0, (int)$new_width, (int)$new_height, $this->width, $this->height, 2);
		}
		else
		{
			imagecopyresampled($new, $this->pointer, 0, 0, 0, 0, (int)$new_width, (int)$new_height, $this->width, $this->height);
		}

		imagedestroy($this->pointer);
		$this->pointer = $new;
	}

	protected function gd_flip()
	{
		imageflip($this->pointer, IMG_FLIP_HORIZONTAL);
	}

	protected function gd_rotate($angle)
	{
		// GD is using counterclockwise
		$angle = -($angle);

		$this->pointer = imagerotate($this->pointer, (int)$angle, 0);
	}

	protected function gd_fastimagecopyresampled(&$dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h, $quality = 3)
	{
		// Plug-and-Play fastimagecopyresampled function replaces much slower imagecopyresampled.
		// Just include this function and change all "imagecopyresampled" references to "fastimagecopyresampled".
		// Typically from 30 to 60 times faster when reducing high resolution images down to thumbnail size using the default quality setting.
		// Author: Tim Eckel - Date: 09/07/07 - Version: 1.1 - Project: FreeRingers.net - Freely distributable - These comments must remain.
		//
		// Optional "quality" parameter (defaults is 3). Fractional values are allowed, for example 1.5. Must be greater than zero.
		// Between 0 and 1 = Fast, but mosaic results, closer to 0 increases the mosaic effect.
		// 1 = Up to 350 times faster. Poor results, looks very similar to imagecopyresized.
		// 2 = Up to 95 times faster.  Images appear a little sharp, some prefer this over a quality of 3.
		// 3 = Up to 60 times faster.  Will give high quality smooth results very close to imagecopyresampled, just faster.
		// 4 = Up to 25 times faster.  Almost identical to imagecopyresampled for most images.
		// 5 = No speedup. Just uses imagecopyresampled, no advantage over imagecopyresampled.

		if (empty($src_image) || empty($dst_image) || $quality <= 0)
		{
			return false;
		}

		if ($quality < 5 && (($dst_w * $quality) < $src_w || ($dst_h * $quality) < $src_h))
		{
			$temp = imagecreatetruecolor(intval($dst_w * $quality + 1), intval($dst_h * $quality + 1));
			imagecopyresized($temp, $src_image, 0, 0, (int)$src_x, (int)$src_y, intval($dst_w * $quality + 1), intval($dst_h * $quality + 1), (int)$src_w, (int)$src_h);
			imagecopyresampled($dst_image, $temp, (int)$dst_x, (int)$dst_y, 0, 0, (int)$dst_w, (int)$dst_h, intval($dst_w * $quality), intval($dst_h * $quality));
			imagedestroy($temp);
		}
		else
		{
			imagecopyresampled($dst_image, $src_image, (int) $dst_x, (int) $dst_y, (int) $src_x, (int) $src_y, (int) $dst_w, (int) $dst_h, (int) $src_w, (int) $src_h);
		}

		return true;
	}
}