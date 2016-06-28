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

/*
    Generic image resize library
    Copyleft (C) 2005-15 BohwaZ <http://bohwaz.net/>
*/

class Image
{
    static protected $options = [
        'crop' => false,
        'ignore_aspect_ratio' => false,
        'force_size_using_bg_color' => false,
        'force_gd' => false,
        'force_imagick' => false,
        'force_imlib' => false,
        'use_gd_fast_resize_trick' => true,
        'enable_report' => true,
        'force_output_format' => false,
        'progressive_jpeg' => false,
        'jpeg_quality' => 75,
        'png_compression' => 9,
        'background_color' => '000000',
    ];

    // Libs
    const IMLIB = 1;
    const IMAGICK = 2;
    const GD = 3;

    static protected $report = [];
    static protected $cache = [];

    static public function canUseImlib()
    {
        return (extension_loaded('imlib') && function_exists('imlib_load_image'));
    }

    static public function canUseImagick()
    {
        return (extension_loaded('imagick') && class_exists('Imagick'));
    }

    static public function canUseGD()
    {
        return (extension_loaded('gd') && function_exists('imagecreatefromjpeg'));
    }

    static protected function option($id)
    {
        if (array_key_exists($id, self::$options))
            return self::$options[$id];
        else
            return false;
    }

    static protected function parseOptions($options)
    {
        foreach (self::$options as $key=>$value)
        {
            if (!array_key_exists($key, $options))
            {
                $options[$key] = $value;
            }
        }

        if ($options['force_imlib'] && !self::canUseImlib())
        {
            throw new \RuntimeException('Imlib is forced but doesn\'t seem not installed');
        }
        elseif ($options['force_gd'] && !self::canUseGD())
        {
            throw new \RuntimeException('GD is forced but doesn\'t seem not installed');
        }
        elseif ($options['force_imagick'] && !self::canUseImagick())
        {
            throw new \RuntimeException('Imagick is forced but doesn\'t seem not installed');
        }
        
        if (!empty($options['force_output_format']) 
            && $options['force_output_format'] != 'JPEG' 
            && $options['force_output_format'] != 'PNG')
        {
            throw new \RuntimeException('Option force_output_format must be either JPEG or PNG');
        }

        return $options;
    }

    static public function identify($src_file)
    {
        if (empty($src_file))
            throw new \RuntimeException('No source file argument passed');

        $hash = sha1($src_file);

        if (array_key_exists($hash, self::$cache))
        {
            return self::$cache[$hash];
        }

        $image = false;

        if (self::canUseImlib())
        {
            $im = @imlib_load_image($src_file);

            if ($im)
            {
                $image = [
                    'format'    =>  strtoupper(imlib_image_format($im)),
                    'width'     =>  imlib_image_get_width($im),
                    'height'    =>  imlib_image_get_height($im),
                ];

                imlib_free_image($im);
            }

            unset($im);
        }

        if (!$image && self::canUseImagick())
        {
            try {
                $im = new \Imagick($src_file);

                if ($im)
                {
                    $image = [
                        'width'     =>  $im->getImageWidth(),
                        'height'    =>  $im->getImageHeight(),
                        'format'    =>  strtoupper($im->getImageFormat()),
                    ];

                    $im->destroy();
                }

                unset($im);
            }
            catch (\ImagickException $e)
            {
            }

        }

        if (!$image && self::canUseGD())
        {
            $gd_img = getimagesize($src_file);

            if (!$gd_img)
                return false;

            $image['width'] = $gd_img[0];
            $image['height'] = $gd_img[1];

            switch ($gd_img[2])
            {
                case IMAGETYPE_GIF:
                    $image['format'] = 'GIF';
                    break;
                case IMAGETYPE_JPEG:
                    $image['format'] = 'JPEG';
                    break;
                case IMAGETYPE_PNG:
                    $image['format'] = 'PNG';
                    break;
                default:
                    $image['format'] = false;
                    break;
            }
        }

        self::$cache[$hash] = $image;

        return $image;
    }

    static public function resize($src_file, $dst_file, $new_width, $new_height=null, $options=[])
    {
        if (empty($src_file))
            throw new \RuntimeException('No source file argument passed');

        if (empty($dst_file))
            throw new \RuntimeException('No destination file argument passed');

        if (empty($new_width))
            throw new \RuntimeException('Needs at least the new width as argument');

        $options = self::parseOptions($options);

        if ($options['enable_report'])
        {
            self::$report = [
                'engine_used'   =>  '',
                'time_taken'    =>  0,
                'start_time'    =>  microtime(true),
            ];
        }

        if (!$new_height)
        {
            $new_height = $new_width;
        }

        $new_height = (int) $new_height;
        $new_width = (int) $new_width;

        $lib = false;

        if ($options['force_imlib'])
        {
            $lib = self::IMLIB;
        }
        elseif ($options['force_imagick'])
        {
            $lib = self::IMAGICK;
        }
        if ($options['force_gd'])
        {
            $lib = self::GD;
        }

        if ($options['force_size_using_bg_color'])
        {
            if ($lib == self::IMLIB)
            {
                throw new \RuntimeException("You can't use Imlib to force image size width background color.");
            }

            if (!$lib && self::canUseImagick())
            {
                $lib = self::IMAGICK;
            }
            elseif (!$lib && self::canUseGD())
            {
                $lib = self::GD;
            }
            elseif (!$lib)
            {
                throw new \RuntimeException("You need GD or Imagick to force image size using background color.");
            }
        }

        if (!$lib)
        {
            if (self::canUseImlib())
                $lib = self::IMLIB;
            elseif (self::canUseImagick())
                $lib = self::IMAGICK;
            elseif (self::canUseGD())
                $lib = self::GD;
        }

        if (empty($lib))
        {
            throw new \RuntimeException('No usable image library found');
        }

        if ($lib == self::IMLIB)
        {
            $res = self::imlibResize($src_file, $dst_file, $new_width, $new_height, $options);
        }
        elseif ($lib == self::IMAGICK)
        {
            $res = self::imagickResize($src_file, $dst_file, $new_width, $new_height, $options);
        }
        elseif ($lib == self::GD)
        {
            $res = self::gdResize($src_file, $dst_file, $new_width, $new_height, $options);
        }

        if ($options['enable_report'])
        {
            if ($lib == self::IMLIB)
                self::$report['engine_used'] = 'imlib';
            elseif ($lib == self::IMAGICK)
                self::$report['engine_used'] = 'imagick';
            elseif ($lib == self::GD)
                self::$report['engine_used'] = 'gd';

            self::$report['time_taken'] = microtime(true) - self::$report['start_time'];
            unset(self::$report['start_time']);
        }

        return $res;
    }

    static public function getReport()
    {
        return self::$report;
    }

    static protected function getCropGeometry($w, $h, $new_width, $new_height)
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

    static protected function imlibResize($src_file, $dst_file, $new_width, $new_height, $options)
    {
        $src = @imlib_load_image($src_file);

        if (!$src)
            return false;

        if ($format = $options['force_output_format'])
            $type = strtolower($format);
        else
            $type = strtolower(imlib_image_format($src));

        $w = imlib_image_get_width($src);
        $h = imlib_image_get_height($src);

        if ($options['crop'])
        {
            list($x, $y, $w, $h) = self::getCropGeometry($w, $h, $new_width, $new_height);

            $dst = imlib_create_cropped_scaled_image($src, $x, $y, $w, $h, $new_width, $new_height);
        }
        elseif ($options['ignore_aspect_ratio'])
        {
            $dst = imlib_create_scaled_image($src, $new_width, $new_height);
        }
        else
        {
            if ($w > $h)
                $new_height = 0;
            else
                $new_width = 0;

            $dst = imlib_create_scaled_image($src, $new_width, $new_height);
        }

        imlib_free_image($src);

        if ($type == 'png')
        {
            $png_compression = (int) $options['png_compression'];

            if (empty($png_compression))
                $png_compression = self::$default_png_compression;

            imlib_image_set_format($dst, 'png');
            $res = imlib_save_image($dst, $dst_file, $err, (int)$png_compression);
        }
        elseif ($type == 'gif')
        {
            imlib_image_set_format($dst, 'gif');
            $res = imlib_save_image($dst, $dst_file);
        }
        else
        {
            $jpeg_quality = (int) $options['jpeg_quality'];

            if (empty($jpeg_quality))
                $jpeg_quality = 85;

            imlib_image_set_format($dst, 'jpeg');
            $res = imlib_save_image($dst, $dst_file, $err, (int)$jpeg_quality);
        }

        $w = imlib_image_get_width($dst);
        $h = imlib_image_get_height($dst);

        imlib_free_image($dst);

        return ($res ? [$w, $h] : $res);
    }

    static protected function imagickResize($src_file, $dst_file, $new_width, $new_height, $options)
    {
        try {
            $im = new \Imagick($src_file);
        }
        catch (\ImagickException $e)
        {
            return false;
        }

        if ($format = $options['force_output_format'])
            $type = strtolower($format);
        else
            $type = strtolower($im->getImageFormat());

        $im->setImageFormat($type);

        if ($options['crop'])
        {
            $im->cropThumbnailImage($new_width, $new_height);
        }
        elseif ($options['force_size_using_bg_color'])
        {
            if ($options['force_size_using_bg_color'] == 'transparent')
                $c = new \ImagickPixel('transparent');
            else
                $c = new \ImagickPixel('#' . $options['force_size_using_bg_color']);

            $im->thumbnailImage($new_width, $new_height, true);

            $bg = new \Imagick;
            $bg->newImage($new_width, $new_height, $c, 'png');

            $geometry = $im->getImageGeometry();

            /* The overlay x and y coordinates */
            $x = ($new_width - $geometry['width']) / 2;
            $y = ($new_height - $geometry['height']) / 2;

            $bg->compositeImage($im, \Imagick::COMPOSITE_OVER, $x, $y);
            $im->destroy();
            $im = $bg;
            unset($bg);
        }
        else
        {
            $im->thumbnailImage($new_width, $new_height, !$options['ignore_aspect_ratio']);
        }

        if ($type == 'png')
        {
            $png_compression = (int) $options['png_compression'];

            if (empty($png_compression))
                $png_compression = 5;

            $im->setImageFormat('png');
            $im->setCompression(\Imagick::COMPRESSION_LZW);
            $im->setCompressionQuality($png_compression * 10);
        }
        elseif ($type == 'gif')
        {
            $im->setImageFormat('gif');
        }
        else
        {
            $jpeg_quality = (int) $options['jpeg_quality'];

            if (empty($jpeg_quality))
                $jpeg_quality = 85;

            $im->setImageFormat('jpeg');
            $im->setCompression(\Imagick::COMPRESSION_JPEG);
            $im->setCompressionQuality($jpeg_quality);
        }

        $res = file_put_contents($dst_file, $im);

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();

        $im->destroy();

        return ($res ? [$w, $h] : $res);
    }

    static protected function gdResize($src_file, $dst_file, $new_width, $new_height, $options)
    {
        $infos = self::identify($src_file);

        if (!$infos)
            return false;

        if ($options['force_output_format'])
            $type = $options['force_output_format'];
        else
            $type = $infos['format'];

        try
        {
            switch ($infos['format'])
            {
                case 'JPEG':
                    $src = imagecreatefromjpeg($src_file);
                    break;
                case 'PNG':
                    $src = imagecreatefrompng($src_file);
                    break;
                case 'GIF':
                    $src = imagecreatefromgif($src_file);
                    break;
                default:
                    return false;
            }

            if (!$src)
                throw new \RuntimeException("No source image created");
        }
        catch (\Exception $e)
        {
            throw new \RuntimeException("Invalid input format: ".$e->getMessage());
        }

        $w = $infos['width'];
        $h = $infos['height'];

        $dst_x = 0;
        $dst_y = 0;
        $src_x = 0;
        $src_y = 0;
        $dst_w = $new_width;
        $dst_h = $new_height;
        $src_w = $w;
        $src_h = $h;
        $out_w = $new_width;
        $out_h = $new_height;

        if ($options['crop'])
        {
            list($src_x, $src_y, $src_w, $src_h) = self::getCropGeometry($w, $h, $new_width, $new_height);
        }
        elseif (!$options['ignore_aspect_ratio'])
        {
            if ($w <= $new_width && $h <= $new_height)
            {
                $dst_w = $out_w = $w;
                $dst_h = $out_h = $h;
            }
            else
            {
                $in_ratio = $w / $h;
                $out_ration = $new_width / $new_height;

                $pic_width = $new_width;
                $pic_height = $new_height;

                if ($in_ratio >= $out_ration)
                {
                    $pic_height = $new_width / $in_ratio;
                }
                else
                {
                    $pic_width = $new_height * $in_ratio;
                }

                $dst_w = $out_w = $pic_width;
                $dst_h = $out_h = $pic_height;
            }
        }

        if ($options['force_size_using_bg_color'])
        {
            $diff_width = $new_width - $dst_w;
            $diff_height = $new_height - $dst_h;
            $offset_x = $diff_width / 2;
            $offset_y = $diff_height / 2;

            $dst_x = round($offset_x);
            $dst_y = round($offset_y);
            $out_w = $new_width;
            $out_h = $new_height;
        }

        $dst = imagecreatetruecolor($out_w, $out_h);

        if (!$dst)
        {
            return false;
        }

        imageinterlace($dst, 0);

        $use_background = false;

        if ($options['force_size_using_bg_color'])
        {
            if ($options['force_size_using_bg_color'] == 'transparent'
                || strlen($options['force_size_using_bg_color']) == 6)
                $use_background = $options['force_size_using_bg_color'];
            else
                $use_background = '000000';
        }

        if (!$use_background || $use_background == 'transparent')
        {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        else
        {
            $color = imagecolorallocate($dst,
                hexdec(substr($use_background, 0, 2)),
                hexdec(substr($use_background, 2, 2)),
                hexdec(substr($use_background, 4, 2))
                );

            imagefill($dst, 0, 0, $color);
        }


        if ($options['use_gd_fast_resize_trick'])
        {
            fastimagecopyresampled($dst, $src, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h, 2);
        }
        else
        {
            imagecopyresampled($dst, $src, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);
        }

        imagedestroy($src);

        try
        {
            if ($type == 'PNG')
            {
                $png_compression = (int) $options['png_compression'];

                if (empty($png_compression))
                    $png_compression = 5;

                $res = imagepng($dst, $dst_file, $png_compression, PNG_NO_FILTER);
            }
            elseif ($type == 'GIF')
            {
                $res = imagegif($dst, $dst_file);
            }
            else
            {
                $jpeg_quality = (int) $options['jpeg_quality'];

                if (empty($jpeg_quality))
                    $jpeg_quality = 85;

                $res = imagejpeg($dst, $dst_file, $jpeg_quality);
            }

            imagedestroy($dst);
        }
        catch (\Exception $e)
        {
            throw new \RuntimeException("Unable to create destination file: ".$e->getMessage());
        }

        return ($res ? [$dst_w, $dst_h] : $res);
    }

    static public function getImageStreamFormat($bytes)
    {
        $b = substr($bytes, 0, 4);
        unset($bytes);

        if ($b == 'GIF8')
            return 'GIF';
        elseif ($b == "\x89PNG")
            return 'PNG';
        elseif (substr($b, 0, 3) == "\xff\xd8\xff")
            return 'JPEG';

        return false;
    }
}

function fastimagecopyresampled (&$dst_image, $src_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h, $quality = 3)
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
