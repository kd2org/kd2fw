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

namespace KD2\Office;

/**
 * OpenDocument converter to plain text
 * This is mostly a PHP port from ODT2TXT
 * @see https://github.com/dstosberg/odt2txt/blob/master/odt2txt.c
 */
class ToText
{
	static public function fromFile(string $file): string
	{
		$fp = fopen($file, 'rb');

		$header = fread($fp, 4);

		if ($header == "PK\003\004") {
			fclose($fp);
			$phar = new \PharData($file, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_PATHNAME
				| FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
				null,
				Phar::ZIP
			);

			if (empty($phar['content.xml'])) {
				throw new \InvalidArgumentException('Specified file is not a valid OpenDocument file.');
			}

			$contents = file_get_contents($phar['content.xml']);
			unset($phar);
		}
		elseif ($header == '<?xm') {
			// FODT format (raw)
			fseek($fp, 0, SEEK_SET);
			$contents = '';

			while (!feof($fp)) {
				$contents .= fgets($fp, 8192);
			}

			fclose($fp);

			if ($pos = strpos($contents, '<office:body>')) {
				$contents = substr($contents, $pos);
			}

			/* remove binary */
			$contents = preg_replace('!<office:binary-data>[^>]*</office:binary-data>!', '', $contents);
		}
		else {
			throw new \InvalidArgumentException('Specified file is not a valid OpenDocument file.');
		}

		return self::fromString($contents);
	}

	static public function fromString(string $contents): string
	{
        /* remove soft-page-breaks. We don't need them and they may disturb later decoding */
        $contents = str_replace('<text:soft-page-break/>', '', $contents);
        /* same for xml-protected spaces */
        $contents = str_replace('<text:s/>', ' ', $contents);

		/* headline, first level */
		$contents = preg_replace_callback('!<text:h[^>]*outline-level="(\d+)"[^>]*>([^<]*)<[^>]*>!',
			fn ($m) => sprintf("%s %s\n\n", str_repeat('#', $m[1]), $m[2]),
			$contents);

		/* other headlines */
		$contents = preg_replace('!<text:h[^>]*>([^<]*)<[^>]*>!', '## $1', $contents);

		// List items
		$contents = preg_replace("!<text:list-item[^>]*>\s*<text:p[^>]*>!", '* ', $contents);

		/* normal paragraphs */
		$contents = preg_replace('!<text:p [^>]*>|</text:p>!', "\n\n", $contents);

		/* tabs */
		$contents = str_replace('<text:tab/>', "\t", $contents);
		$contents = str_replace('<text:line-break/>', "\n", $contents);

		/* images */
		$contents = preg_replace_callback('!<draw:frame[^>]*draw:name=\"([^\"]*)\"[^>]*>!',
			fn($m) => sprintf('[-- Image: %s --]', $m[1]),
			$contents);

		/* replace all remaining tags */
		$contents = preg_replace('!<[^>]*>!', '', $contents);
		/* remove indentations, e.g. kword */
		$contents = preg_replace("!\n +!", "\n", $contents);
		/* remove large vertical spaces */
		$contents = preg_replace("!\n{3,}!", "\n\n", $contents);

		$contents = htmlspecialchars_decode($contents);

		$contents = trim($contents);

		return $contents;
	}
}
