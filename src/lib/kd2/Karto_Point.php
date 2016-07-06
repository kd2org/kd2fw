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

/**
 * Karto: an independent PHP library providing basic mapping tools
 *
 * Copyleft (C) 2010-2016 BohwaZ
 */

namespace KD2;

class Karto_Point implements \ArrayAccess
{
	const PIXELS_OFFSET = 268435456;
	// You might wonder where did number 268435456 come from? 
	// It is half of the earth circumference in pixels at zoom level 21. 
	// You can visualize it by thinking of full map. 
	// Full map size is 536870912 × 536870912 pixels. 
	// Center of the map in pixel coordinates is 268435456,268435456 
	// which in latitude and longitude would be 0,0.
	const PIXELS_RADIUS = 85445659.4471; /* PIXELS_OFFSET / pi() */

	protected $lat = null;
	protected $lon = null;

	public function __construct($lat = null, $lon = null)
	{
		$self = __CLASS__;

		if (is_object($lat) && $lat instanceof $self)
		{
			$this->lat = $lat->lat;
			$this->lon = $lat->lon;
		}
		elseif (is_array($lat) && is_null($lon) && array_key_exists('lat', $lat))
		{
			$this->lat = $lat['lat'];
			$this->lon = $lat['lon'];
		}
		elseif (is_array($lat) && is_null($lon) && array_key_exists(1, $lat))
		{
			$this->lat = $lat[0];
			$this->lon = $lat[1];
		}
		else
		{
			if (!is_null($lat))
				$this->lat = $lat;
			
			if (!is_null($lon))
				$this->lon = $lon;
		}
	}

	public function __toString()
	{
		return $this->lat . ',' . $this->lon;
	}

	public function __set($key, $value)
	{
		if ($key == 'lng')
			$key = 'lon';

		if (!property_exists($this, $key))
		{
			throw new \InvalidArgumentException('Unknown property ' . $key);
		}

		if (is_null($value))
		{
			$this->$key = null;
			return;
		}

		if ($key == 'lat' && ($value < -85.0511 || $value > 85.0511))
		{
			throw new \InvalidArgumentException('Invalid latitude (must be between -85.0511 and 85.0511)');
		}
		
		if ($key == 'lon' && ($value < -180 || $value > 180))
		{
			throw new \InvalidArgumentException('Invalid latitude (must be between -180 and 180)');
		}
		
		$this->$key = (double) $value;
	}

	public function __get($key)
	{
		if ($key == 'lng')
			$key = 'lon';

		if (!property_exists($this, $key))
		{
			throw new \InvalidArgumentException('Unknown property ' . $key);
		}
		
		return $this->$key;
	}

	public function offsetSet($key, $value)
	{
		$this->__set($key, $value);
	}

	public function offsetExists($offset) {
		return isset($this->$offset);
	}

	public function offsetUnset($offset) {
		$this->__set($offset, null);
	}

	public function offsetGet($offset) {
		return $this->__get($offset);
	}

	/**
	 * Gets distance between this point and another
	 * @param  object Karto_Point $point Distant point
	 * @return double Distance in KM
	 */
	public function distanceTo(Karto_Point $point)
	{
		$lat1 = $this->lat;
		$lon1 = $this->lon;
		$lat2 = $point->lat;
		$lon2 = $point->lon;

		// convert lat1 and lat2 into radians now, to avoid doing it twice below
		$lat1rad = deg2rad($lat1);
		$lat2rad = deg2rad($lat2);
		
		// apply the spherical law of cosines to our latitudes and longitudes, and set the result appropriately
		return (acos(sin($lat1rad) * sin($lat2rad) + cos($lat1rad) * cos($lat2rad) * cos(deg2rad($lon2) - deg2rad($lon1))) * 6371);
	}

	/**
	 * Distance in pixels between two points at a specific zoom level
	 * @param  float $lat1 Latitude of starting point (decimal)
	 * @param  float $lon1 Longitude of starting point (decimal)
	 * @param  float $lat2 Latitude of destination (decimal)
	 * @param  float $lon2 Longitude of destination (decimal)
	 * @param  integer $zoom Zoom level
	 * @return int Distance in pixels
	 */
	public function pixelDistanceTo(Karto_Point $point, $zoom)
	{
		list($x1, $y1) = $this->XY();
		list($x2, $y2) = $point->XY();
			
		return sqrt(pow(($x1-$x2),2) + pow(($y1-$y2),2)) >> (21 - $zoom);
	}

	/**
	 * Get position in pixels for map
	 * @return array [int X, int Y] position on map in pixels
	 */
	public function XY()
	{
		$x = round(self::PIXELS_OFFSET + self::PIXELS_RADIUS * $this->lon * pi() / 180);
		$y = round(self::PIXELS_OFFSET - self::PIXELS_RADIUS * 
					log((1 + sin($this->lat * pi() / 180)) / 
					(1 - sin($this->lat * pi() / 180))) / 2);
		return [$x, $y];
	}


	/**
	 * Converts a latitude and longitude from decimal to DMS notation
	 * @return array Latitude / Longitude in DMS notation, eg. [45 5 56 S, 174 11 37 E]
	 */
	static function toDMS()
	{
		$convert = function ($dec)
		{
			$dec = explode('.', $dec);
			if (count($dec) != 2) return [0, 0, 0];
			$m = $dec[1];
			$h = $dec[0];
			$m = round('0.' . $m, 6) * 3600;
			$min = floor($m / 60);
			$sec = round($m - ($min*60));
			return [$h, $min, $sec];
		};

		$lat = $convert($this->lat);
		$lat = abs($lat[0]) . ' ' . abs($lat[1]) . ' ' . abs($lat[2]) . ' ' . ($lat[0] > 0 ? 'N' : 'S');
		
		$lon = $convert($this->lon);
		$lon = abs($lon[0]) . ' ' . abs($lon[1]) . ' ' . abs($lon[2]) . ' ' . ($lon[0] > 0 ? 'E' : 'W');

		return [$lat, $lon];
	}

	/**
	 * Is this latitude/longitude contained inside the given set bounds?
	 * @param  object  Karto_Point_Set $set Set of points
	 * @return boolean             true if point is inside the boundaries, false if it is outside
	 */
	public function isContainedIn(Karto_Point_Set $set)
	{
		$bbox = $set->getBBox();

		if ($this->lon >= $bbox->minLon
			&& $this->lon <= $bbox->maxLon
			&& $this->lat >= $bbox->minLat
			&& $this->lat <= $bbox->maxLat)
		{
			return true;
		}

		return false;
	}

}