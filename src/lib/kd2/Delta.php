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
 * Delta algorithm
 * ported from the C code of Fossil SCM
 * http://www.fossil-scm.org/xfer/doc/trunk/www/delta_format.wiki
 * http://www.fossil-scm.org/xfer/doc/trunk/www/delta_encoder_algorithm.wiki
 *
 * KD2 PHP framework - http://dev.kd2.org/fw/
 */

/*
** Copyright (c) 2006 D. Richard Hipp
** Copyright (c) 2013 BohwaZ (PHP port)
**
** Authors contact information:
**   drh@hwaci.com
**   http://www.hwaci.com/drh/
**
**   http://bohwaz.net/
**
*******************************************************************************
**/


class Delta_Exception extends \Exception {}

class Delta_Hash
{
	public $a; /* Hash values */
	public $b;
	public $i; /* Start of the hash window */
	public $z; /* The values that have been hashed */

	public function __set($key, $value)
	{
		if (($key == 'a' || $key == 'b' || $key == 'i') && (!is_int($value) || $value > 2^16)) {
			throw new \OutOfBoundsException($key . ' value must be a 16 bits integer');
		}

		if ($key != 'z')
		{
			$this->{$key} = $value & 0xffff;
		}
		else
		{
			$this->{$key} = $value;
		}
	}
}

class Delta
{
	const NHASH = 16;
	public $debug_enabled = false;

	protected function debug($msg)
	{
		echo str_replace("\n", '.', $msg) . "\n";
		return;
	}

	/**
	 * Emulates C-like 32-bits integer
	 * @param  mixed 	$number
	 * @return integer 	Unsigned integer
	 */
	protected function u32($number)
	{
		return $number & 0xffffffff;
	}

	/**
	 * Emulates C-like 16-bit integer
	 * @param  mixed $number
	 * @return mixed Unsigned integer
	 */
	protected function u16($number)
	{
		return $number & 0xffff;
	}

	/*
	** Initialize the rolling hash using the first NHASH characters of z[]
	*/
	protected function hash_init(Delta_Hash &$pHash, $z)
	{
		$a = $b = 0;

		for ($i=0; $i < self::NHASH; $i++) {
			$a += ord($z[$i]);
			$b += (self::NHASH - $i) * ord($z[$i]);
			$pHash->z[$i] = ord($z[$i]);
		}

		$pHash->a = $a & 0xffff;
		$pHash->b = $b & 0xffff;
		$pHash->i = 0;
	}

	/*
	** Advance the rolling hash by a single character "c"
	*/
	protected function hash_next(Delta_Hash &$pHash, $c)
	{
		$old = $this->u16($pHash->z[$pHash->i]);
		$pHash->z[$pHash->i] = $c;
		$pHash->i = ($pHash->i+1) & (self::NHASH - 1);
		$pHash->a = $pHash->a - $old + $c;
		$pHash->b = $pHash->b - self::NHASH * $old + $pHash->a;
	}

	/*
	** Return a 32-bit hash value
	*/
	protected function hash_32bit(Delta_Hash $pHash)
	{
		return ($pHash->a & 0xffff) | sprintf('%u', $this->u32(($pHash->b & 0xffff)<<16));
	}

	/*
	** Write an base-64 integer into the given buffer.
	*/
	protected function putInt($v)
	{
		static $zDigits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz~';
					   /*  123456789 123456789 123456789 123456789 123456789 123456789 123 */

		$zBuf = (string)'';
		$pz = '';

		if ( $v == 0 ) {
			return $pz . '0';
		}

		for ($i=0; $v>0; $i++, $v>>=6) {
			$zBuf[$i] = $zDigits[$v&0x3f];
		}

		for ($j = $i-1; $j>=0; $j--) {
			$pz .= $zBuf[$j];
		}

		return $pz;
	}

	/*
	** Read bytes from *pz and convert them into a positive integer.  When
	** finished, leave *pz pointing to the first character past the end of
	** the integer.  The *pLen parameter holds the length of the string
	** in *pz and is decremented once for each character in the integer.
	*/
	protected function getInt(&$pz, &$pLen)
	{
		$zValue = array(
			-1, -1, -1, -1, -1, -1, -1, -1,   -1, -1, -1, -1, -1, -1, -1, -1,
			-1, -1, -1, -1, -1, -1, -1, -1,   -1, -1, -1, -1, -1, -1, -1, -1,
			-1, -1, -1, -1, -1, -1, -1, -1,   -1, -1, -1, -1, -1, -1, -1, -1,
	  		 0,  1,  2,  3,  4,  5,  6,  7,    8,  9, -1, -1, -1, -1, -1, -1,
			-1, 10, 11, 12, 13, 14, 15, 16,   17, 18, 19, 20, 21, 22, 23, 24,
			25, 26, 27, 28, 29, 30, 31, 32,   33, 34, 35, -1, -1, -1, -1, 36,
			-1, 37, 38, 39, 40, 41, 42, 43,   44, 45, 46, 47, 48, 49, 50, 51,
			52, 53, 54, 55, 56, 57, 58, 59,   60, 61, 62, -1, -1, -1, 63, -1,
		);

		foreach ($zValue as &$row)
		{
			$row = $this->u32($row);
		}

		$v = 0;
		$z = 0;

		while ( ($c = $zValue[0x7f&ord($pz[$z++])]) != $this->u32(-1) ) {
			$v = ($v<<6) + $c;
		}

		$z--;
		$pz = substr($pz, $z);
		$pLen = strlen($pz);
		return $v;
	}

	/*
	** Return the number digits in the base-64 representation of a positive integer
	*/
	protected function digit_count($v)
	{
		for($i=1, $x=64; $v >= $x; $i++, $x <<= 6){}
		return $i;
	}

	/*
	** Compute a 32-bit checksum on the N-byte buffer.  Return the result.
	*/
	protected function checksum($z, $N)
	{
		$sum0 = $sum1 = $sum2 = $sum3 = (float)0.0;

		while ($N >= 16)
		{
			$sum0 += (ord($z[0]) + ord($z[4]) + ord($z[8]) + ord($z[12]));
			$sum1 += (ord($z[1]) + ord($z[5]) + ord($z[9]) + ord($z[13]));
			$sum2 += (ord($z[2]) + ord($z[6]) + ord($z[10]) + ord($z[14]));
			$sum3 += (ord($z[3]) + ord($z[7]) + ord($z[11]) + ord($z[15]));
			$z = substr($z, 16);
			$N -= 16;
		}

		while ($N >= 4) {
			$sum0 += ord($z[0]);
			$sum1 += ord($z[1]);
			$sum2 += ord($z[2]);
			$sum3 += ord($z[3]);
			$z = substr($z, 4);
			$N -= 4;
		}

		$sum3 += $this->u32($sum2 << 8) + $this->u32($sum1 << 16) + $this->u32($sum0 << 24);

		switch ($N) {
			case 3:   $sum3 += $this->u32(ord($z[2]) << 8);
			case 2:   $sum3 += $this->u32(ord($z[1]) << 16);
			case 1:   $sum3 += $this->u32(ord($z[0]) << 24);
			default:  ;
		}

		$sum3 = $this->u32($sum3);
		return sprintf('%u', $sum3);
	}

	/*
	** Create a new delta.
	**
	** The delta is written into a preallocated buffer, zDelta, which
	** should be at least 60 bytes longer than the target file, zOut.
	** The delta string will be NUL-terminated, but it might also contain
	** embedded NUL characters if either the zSrc or zOut files are
	** binary.  This function returns the length of the delta string
	** in bytes, excluding the final NUL terminator character.
	**
	** Output Format:
	**
	** The delta begins with a base64 number followed by a newline.  This
	** number is the number of bytes in the TARGET file.  Thus, given a
	** delta file z, a program can compute the size of the output file
	** simply by reading the first line and decoding the base-64 number
	** found there.  The delta_output_size() routine does exactly this.
	**
	** After the initial size number, the delta consists of a series of
	** literal text segments and commands to copy from the SOURCE file.
	** A copy command looks like this:
	**
	**     NNN@MMM,
	**
	** where NNN is the number of bytes to be copied and MMM is the offset
	** into the source file of the first byte (both base-64).   If NNN is 0
	** it means copy the rest of the input file.  Literal text is like this:
	**
	**     NNN:TTTTT
	**
	** where NNN is the number of bytes of text (base-64) and TTTTT is the text.
	**
	** The last term is of the form
	**
	**     NNN;
	**
	** In this case, NNN is a 32-bit bigendian checksum of the output file
	** that can be used to verify that the delta applied correctly.  All
	** numbers are in base-64.
	**
	** Pure text files generate a pure text delta.  Binary files generate a
	** delta that may contain some binary data.
	**
	** Algorithm:
	**
	** The encoder first builds a hash table to help it find matching
	** patterns in the source file.  16-byte chunks of the source file
	** sampled at evenly spaced intervals are used to populate the hash
	** table.
	**
	** Next we begin scanning the target file using a sliding 16-byte
	** window.  The hash of the 16-byte window in the target is used to
	** search for a matching section in the source file.  When a match
	** is found, a copy command is added to the delta.  An effort is
	** made to extend the matching section to regions that come before
	** and after the 16-byte hash window.  A copy command is only issued
	** if the result would use less space that just quoting the text
	** literally. Literal text is added to the delta for sections that
	** do not match or which can not be encoded efficiently using copy
	** commands.
	*/

	/**
	 * Computes the difference between $zSrc and $zOut and returns the delta
	 * @param  string $zSrc Binary content of source file
	 * @param  string $zOut Binary content of target file
	 * @return string 		Delta
	 */
	public function create($zSrc, $zOut)
	{
		$lenSrc = strlen($zSrc);
		$lenOut = strlen($zOut);

		$zDelta = '';

		$h = new Delta_Hash;
		$nHash = 0; /* Number of hash table entries */
		$landmark = 0;             /* Primary hash table */
		$collide = 0;              /* Collision chain */
		$lastRead = 0xffffffff; /* Last byte of zSrc read by a COPY command */

		/* Add the target file size to the beginning of the delta
		*/
		$zDelta .= $this->putInt($lenOut);
		$zDelta .= "\n";

		/* If the source file is very small, it means that we have no
		** chance of ever doing a copy command.  Just output a single
		** literal segment for the entire target and exit.
		*/
		if ($lenSrc <= self::NHASH)
		{
			$zDelta .= $this->putInt($lenOut);
			$zDelta .= ':';
			$zDelta .= substr($zOut, 0, $lenOut);
			$zDelta .= $this->putInt($this->checksum($zOut, $lenOut));
			$zDelta .= ';';
			return $zDelta;
		}

		/* Compute the hash table used to locate matching sections in the
		** source file.
		*/
		$nHash = (int) ($lenSrc / self::NHASH);
		$collide = array_fill(0, $nHash * 2 * PHP_INT_SIZE, $this->u32(-1));
		$landmark = array_slice($collide, $nHash);

		for ($i = 0; $i < $lenSrc - self::NHASH; $i += self::NHASH)
		{
			$this->hash_init($h, substr($zSrc, $i, self::NHASH));
			$hv = $this->hash_32bit($h) % $nHash;
			$collide[$i / self::NHASH] = $landmark[$hv];
			$landmark[$hv] = $i / self::NHASH;
		}

		/* Begin scanning the target file and generating copy commands and
		** literal sections of the delta.
		*/
		$base = 0;    /* We have already generated everything before zOut[base] */
		while ($base + self::NHASH < $lenOut)
		{
			$bestOfst = 0;
			$bestLitsz = 0;

			$this->hash_init($h, substr($zOut, $base, self::NHASH));

			$i = 0;     /* Trying to match a landmark against zOut[base+i] */
			$bestCnt = 0;

			while (1)
			{
				$limit = 250;

				$hv = $this->hash_32bit($h) % $nHash;

				if ($this->debug_enabled) {
					$this->debug(sprintf("LOOKING: %4d [%s]", $base+$i, substr($zOut, $base + $i, 16)));
				}

				$iBlock = $landmark[$hv];

				while ($iBlock != $this->u32(-1) && $iBlock >= 0 && ($limit--) > 0)
				{
					/*
					** The hash window has identified a potential match against
					** landmark block iBlock.  But we need to investigate further.
					**
					** Look for a region in zOut that matches zSrc. Anchor the search
					** at zSrc[iSrc] and zOut[base+i].  Do not include anything prior to
					** zOut[base] or after zOut[outLen] nor anything after zSrc[srcLen].
					**
					** Set cnt equal to the length of the match and set ofst so that
					** zSrc[ofst] is the first element of the match.  litsz is the number
					** of characters between zOut[base] and the beginning of the match.
					** sz will be the overhead (in bytes) needed to encode the copy
					** command.  Only generate copy command if the overhead of the
					** copy command is less than the amount of literal text to be copied.
					*/

					/* Beginning at iSrc, match forwards as far as we can.  j counts
					** the number of characters that match */
					$iSrc = $iBlock * self::NHASH;

					for($j = 0, $x = $iSrc, $y = $base + $i; $x < $lenSrc && $y < $lenOut; $j++, $x++, $y++)
					{
						if ($zSrc[$x] != $zOut[$y])
						{
							break;
						}
					}

					$j--;

					/* Beginning at iSrc-1, match backwards as far as we can.  k counts
					** the number of characters that match */
					for ($k = 1; $k < $iSrc && $k <= $i; $k++)
					{
						if ($zSrc[$iSrc - $k] != $zOut[$base + $i - $k])
							break;
					}

					$k--;

					/* Compute the offset and size of the matching region */
					$ofst = $iSrc - $k;
					$cnt = $j + $k + 1;
					$litsz = $i - $k;  /* Number of bytes of literal text before the copy */

					if ($this->debug_enabled) {
						$this->debug(sprintf("MATCH %d bytes at %d: [%s] litsz=%d", $cnt, $ofst, substr($zSrc, $ofst, 16), $litsz));
					}

					/* sz will hold the number of bytes needed to encode the "insert"
					** command and the copy command, not counting the "insert" text */
					$sz = $this->digit_count($i - $k) + $this->digit_count($cnt) + $this->digit_count($ofst) + 3;

					if ($cnt >= $sz && $cnt > $bestCnt )
					{
						/* Remember this match only if it is the best so far and it
						** does not increase the file size */
						$bestCnt = $cnt;
						$bestOfst = $iSrc - $k;
						$bestLitsz = $litsz;

						if ($this->debug_enabled) {
							$this->debug(sprintf("... BEST SO FAR"));
						}
					}

					/* Check the next matching block */
					$iBlock = $collide[$iBlock];
				}

				/* We have a copy command that does not cause the delta to be larger
				** than a literal insert.  So add the copy command to the delta.
				*/
				if ($bestCnt > 0)
				{
					if ($bestLitsz > 0)
					{
						/* Add an insert command before the copy */
						$zDelta .= $this->putInt($bestLitsz);
						$zDelta .= ':';
						$zDelta .= substr($zOut, $base, $bestLitsz);
						$base += $bestLitsz;

						if ($this->debug_enabled) {
							$this->debug(sprintf("insert %d", $bestLitsz));
						}
					}

					$base += $bestCnt;
					$zDelta .= $this->putInt($bestCnt);
					$zDelta .= '@';
					$zDelta .= $this->putInt($bestOfst);

					if ($this->debug_enabled) {
						$this->debug(sprintf("copy %d bytes from %d", $bestCnt, $bestOfst));
					}

					$zDelta .= ',';

					if ($bestOfst + $bestCnt - 1 > $lastRead)
					{
						$lastRead = $bestOfst + $bestCnt - 1;

						if ($this->debug_enabled) {
							$this->debug(sprintf("lastRead becomes %d", $lastRead));
						}
					}

					$bestCnt = 0;
					break;
				}

				/* If we reach this point, it means no match is found so far */
				if ($base + $i + self::NHASH >= $lenOut)
				{
					/* We have reached the end of the file and have not found any
					** matches.  Do an "insert" for everything that does not match */
					$zDelta .= $this->putInt($lenOut - $base);
					$zDelta .= ':';
					$zDelta .= substr($zOut, $base, $lenOut - $base);
					$base = $lenOut;
					break;
				}

				/* Advance the hash by one character.  Keep looking for a match */
				$this->hash_next($h, ord($zOut[$base + $i + self::NHASH]));
				$i++;
			}
		}

		/* Output a final "insert" record to get all the text at the end of
		** the file that does not match anything in the source file.
		*/
		if ($base < $lenOut)
		{
			$zDelta .= $this->putInt($lenOut - $base);
			$zDelta .= ':';
			$zDelta .= substr($zOut, $base, $lenOut - $base);
		}

		/* Output the final checksum record. */
		$zDelta .= $this->putInt($this->checksum($zOut, $lenOut));
		$zDelta .= ';';
		unset($collide);

		return $zDelta;
	}

	/*
	** Return the size (in bytes) of the output from applying
	** a delta.
	**
	** This routine is provided so that an procedure that is able
	** to call delta_apply() can learn how much space is required
	** for the output and hence allocate nor more space that is really
	** needed.
	*/
	public function outputSize($zDelta)
	{
		$lenDelta = strlen($zDelta);
		$size = $this->getInt($zDelta, $lenDelta);

		if (substr($zDelta, 0, 1) != "\n")
		{
			/* ERROR: size integer not terminated by "\n" */
			return -1;
		}

		return $size;
	}


	/*
	** Apply a delta.
	**
	** The output buffer should be big enough to hold the whole output
	** file and a NUL terminator at the end.  The delta_output_size()
	** routine will determine this size for you.
	**
	** The delta string should be null-terminated.  But the delta string
	** may contain embedded NUL characters (if the input and output are
	** binary files) so we also have to pass in the length of the delta in
	** the lenDelta parameter.
	**
	** This function returns the size of the output file in bytes (excluding
	** the final NUL terminator character).  Except, if the delta string is
	** malformed or intended for use with a source file other than zSrc,
	** then this routine returns -1.
	**
	** Refer to the delta_create() documentation above for a description
	** of the delta file format.
	*/
	public function apply($zSrc, $zDelta)
	{
		$zOut = '';
		$lenSrc = strlen($zSrc);
		$lenDelta = strlen($zDelta);

		$total = 0;
		$zOut;

		$limit = $this->getInt($zDelta, $lenDelta);

		if (substr($zDelta, 0, 1) != "\n")
		{
			throw new Delta_Exception('size integer not terminated by "\n"');
		}

		$zDelta = substr($zDelta, 1);
		$lenDelta--;

		while ($zDelta != '' && $lenDelta > 0)
		{
			$cnt = $this->getInt($zDelta, $lenDelta);

			switch ($zDelta[0])
			{
				case '@':
				{
					$zDelta = substr($zDelta, 1);
					$lenDelta--;

					$ofst = $this->getInt($zDelta, $lenDelta);

					if ($lenDelta > 0 && $zDelta[0] != ',' )
					{
						throw new Delta_Exception("copy command not terminated by ','");
					}

					$zDelta = substr($zDelta, 1);
					$lenDelta--;

					if ($this->debug_enabled) {
						$this->debug(sprintf("COPY %d from %d\n", $cnt, $ofst));
					}

					$total += $cnt;

					if ($total > $limit)
					{
						throw new Delta_Exception('copy exceeds output file size');
					}

					if ($ofst + $cnt > $lenSrc)
					{
						throw new Delta_Exception('copy extends past end of input');
					}

					$zOut .= substr($zSrc, $ofst, $cnt);
					break;
				}
				case ':':
				{
					$zDelta = substr($zDelta, 1);
					$lenDelta--;
					$total += $cnt;

					if ($total > $limit)
					{
						throw new Delta_Exception('insert command gives an output larger than predicted');
					}

					if ($this->debug_enabled) {
						$this->debug(sprintf("INSERT %d\n", $cnt));
					}

					if ($cnt > $lenDelta)
					{
						throw new Delta_Exception('insert count exceeds size of delta');
					}

					$zOut .= substr($zDelta, 0, $cnt);
					$zDelta = substr($zDelta, $cnt);
					$lenDelta -= $cnt;
					break;
				}
				case ';':
				{
					$zDelta = substr($zDelta, 1);
					$lenDelta--;

					if ($cnt != ($ck = $this->checksum($zOut, $total)))
					{
						throw new Delta_Exception('bad checksum: '.sprintf("%u", $ck));
					}

					if ($total != $limit)
					{
						throw new Delta_Exception('generated size does not match predicted size');
					}

					return $zOut;
				}
				default:
				{
					throw new Delta_Exception('unknown delta operator: ' . sprintf("'%s'", $zDelta[0]));
				}
			}
		}

		throw new Delta_Exception('unterminated delta');
	}
}