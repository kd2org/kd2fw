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

/**
 * Various tools to get informations on files
 * Part of KD2fw
 * Copyleft (C) 2013-2016 BohwaZ <http://bohwaz.net/>
 */

class FileInfo
{
	/**
	 * Magic numbers, taken from fileinfo package
	 * Every key contains the mimetype, and the value is an array
	 * containing magic numbers
	 * 'image/gif' => ['GIF'] // will match only if the string 'GIF' is found at position 0
	 * 'application/epub+zip' => ["PK\003\004", 30 => 'mimetypeapplication/epub+zip']
	 * // will only match if "PK\003\004" is found at position 0 and 
	 * // 'mimetypeapplication/epub+zip' is found at position 30
	 * @var array
	 */
	static public $magic_numbers = [
		// Images
		'image/gif'	=>	[['GIF87a'], ['GIF89a']],
		'image/png'	=>	["\x89PNG"],
		'image/jpeg'=>	["\xff\xd8\xff"],
		'image/tiff'=>	[["\x49\x49\x2A\x00"], ["\x4D\x4D\x00\x2A"]],
		'image/bmp'	=>	['BM'],
		'image/vnd.adobe.photoshop'	=>	['8BPS'],
		'image/x-icon'	=>	["\000\000\001\000"],
		'image/xcf' => 	['gimp xcf'],
		'image/svg+xml' => 	'/^\s*<\?xml.*?\?>\s*<svg/is',

		// Office documents
		'application/msword'		=>	[
			["\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1", 546 => 'jbjb'],
			["\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1", 546 => 'bjbj']
		],
		'application/x-msoffice'	=>	["\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"],
		'application/vnd.openxmlformats-officedocument'	=>	["PK\x03\x04\x14\x00\x06\x00"],
		'application/pdf'			=>	['%PDF-'],
		'application/postscript'	=>	[["\004%"], ['%!PS-Adobe-']],
		'application/epub+zip'		=>	["PK\003\004", 30 => 'mimetypeapplication/epub+zip'],

		// Open Office 1.x
		'application/vnd.sun.xml.writer'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.sun.xml.writer'],
		'application/vnd.sun.xml.calc'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.sun.xml.calc'],
		'application/vnd.sun.xml.draw'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.sun.xml.draw'],
		'application/vnd.sun.xml.impress'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.sun.xml.impress'],
		'application/vnd.sun.xml.math'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.sun.xml.math'],
		'application/vnd.sun.xml.base'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.sun.xml.base'],

		// Open Office 2.x
		'application/vnd.oasis.opendocument.text'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.text'],
		'application/vnd.oasis.opendocument.graphics'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.graphics'],
		'application/vnd.oasis.opendocument.presentation'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.presentation'],
		'application/vnd.oasis.opendocument.spreadsheet'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.spreadsheet'],
		'application/vnd.oasis.opendocument.chart'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.chart'],
		'application/vnd.oasis.opendocument.formula'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.formula'],
		'application/vnd.oasis.opendocument.image'
			=>	["PK\003\004", 26 => "\x8\0\0\0mimetypeapplication/", 50 => 'vnd.oasis.opendocument.image'],

		// Video/audio
		'audio/x-ms-asf'			=>	["\x30\x26\xb2\x75"],
		'audio/x-wav'			=>	['RIFF', 8 => 'WAVE'],
		'video/x-msvideo'		=>	[['RIFF', 8 => 'AVI'], ['RIFX']],
		'video/quicktime'		=>	[['mdat'], ['moov']],
		'video/mpeg'			=>	[["\x1B\x3"], ["\x1B\xA"], ["\x1E\x0"]],
		'audio/mpeg'			=>	[["\xff\xfb"], ['ID3']],
		'audio/x-pn-realaudio'	=>	["\x2e\x72\x61\xfd"],
		'audio/vnd.rn-realaudio'=>	['.RMF'],
		'audio/x-flac'			=>	['fLaC'],
		'application/ogg'		=>	['OggS'],
		'audio/midi'			=>	['MThd'],

		// Text
		'text/xml'	=>	['<?xml'],
		'text/rtf'	=>	['{\\rtf'],
		'text/x-php'=>	'/<\?php|<\?=/',
		'text/html'	=>	'/<!DOCTYPE html|<(?:html|head|title|script|style|table|a href=)/i',

		// Others
		'application/zip'		=>	["PK\003\004"],
		'application/x-tar'		=>	[257 => "ustar\0\x06"],
		'application/x-xz'		=>	["\xFD7zXZ\x00"],
		'application/x-7z-compressed'	=>	["7z\xBC\xAF\x27\x1C"],

		'application/x-gzip'	=>	["\x1f\x8b"],
		'application/x-bzip'	=>	['BZ0'],
		'application/x-bzip2'	=>	['BZh'],
		'application/x-rar'		=>	['Rar!'],

		'application/x-shockwave-flash'	=>	[['FWS'], ['CWS']],

		'application/x-sqlite3'	=>	['SQLite format 3'],
		'text/x-shellscript'	=>	['#!/'],
		'application/octet-stream'=>["\177ELF"],
	];

	/**
	 * List of extensions for recognized MIME-types
	 * @var array
	 */
	static public $mime_extensions = [
		// Images
		'image/gif'	=>	'gif',
		'image/png'	=>	'png',
		'image/jpeg'=>	'jpg',
		'image/tiff'=>	'tif',
		'image/bmp'	=>	'bmp',
		'image/vnd.adobe.photoshop'	=>	'psd',
		'image/x-icon'	=>	'ico',
		'image/xcf'	=>	'xcf',
		'image/svg+xml'	=>	'svg',

		// Office documents
		'application/msword'		=>	'doc',
		'application/vnd.openxmlformats-officedocument'	=>	'docx',
		'application/pdf'			=>	'pdf',
		'application/postscript'	=>	'ps',
		'application/epub+zip'		=>	'epub',
		
		// Open Office 1.x
		'application/vnd.sun.xml.writer'	=>	'sxw',
		'application/vnd.sun.xml.calc'		=>	'sxc',
		'application/vnd.sun.xml.draw'		=>	'sxd',
		'application/vnd.sun.xml.impress'	=>	'sxi',
		'application/vnd.sun.xml.math'		=>	'sxf',

		// Open Office 2.x
		'application/vnd.oasis.opendocument.text'		=>	'odt',
		'application/vnd.oasis.opendocument.graphics'	=>	'odg',
		'application/vnd.oasis.opendocument.presentation'=>	'odp',
		'application/vnd.oasis.opendocument.spreadsheet'=>	'ods',
		'application/vnd.oasis.opendocument.chart'		=>	'odc',
		'application/vnd.oasis.opendocument.formula'	=>	'odf',

		// Video/audio
		'audio/x-ms-asf'		=>	'asx',
		'audio/x-wav'			=>	'wav',
		'video/x-msvideo'		=>	'avi',
		'video/quicktime'		=>	'mov',
		'video/mpeg'			=>	'mpeg',
		'audio/mpeg'			=>	'mp3',
		'audio/x-pn-realaudio'	=>	'ra',
		'audio/vnd.rn-realaudio'=>	'ram',
		'audio/x-flac'			=>	'flac',
		'application/ogg'		=>	'ogg',
		'audio/midi'			=>	'mid',

		// Text
		'text/xml'	=>	'xml',
		'text/rtf'	=>	'rtf',
		'text/x-php'=>	'php',
		'text/html'	=>	'html',

		// Others
		'application/zip'		=>	'zip',
		'application/x-tar'		=>	'tar',
		'application/x-xz'		=>	'xz',
		'application/x-7z-compressed'	=>	'7z',
		'application/x-gzip'	=>	'gz',
		'application/x-bzip'	=>	'bz',
		'application/x-bzip2'	=>	'bz2',
		'application/x-rar'		=>	'rar',

		'application/x-shockwave-flash'	=>	'swf',

		'application/x-sqlite3'	=>	'sqlite',
		'text/x-shellscript'	=>	'sh',
		'application/octet-stream'	=>	'exe',
	];

	/**
	 * Guesses the MIME type of a file from its content
	 * @param  string $bytes First 1024 bytes (or more) of the file
	 * @return mixed         Returns a string containing the matched MIME type or FALSE if no MIME type matched
	 */
	static public function guessMimeType($bytes)
	{
		// try to match for every mimetype
		foreach (self::$magic_numbers as $type=>$match)
		{
			// Regexp match
			if (is_string($match))
			{
				if (self::matchRegexp($match, $bytes))
					return $type;

				continue;
			}

			// Multiple matches possible for one type
			if (is_array(current($match)))
			{
				foreach ($match as $_submatch)
				{
					if (self::matchBytes($_submatch, $bytes))
						return $type;
				}

				continue;
			}

			// Single match
			if (self::matchBytes($match, $bytes))
				return $type;
		}

		return false;
    }

    /**
     * Tries to match a regular expression against the first bytes of the file
     * @param  string $regexp Regexp to test
     * @param  string $bytes  Binary string of first bytes of the file
     * @return boolean 		  TRUE if regexp matches, or else FALSE
     */
    static protected function matchRegexp($regexp, $bytes)
    {
    	return preg_match($regexp, $bytes);
    }

    /**
     * Tries to match magic numbers at specified positions
     * @param  array  $magic associative array: (byte position) => matching string
     * @param  string $bytes Binary string of first bytes
     * @return boolean 		 TRUE if magic numbers actually match, or else FALSE
     */
    static protected function matchBytes($magic, $bytes)
    {
		$match = 0;
		$max = strlen($bytes);

		// Try to match every magic number for this mimetype
		foreach ($magic as $pos=>$v)
		{
			// Content is too short to try matching this mimetype
			if ($pos > $max)
			{
				return false;
			}

			$len = strlen($v);

			// No match: skip to next mimetype
			if (substr($bytes, $pos, $len) !== $v)
			{
				return false;
			}
		}

		return true;
	}

    /**
     * Get a file extension from its MIME-type
     * @param  string $type MIME-type, eg. audio/flac
     * @return string 		extension, eg. flac
     */
    static public function getFileExtensionFromMimeType($type)
    {
    	foreach (self::$mime_extensions as $mime=>$ext)
    	{
    		if ($mime === $type)
    			return $ext;
    	}

    	return false;
    }

    static public function getMimeTypeFromFileExtension($extension)
    {
    	foreach (self::$mime_extensions as $mime=>$ext)
    	{
    		if ($extension === $ext)
    			return $mime;
    	}

    	return false;
    }
}