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

// From http://www.appelsiini.net/2008/11/introduction-to-marker-clustering-with-google-maps

class Karto
{
    const PIXELS_OFFSET = 268435456;
    // You might wonder where did number 268435456 come from? 
    // It is half of the earth circumference in pixels at zoom level 21. 
    // You can visualize it by thinking of full map. 
    // Full map size is 536870912 × 536870912 pixels. 
    // Center of the map in pixel coordinates is 268435456,268435456 
    // which in latitude and longitude would be 0,0.
    const PIXELS_RADIUS = 85445659.4471; /* PIXELS_OFFSET / pi() */

    const RADIUS = 6371.0; // Average radius of earth (in kilometers)
    // FIXME: or 6378.1 km?

    /**
     * Distance from one point to another
     * @param  float $lat1 Latitude of starting point (decimal)
     * @param  float $lon1 Longitude of starting point (decimal)
     * @param  float $lat2 Latitude of destination (decimal)
     * @param  float $lon2 Longitude of destination (decimal)
     * @return float Distance in kilometers
     */
    public function haversineDistance($lat1, $lon1, $lat2, $lon2) 
    {
        $latd = deg2rad($lat2 - $lat1);
        $lond = deg2rad($lon2 - $lon1);
        $a = sin($latd / 2) * sin($latd / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lond / 2) * sin($lond / 2);
             $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return self::EARTH_RADIUS * $c; // average radius of earth
    }

    /**
     * Get horizontal position in pixels for map from longitude
     * @param  float $lon Longitude (decimal)
     * @return int Horizontal position on map in pixels
     */
    public function lonToX($lon)
    {
        return round(self::PIXELS_OFFSET + self::PIXELS_RADIUS * $lon * pi() / 180);        
    }

    /**
     * Get vertical position in pixels for map from latitude
     * @param  float $lat Latitude (decimal)
     * @return int Vertical position on map in pixels
     */
    public function latToY($lat)
    {
        return round(self::PIXELS_OFFSET - self::PIXELS_RADIUS * 
                    log((1 + sin($lat * pi() / 180)) / 
                    (1 - sin($lat * pi() / 180))) / 2);
    }

    /**
     * Is this latitude/longitude contained inside the given NW/SE bounds?
     * @param  double  $lat        Point latitude
     * @param  double  $lon        Point longitude
     * @param  array   $north_west NW boundary ['lat' => 0.0, 'lon' => 0.0]
     * @param  array   $south_east SW boundary
     * @return boolean             true if point is inside the boundaries, false if it is outside
     */
    public function isContainedInBounds($lat, $lon, $north_west, $south_east)
    {
        if ($lon < max($north_west['lon'], $south_east['lon'])
            && $lon > min($north_west['lon'], $south_east['lon'])
            && $lat < max($north_west['lat'], $south_east['lon'])
            && $lat > min($north_west['lat'], $south_east['lat']))
        {
            return true;
        }

        return false;
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
    public function pixelDistance($lat1, $lon1, $lat2, $lon2, $zoom)
    {
        $x1 = $this->lonToX($lon1);
        $y1 = $this->latToY($lat1);

        $x2 = $this->lonToX($lon2);
        $y2 = $this->latToY($lat2);
            
        return sqrt(pow(($x1-$x2),2) + pow(($y1-$y2),2)) >> (21 - $zoom);
    }

    /**
     * Cluster points to avoid having too much points
     * @param  array $points Array of points, each item MUST contain a 'lat' and 'lon' keys containing
     * latitude and longitude in decimal notation. All data from each item will be kept in resulting cluster array.
     * @param  integer $distance Maximum distance between two points to cluster them
     * @param  integer $zoom Map zoom level
     * @return array Each row will contain a key named 'points' containing all the points in the cluster and
     * a key named 'center' containing the center coordinates of the cluster.
     */
    public function cluster($points, $distance, $zoom) 
    {
        $clustered = [];

        /* Loop until all points have been compared. */
        while (count($points))
        {
            $point  = array_pop($points);
            $cluster = ['points' => [], 'center' => null];
            /* Compare against all points which are left. */
            foreach ($points as $key => $target)
            {
                $pixels = $this->pixelDistance($point['lat'], $point['lon'],
                                        $target['lat'], $target['lon'],
                                        $zoom);

                /* If two points are closer than given distance remove */
                /* target point from array and add it to cluster.      */
                if ($distance > $pixels)
                {
                    unset($points[$key]);
                    $cluster['points'][] = $target;
                }
            }

            /* If a point has been added to cluster, add also the one  */
            /* we were comparing to and remove the original from array. */
            if (count($cluster) > 0)
            {
                $cluster['points'][] = $point;

                $cluster['center'] = $this->calculateCenter($cluster['points']);

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
     * Calculate average latitude and longitude of supplied array of points
     * @param  array  $points Each array item MUST have at least two keys named 'lat' and 'lon'
     * @return array  ['lat' => x.xxxx, 'lon' => y.yyyy]
     */
    public function calculateCenter($points)
    {
        /* Calculate average lat and lon of points. */
        $lat_sum = $lon_sum = 0;
        foreach ($points as $point)
        {
           $lat_sum += $point['lat'];
           $lon_sum += $point['lon'];
        }

        $lat_avg = $lat_sum / count($points);
        $lon_avg = $lon_sum / count($points);
        
        return ['lat' => $lat_avg, 'lon' => $lon_avg];
    }

    // FIXME
    public function getBounds($lat, $lon, $width, $height, $zoom)
    {
        $delta_x  = round($width / 2);
        $delta_y  = round($height / 2);

        $north    = Google_Maps_Mercator::adjustLatByPixels($lat, $delta_y * -1, $zoom);
        $south    = Google_Maps_Mercator::adjustLatByPixels($lat, $delta_y, $zoom);
        $west     = Google_Maps_Mercator::adjustLonByPixels($lon, $delta_x * -1, $zoom);
        $east     = Google_Maps_Mercator::adjustLonByPixels($lon, $delta_x, $zoom);
        
        $north_west = new Google_Maps_Coordinate($north, $west);
        $north_east = new Google_Maps_Coordinate($north, $east);
        $south_west = new Google_Maps_Coordinate($south, $west);
        $south_east = new Google_Maps_Coordinate($south, $east);

        $this->setZoom($old_zoom);
        
        return new Google_Maps_Bounds(array($north_west, $south_east));       
    }

    /**
     * Gets distance between two points
     * @param  float $lat1 Latitude of starting point (decimal)
     * @param  float $lon1 Longitude of starting point (decimal)
     * @param  float $lat2 Latitude of destination (decimal)
     * @param  float $lon2 Longitude of destination (decimal)
     * @return [type]       [description]
     */
	public function distance($lat1, $lon1, $lat2, $lon2)
	{
		if (is_null($lat1) || is_null($lon1) || is_null($lat2) || is_null($lon2))
			return null;

		$lat1 = (double) $lat1;
		$lon1 = (double) $lon1;
		$lat2 = (double) $lat2;
		$lon2 = (double) $lon2;

		// convert lat1 and lat2 into radians now, to avoid doing it twice below
		$lat1rad = deg2rad($lat1);
		$lat2rad = deg2rad($lat2);
		
		// apply the spherical law of cosines to our latitudes and longitudes, and set the result appropriately
		return (acos(sin($lat1rad) * sin($lat2rad) + cos($lat1rad) * cos($lat2rad) * cos(deg2rad($lon2) - deg2rad($lon1))) * self::RADIUS);
	}

	/**
	 * Converts a latitude and longitude from decimal to DMS notation
	 * @param  float $lat Decimal latitude
	 * @param  float $lon Decimal longitude
	 * @return string Latitude / Longitude in DMS notation, eg. 45 5 56 S 174 11 37 E
	 */
	static function notationDecToDMS($lat, $lon)
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

		$lat = $convert($lat);
		$lat = abs($lat[0]) . ' ' . abs($lat[1]) . ' ' . abs($lat[2]) . ' ' . ($lat[0] > 0 ? 'N' : 'S');
		
		$lon = $convert($lon);
		$lon = abs($lon[0]) . ' ' . abs($lon[1]) . ' ' . abs($lon[2]) . ' ' . ($lon[0] > 0 ? 'E' : 'W');

	    return $lat . ' ' . $lon;
	}
}