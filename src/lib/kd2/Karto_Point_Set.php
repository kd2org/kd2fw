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
 * Copyleft (C) 2010-2013 BohwaZ
 */

namespace KD2;

use KD2\Karto_Point;

class Karto_Point_Set
{
	protected $points = [];

	public function __construct($points)
	{
		foreach ($points as $point)
		{
			$this->add($point);
		}
	}

	public function add($point)
	{
		$this->points[] = new Karto_Point($point);
	}

	public function getPoints()
	{
		return $this->points;
	}

	public function count()
	{
		return count($this->points);
	}

	public function getBBox()
	{
		if (count($this->points) < 1)
			throw new \OutOfRangeException('Empty point set');

		$bbox = new \stdClass;

		$point = $this->points[0];

		$bbox->minLat = $bbox->maxLat = $point->lat;
		$bbox->minLon = $bbox->maxLon = $point->lon;

		foreach ($this->points as $point)
		{
			$bbox->minLat = min($bbox->minLat, $point->lat);
			$bbox->maxLat = max($bbox->maxLat, $point->lat);
			$bbox->minLon = min($bbox->minLon, $point->lon);
			$bbox->maxLon = max($bbox->maxLon, $point->lon);
		}

		$bbox->northEast = new Karto_Point($bbox->maxLat, $bbox->minLon);
		$bbox->southWest = new Karto_Point($bbox->minLat, $bbox->maxLon);
		
		return $bbox;
	}

	/**
	 * Calculate average latitude and longitude of supplied array of points
	 * @param  array  $points Each array item MUST have at least two keys named 'lat' and 'lon'
	 * @return object (obj)->lat = x.xxxx, ->lon => y.yyyy
	 */
	public function getCenter()
	{
		if (count($this->points) < 1)
			throw new \OutOfRangeException('Empty point set');
		
		/* Calculate average lat and lon of points. */
		$lat_sum = $lon_sum = 0;
		foreach ($this->points as $point)
		{
		   $lat_sum += $point->lat;
		   $lon_sum += $point->lon;
		}

		$lat_avg = $lat_sum / count($this->points);
		$lon_avg = $lon_sum / count($this->points);
		
		return new Karto_Point($lat_avg, $lon_avg);
	}


	/**
	 * Cluster points to avoid having too much points
	 * @param  integer $distance Maximum distance between two points to cluster them
	 * @param  integer $zoom Map zoom level
	 * @return array Each row will contain a key named 'points' containing all the points in the cluster and
	 * a key named 'center' containing the center coordinates of the cluster.
	 */
	public function cluster($distance, $zoom) 
	{
		$clustered = [];
		$points = $this->points;

		/* Loop until all points have been compared. */
		while (count($points))
		{
			$point  = array_pop($points);
			$cluster = new Karto_Point_Set;

			/* Compare against all points which are left. */
			foreach ($points as $key => $target)
			{
				$pixels = $point->pixelDistanceTo($target, $zoom);

				/* If two points are closer than given distance remove */
				/* target point from array and add it to cluster.      */
				if ($distance > $pixels)
				{
					unset($points[$key]);
					$cluster->add($target);
				}
			}

			/* If a point has been added to cluster, add also the one  */
			/* we were comparing to and remove the original from array. */
			if (count($cluster) > 0)
			{
				$cluster->add($point);
				$clustered[] = $cluster;
			}
			else
			{
				$clustered[] = $point;
			}
		}

		return $clustered;
	}

	/**
	 * Decode a polyline into a set of coordinates
	 * @link   https://developers.google.com/maps/documentation/utilities/polylinealgorithm Polyline algorithm
	 * @param  string $line   Polyline encoded string
	 * @return array          A list of lat/lon tuples
	 */
	static public function fromPolyline($line)
	{
		$precision = 5;
		$index = $i = 0;
		$points = [];
		$previous = [0,0];

		while ($i < strlen($line))
		{
			$shift = $result = 0x00;

			do {
				$bit = ord(substr($line, $i++)) - 63;
				$result |= ($bit & 0x1f) << $shift;
				$shift += 5;
			} while ($bit >= 0x20);

			$diff = ($result & 1) ? ~($result >> 1) : ($result >> 1);
			$number = $previous[$index % 2] + $diff;
			$previous[$index % 2] = $number;
			$index++;

			$points[] = $number * 1 / pow(10, $precision);
		}
		
		return new Karto_Point_Set(array_chunk($points, 2));
	}

	/**
	 * Returns mercator suitable zoom level according to the set of points
	 * @param float $padding Extra padding around the bounding box (< 1 = reduce padding, 1 = no padding, > 1 = add padding)
	 * @return integer Zoom level between 1 and 21
	 */
	public function getZoomLevel($padding = 1)
	{
		$bbox = $this->getBBox();
		$latDiff = ($bbox->maxLat - $bbox->minLat) * $padding;
		$lngDiff = ($bbox->maxLon - $bbox->minLon) * $padding;

		$maxDiff = ($lngDiff > $latDiff) ? $lngDiff : $latDiff;
		
		if ($maxDiff < 360 / pow(2, 20))
		{
			$zoomLevel = 21;
		}
		else
		{
			$zoomLevel = (int) (-1*( (log($maxDiff)/log(2)) - (log(360)/log(2))));
	
			if ($zoomLevel < 1)
				$zoomLevel = 1;
		}

		return $zoomLevel;
	}
}