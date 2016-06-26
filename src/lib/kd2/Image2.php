<?php

namespace KD2;

/*
	Generic image resize library
	Copyleft (C) 2005-16 BohwaZ <http://bohwaz.net/>

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as
	published by the Free Software Foundation, version 3 of the
	License.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

class Image2
{
	protected $exact = false;
	protected $imlib = false;
	protected $imagick = false;
	protected $gd = false;

	protected $libraries = ['exact', 'imlib', 'imagick', 'gd'];

	protected $source = null;
	protected $width = null;
	protected $height = null;
	protected $format = null;
	protected $type = null;
	protected $info = null;
	protected $exif = null;

	protected $pointer = null;
	protected $pointer_lib = null;

	protected $use_gd_fast_resize_trick = true;

	/**
	 * JPEG quality, from 1 to 100
	 * @var integer
	 */
	public $jpeg_quality = 80;

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

	/**
	 * Image2 constructor
	 */
	public function __construct()
	{
		$this->exact = (extension_loaded('exactimage') && function_exists('newImage'));
		$this->imlib = (extension_loaded('imlib') && function_exists('imlib_load_image'));
		$this->imagick = (extension_loaded('imagick') && class_exists('Imagick'));
		$this->gd = (extension_loaded('gd') && function_exists('imagecreatefromjpeg'));
	}

	public function queryLibraries()
	{
		$out = [];

		foreach ($this->libraries as $lib)
		{
			$out[$lib] = $this->$lib ? $this->{$lib . '_formats'}() : false;
		}

		return $out;
	}

	protected function setLibrary($library = null)
	{
		if ($library && in_array($library, $this->libraries))
		{
			if (!$this->$library)
			{
				throw new \RuntimeException('Library \'' . $library . '\' is not installed and can not be used.');
			}

			$this->pointer_lib = $library;
		}
		else
		{
			$format = $this->getFormatFromType($this->type);

			foreach ($this->libraries as $lib)
			{
				if ($this->$lib && in_array($format, $this->{$lib . '_formats'}()))
				{
					$this->pointer_lib = $lib;
					break;
				}
			}
		}

		if (!$this->pointer_lib)
		{
			throw new \RuntimeException('No suitable image library found for type: ' . $this->type);
		}
	}

	/**
	 * Open an image file
	 * @param string $source Source image file path
	 * @param string $library Use of a specific library (imlib, exact, imagick or gd)
	 * @throws InvalidArgumentException If $source file is invalid or can not be read
	 */
	public function open($source, &$library = null)
	{
		if (empty($source))
		{
			throw new \InvalidArgumentException('Empty source file argument passed');
		}

		if (!is_readable($source))
		{
			throw new \InvalidArgumentException('Can\'t read source file: ' . $source);
		}

		$this->close();
		$this->source = $source;

		// Find MIME type
		if (function_exists('mime_content_type'))
		{
			 $this->type = mime_content_type($source);
		}

		$this->setLibrary($library);

		$this->{$this->pointer_lib . '_open'}();

		if (!$this->pointer)
		{
			throw new \RuntimeException('Invalid image format, couldn\'t be read: ' . $source);
		}

		$this->{$this->pointer_lib . '_size'}();

		$library = $this->pointer_lib;

		return $this;
	}

	/**
	 * Load an image from a string
	 * @param string $data Source image data
	 * @param string $library Use of a specific library (imlib, exact, imagick or gd)
	 * @throws InvalidArgumentException If $source data is invalid
	 */
	public function readBlob($data, $library = null)
	{
		if (empty($data))
		{
			throw new \InvalidArgumentException('Empty source data argument passed');
		}

		$this->close();
		$this->source = true;

		// Find MIME type
		if (function_exists('finfo_open'))
		{
			$f = finfo_open(FILEINFO_MIME);
			$this->type = finfo_buffer($f, $data);
		}

		$this->setLibrary($library);

		$this->{$this->pointer_lib . '_blob'}($data);

		if (!$this->pointer)
		{
			throw new \RuntimeException('Invalid image format, couldn\'t be read.');
		}

		$this->getInfo($data);
		$this->{$this->pointer_lib . '_size'}();

		return $this;
	}

	public function close()
	{
		if ($this->pointer !== null)
		{
			$this->{$this->pointer_lib . '_close'}();
		}

		$this->pointer = null;
		$this->pointer_lib = null;

		$this->source = null;
		$this->type = null;
		$this->format = null;
		$this->width = null;
		$this->height = null;
		$this->info = null;
		$this->exif = null;
	}

	public function __destruct()
	{
		$this->close();
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
	 * Returns image size and type from binary blob
	 * (only works with JPEG, PNG and GIF)
	 * @param  string $data Binary data from file (24 bytes minimum for PNG, 10 bytes for GIF and about 250 KB for JPEG)
	 * @return mixed		Array(Width, Height, Mime-Type) or FALSE if unknown file type and size
	 */
	public function getSizeFromBlob($data)
	{
		$types = ['JPEG', 'PNG', 'GIF'];

		// Try every format until it works
		foreach ($types as $type)
		{
			$func = 'getSizeFrom' . $type . 'Blob';
			$size = $this->$func($data);

			if ($size)
			{
				return $size + ['image/' . strtolower($type)];
			}
		}

		return false;
	}

	/**
	 * In case we need to read directly from the file
	 * @return string Binary blob
	 */
	public function getFileSizeHeader($source, $format = null)
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
	 * Source: http://php.net/manual/en/function.getimagesize.php#94178
	 * @param  string $data JPEG Binary string
	 * @return mixed        array(Width, Height) or FALSE if not a JPEG or no size information found
	 */
	public function getSizeFromJPEGBlob($data)
	{
		$soi = unpack('nmagic/nmarker', $data);

		// Not a JPEG
		if ($soi['magic'] != 0xFFD8)
			return false;
		
		$marker = $soi['marker'];
		$data   = substr($data, 4);
		$done   = false;

		while (true)
		{
			if (strlen($data) === 0)
				return false;

			switch($marker)
			{
				case 0xFFC0:
					$info = unpack('nlength/Cprecision/nY/nX', $data);
					return [$info['X'], $info['Y']];
					break;

				default:
					$info   = unpack('nlength', $data);
					$data   = substr($data, $info['length']);
					$info   = unpack('nmarker', $data);
					$marker = $info['marker'];
					$data   = substr($data, 2);
					break;
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
	public function getSizeFromPNGBlob($data)
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

		$xy = unpack('NX/NY', substr($data, 0, 8));
		return array_values($xy);
	}

	/**
	 * Extracts GIF image size directly from binary blob
	 * Source: http://giflib.sourceforge.net/whatsinagif/bits_and_bytes.html
	 * @param  string $data Binary GIF blob (10 bytes minimum)
	 * @return mixed        Arry [Width, Height] or FALSE if not a GIF file
	 */
	public function getSizeFromGIFBlob($data)
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

	public function getInfo($data = null)
	{
		if ($this->info !== null)
			return $this->info;

		if (!$this->gd)
		{
			throw new \RuntimeException('Can not get image info: GD extension not installed but required to call getimagesize()');
		}
		
		$extra = null;
		
		if (is_null($data))
		{
			$this->info = getimagesize($this->source, $extra);
		}
		else
		{
			$this->info = getimagesizefromstring($data, $extra);
		}

		$this->info['info'] = $extra;

		return $this->info;
	}

	public function getExif()
	{
		if ($this->type !== 'image/jpeg')
			return false;

		if ($this->exif !== null)
			return $this->exif;

		if (!function_exists('exif_read_data'))
		{
			throw new \RuntimeException('PHP EXIF extension is not installed.');
		}

		$this->exif = exif_read_data($this->source, null, false, true);
		return $this->exif;
	}

	public function getExifThumbnail()
	{
		$exif = $this->getExif();

		if (empty($exif['THUMBNAIL']['THUMBNAIL']))
		{
			return false;
		}

		return $exif['THUMBNAIL']['THUMBNAIL'];
	}

	public function getExifThumbnailDetails()
	{
		$exif = $this->getExif();

		if (empty($exif['THUMBNAIL']))
		{
			return false;
		}

		unset($exif['THUMBNAIL']['THUMBNAIL']);
		return $exif['THUMBNAIL'];
	}

	public function saveExifThumbnail($destination, $auto_rotate = false)
	{
		$exif = $this->getExif();

		if (empty($exif['THUMBNAIL']['THUMBNAIL']))
		{
			return false;
		}

		// If we don't need to rotate the image then return it
		if (!$auto_rotate || empty($exif['THUMBNAIL']['Orientation'])
			|| !in_array($exif['THUMBNAIL']['Orientation'], [3, 6, 8]))
		{
			return file_put_contents($destination, $exif['THUMBNAIL']['THUMBNAIL']);
		}

		$im = new self();
		$im->load($exif['THUMBNAIL']['THUMBNAIL']);
		$im->rotate($exif['THUMBNAIL']['Orientation']);
		return $im->save($destination);
	}

	public function getExifRotation($exif_orientation = null)
	{
		if (is_null($exif_orientation))
		{
			$exif = $this->getExif();

			if (!$exif)
				return false;

			if (isset($exif['Orientation']))
			{
				$exif_orientation = $exif['Orientation'];
			}
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

	/**
	 * Crop the current image to this dimensions
	 * @param  [type] $destination [description]
	 * @param  [type] $new_width   [description]
	 * @param  [type] $new_height  [description]
	 * @return [type]              [description]
	 */
	public function crop($new_width, $new_height = null)
	{
		if (!$new_height)
		{
			$new_height = $new_width;
		}

		$new_height = (int) $new_height;
		$new_width = (int) $new_width;

		$this->{$this->pointer_lib . '_crop'}($new_width, $new_height);
		$this->{$this->pointer_lib . '_size'}();

		return $this;
	}

	public function resize($new_width, $new_height = null, $ignore_aspect_ratio = false)
	{
		if (!$new_height)
		{
			$new_height = $new_width;
		}

		$new_height = (int) $new_height;
		$new_width = (int) $new_width;

		$this->{$this->pointer_lib . '_resize'}($new_width, $new_height, $ignore_aspect_ratio);
		$this->{$this->pointer_lib . '_size'}();

		return $this;
	}

	public function rotate($angle)
	{
		if (!$angle)
		{
			return $this;
		}

		$this->{$this->pointer_lib . '_rotate'}($angle);
		$this->{$this->pointer_lib . '_size'}();

		return $this;
	}

	public function rotateAuto()
	{
		return $this->rotate($this->getExifRotation());
	}

	public function cropResize($new_width, $new_height = null)
	{
		if (!$new_height)
		{
			$new_height = $new_width;
		}

		$crop = min($new_width, $new_height);
		return $this->crop($crop)->resize($new_width, $new_height);
	}

	public function save($destination, $format = null)
	{
		if (is_null($format))
		{
			$format = $this->format;
		}

		if (!in_array($format, $this->{$this->pointer_lib . '_formats'}()))
		{
			throw new \InvalidArgumentException('The specified format ' . $format . ' can not be used by ' . $this->pointer_lib);
		}

		return $this->{$this->pointer_lib . '_save'}($destination, $format);
	}

	public function output($format = null, $return = false)
	{
		return $this->{$this->pointer_lib . '_output'}($format, $return);
	}

	protected function getCropGeometry($w, $h, $new_width, $new_height)
	{
		$proportion_src = $w / $h;
		$proportion_dst = $new_width / $new_height;

		$x = $y = 0;
		$out_w = $w;
		$out_h = $h;

		if ($proportion_src > $proportion_dst)
		{
			$out_w = $h * $proportion_dst;
			$x = round(($w - $out_w) / 2);
		}
		else
		{
			$out_h = $w / $proportion_dst;
			$y = round(($h - $out_h) / 2);
		}

		return [$x, $y, round($out_w), round($out_h)];
	}

	public function getFormatFromType($type)
	{
		switch ($type)
		{
			case 'image/svg+xml':	return 'svg';
			case 'application/pdf':	return 'pdf';
			case 'image/vnd.adobe.photoshop': return 'psd';
			case 'image/x-icon': return 'bmp';
			default:
				if (preg_match('!^image/([\w\d]+)$!', $type, $match))
					return $match[1];
				return false;
		}
	}

	// ExactImage methods /////////////////////////////////////////////////////
	protected function exact_open()
	{
		$this->pointer = newImage();
		decodeImageFile($this->pointer, $this->source);
	}

	protected function exact_formats()
	{
		// From https://exactcode.com/opensource/exactimage/
		return ['gif', 'jpeg', 'png', 'jp2', 'bmp', 'exr', 'raw', 'pbm', 
			'tiff', 'xpm', 'svg', 'pcx', 'tga'];
	}

	protected function exact_blob($data)
	{
		$this->pointer = newImage();
		decodeImage($this->pointer, $data);
	}

	protected function exact_size()
	{
		$this->width = imageWidth($this->pointer);
		$this->height = imageHeight($this->pointer);
	}

	protected function exact_close()
	{
		return deleteImage($this->pointer);
	}

	protected function exact_save($destination, $format)
	{
		return file_put_contents($destination, $this->exact_output($format));
	}

	protected function exact_output($format, $return)
	{
		$im = encodeImage($this->pointer, $format, $this->jpeg_quality, $this->compression);

		if ($return)
			return $im;

		echo $im;
		return true;
	}

	// ImLib methods //////////////////////////////////////////////////////////
	protected function imlib_open()
	{
		$this->pointer = imlib_load_image($this->source);
		$this->format = imlib_image_format($this->pointer);
	}

	protected function imlib_formats()
	{
		// There is no way to query imlib for supported formats so this is
		// from the source code, hopefully your version has support compiled
		// for those loaders
		// GIF is read-only, so not included here
		return ['tiff', 'jpeg', 'png', 'pnm', 'bmp', 'xpm', 'tga'];
	}

	protected function imlib_blob($data)
	{
		// Imlib doesn't have a function to create an image from a string, so
		// to avoid creating a temporary file we use data: scheme
		$this->source = 'data:text/plain,' . urlencode($data);
		$this->imlib_open();
		$this->source = true;
	}

	protected function imlib_size()
	{
		$this->width = imlib_image_get_width($this->pointer);
		$this->height = imlib_image_get_height($this->pointer);
	}

	protected function imlib_close()
	{
		imlib_free_image($this->pointer);
	}

	protected function imlib_save($destination, $format)
	{
		$q = null;

		if ($format == 'jpeg')
		{
			$q = $this->jpeg_quality;
		}
		else if ($format == 'tiff' || $format == 'png')
		{
			$q = $this->compression;
		}

		imlib_image_set_format($this->pointer, $format);
		return imlib_save_image($this->pointer, $destination, $err, $q);
	}

	protected function imlib_output($format, $return)
	{
		$q = null;

		if ($format == 'jpeg')
		{
			$q = $this->jpeg_quality;
		}
		else if ($format == 'tiff' || $format == 'png')
		{
			$q = $this->compression;
		}

		// Note that imlib_dump_image is just saving the image to a temporary file and reading it
		// so it is inefficient
		if ($return)
		{
			ob_start();
		}

		$res = imlib_dump_image($this->pointer, null, (int)$q);

		if ($return)
		{
			return ob_get_clean();
		}

		return $res;
	}

	protected function imlib_crop($new_width, $new_height = null)
	{
		list($x, $y, $w, $h) = $this->getCropGeometry($this->width, $this->height, $new_width, $new_height);

		$this->pointer = imlib_create_cropped_image($this->pointer, $x, $y, $new_width, $new_height);
	}

	protected function imlib_resize($new_width, $new_height = null, $ignore_aspect_ratio = false)
	{
		if (!$ignore_aspect_ratio)
		{
			if ($this->width > $this->height)
				$new_height = 0;
			else
				$new_width = 0;
		}

		$this->pointer = imlib_create_scaled_image($this->pointer, $new_width, $new_height);

		if (!$this->pointer)
		{
			throw new \RuntimeException('Image crop failed using imlib');
		}
	}

	protected function imlib_rotate($angle)
	{
		$this->pointer = imlib_create_rotated_image($this->pointer, $angle);
	}

	// Imagick methods ////////////////////////////////////////////////////////
	protected function imagick_open()
	{
		try {
			$this->pointer = new \Imagick($this->source);
		}
		catch (\ImagickException $e)
		{
			throw new \RuntimeException('Unable to open file: ' . $this->source, false, $e);
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
			$this->pointer->setCompression(\Imagick::COMPRESSION_LZW);
			$this->pointer->setCompressionQuality($this->compression * 10);
		}
		else if ($format == 'jpeg')
		{
			$this->pointer->setCompression(\Imagick::COMPRESSION_JPEG);
			$this->pointer->setCompressionQuality($this->jpeg_quality);
			$this->pointer->setInterlaceScheme($this->progressive_jpeg ? \Imagick::INTERLACE_PLANE : \Imagick::INTERLACE_NO);
		}

		if ($format == 'gif' && $this->pointer->getIteratorIndex() > 0)
			return file_put_contents($destination, $this->pointer->getImagesBlob());
		else
			return $this->pointer->writeImage($destination);
	}

	protected function imagick_output($format, $return)
	{
		$this->pointer->setImageFormat($format);

		if ($format == 'png')
		{
			$this->pointer->setCompression(\Imagick::COMPRESSION_LZW);
			$this->pointer->setCompressionQuality($this->compression * 10);
		}
		else if ($format == 'jpeg')
		{
			$this->pointer->setCompression(\Imagick::COMPRESSION_JPEG);
			$this->pointer->setCompressionQuality($this->jpeg_quality);
			$this->pointer->setInterlaceScheme($this->progressive_jpeg ? \Imagick::INTERLACE_PLANE : \Imagick::INTERLACE_NO);
		}

		if ($format == 'gif' && $this->pointer->getIteratorIndex() > 0)
			$res = $this->pointer->getImagesBlob();
		else
			$res = (string) $this->pointer;

		if ($return)
			return $res;
		
		echo $res;
		return true;
	}

	protected function imagick_crop($new_width, $new_height)
	{
		list($x, $y, $w, $h) = $this->getCropGeometry($this->width, $this->height, $new_width, $new_height);

		// Detect animated GIF
		if ($this->format == 'gif' && $this->pointer->getIteratorIndex() > 0)
		{
			$index = $this->pointer->getIteratorIndex();
			// FIXME keep iterations
			$image = $this->pointer->coalesceImages();

			foreach ($image as $frame)
			{
				$frame->cropImage($w, $h, $x, $y);
			  	$frame->setImagePage($w, $h, 0, 0);
			}

			$this->pointer = $image->deconstructImages(); 
			$this->pointer->setIteratorIndex($index);
		}
		else
		{
			$this->pointer->cropImage($w, $h, $x, $y);
		}
	}

	protected function imagick_resize($new_width, $new_height, $ignore_aspect_ratio = false)
	{
		// Detect animated GIF
		if ($this->format == 'gif' && $this->pointer->getIteratorIndex() > 0)
		{
			$index = $this->pointer->getIteratorIndex();
			$image = $this->pointer->coalesceImages();

			foreach ($image as $frame)
			{
				$frame->thumbnailImage($new_width, $new_height, !$ignore_aspect_ratio);
			  	$frame->setImagePage($new_width, $new_height, 0, 0);
			}

			$this->pointer = $image->deconstructImages(); 
			$this->pointer->setIteratorIndex($index);
		}
		else
		{
			// For transparent images
			if ($this->pointer->getImageAlphaChannel())
			{
				$this->pointer->setImageOpacity(1.0);
				$this->pointer->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.3, \Imagick::CHANNEL_ALPHA);
			}
			
			$this->pointer->resizeImage($new_width, $new_height, \Imagick::FILTER_CATROM, 1, false);
		}
	}

	protected function imagick_rotate($angle)
	{
		$this->pointer->rotateImage(new \ImagickPixel('#00000000'), $angle);
		$this->pointer->setImageOrientation(\Imagick::ORIENTATION_UNDEFINED);
	}

	// GD methods /////////////////////////////////////////////////////////////
	protected function gd_open()
	{
		$info = $this->getInfo();
		$this->format = image_type_to_extension($info[2]);

		if (!$this->format || !array_key_exists($this->format, $this->gd_supported_types) || !$this->gd_supported_types[$this->format])
		{
			throw new \RuntimeException('Image type is not supported by GD.');
		}

		$func = 'imagecreatefrom' . $this->format;
		$this->pointer = $func($this->source);
	}

	protected function gd_formats()
	{
		$supported = imagetypes();
		$formats = [];

		if (IMG_PNG & $supported)
			$formats[] = 'png';

		if (IMG_GIF & $supported)
			$formats[] = 'gif';

		if (IMG_JPEG & $supported)
			$formats[] = 'jpeg';

		if (IMG_WBMP & $supported)
			$formats[] = 'wbmp';

		if (IMG_XPM & $supported)
			$formats[] = 'xpm';

		if (function_exists('imagecreatefromwebp'))
			$formats[] = 'webp';

		return $formats;
	}

	protected function gd_blob($data)
	{
		$this->pointer = imagecreatefromstring($data);
	}

	protected function gd_size()
	{
		$info = $this->getInfo();
		
		if (!$info)
			return false;
		
		$this->width = $info[0];
		$this->height = $info[1];
	}

	protected function gd_close()
	{
		return imagedestroy($this->pointer);
	}

	protected function gd_save($destination, $format)
	{
		if (!array_key_exists($format, $this->gd_supported_types) || !$this->gd_supported_types[$format])
		{
			throw new \InvalidArgumentException('Image format ' . $format . ' is not supported by GD.');
		}

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
		$new = imagecreatetruecolor($new_width, $new_height);

        if ($this->format == 'png' || $this->format == 'gif')
        {
			imagealphablending($new, false);
			imagecolortransparent($new, imagecolorallocatealpha($new, 0, 0, 0, 127));
			imagesavealpha($new, true);
        }

        return $new;
	}

	protected function gd_crop($new_width, $new_height)
	{
		list($src_x, $src_y, $src_w, $src_h) = $this->getCropGeometry($this->width, $this->height, $new_width, $new_height);

		$new = $this->gd_create($new_width, $new_height);

		imagecopy($new, $this->pointer, 0, 0, $src_x, $src_y, $src_w, $src_h);
		imagedestroy($this->pointer);
		$this->pointer = $new;
	}

	protected function gd_resize($new_width, $new_height, $ignore_aspect_ratio)
	{
		// Nothing to do
		$in_ratio = $w / $h;
		$out_ratio = $new_width / $new_height;

		if ($in_ratio >= $out_ratio)
		{
			$new_height = $new_width / $in_ratio;
		}
		else
		{
			$new_width = $new_height * $in_ratio;
		}

		$new = $this->gd_create($new_width, $new_height);

		if ($this->use_gd_fast_resize_trick)
		{
			$this->gd_fastimagecopyresampled($new, $this->pointer, 0, 0, 0, 0, $new_width, $new_height, $this->width, $this->height, 2);
		}
		else
		{
			imagecopyresampled($new, $this->pointer, 0, 0, 0, 0, $new_width, $new_height, $this->width, $this->height);
		}

		imagedestroy($this->pointer);
		$this->pointer = $new;
	}

	protected function gd_rotate($angle)
	{
		// GD is using counterclockwise
		$angle = 360 - $angle;

		$this->pointer = imagerotate($this->pointer, $angle, -1);
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
			$temp = imagecreatetruecolor ($dst_w * $quality + 1, $dst_h * $quality + 1);
			imagecopyresized ($temp, $src_image, 0, 0, $src_x, $src_y, $dst_w * $quality + 1, $dst_h * $quality + 1, $src_w, $src_h);
			imagecopyresampled ($dst_image, $temp, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $dst_w * $quality, $dst_h * $quality);
			imagedestroy ($temp);
		}
		else
		{
			imagecopyresampled ($dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);
		}

		return true;
	}
}