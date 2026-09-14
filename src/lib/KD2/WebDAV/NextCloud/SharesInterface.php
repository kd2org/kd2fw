<?php

namespace KD2\WebDAV\NextCloud;

use DateTimeInterface;

interface SharesInterface
{
	/**
	 * Create a share link
	 * @param  string            $uri         Path of the file or directory we want to share
	 * @param  array             $permissions List of permissions that should be given, see self::PERM_* constants for details
	 * @param  ?DateTimeInterface $expiry      Optional expiry date of the share URL
	 * @param  ?string            $password    Optional password of the share URL
	 * @return string Share link created for this file/folder
	 */
	public function createShareLink(string $uri, array $permissions, ?DateTimeInterface $expiry, ?string $password): string;
}
