(function () {
	window.karto = function ()
	{
    	this.PIXELS_OFFSET = 268435456;
	    this.PIXELS_RADIUS = 85445659.4471; /* PIXELS_OFFSET / pi() */
    	this.EARTH_RADIUS = 6371.0; // Average radius of earth (in kilometers)
    	// FIXME: or 6378.1 km?
    	var that = this;
	};

	karto.prototype.deg2rad = function (angle) {
		return angle * .017453292519943295; // (angle / 180) * Math.PI;
	};

	karto.prototype.haversineDistance = function (lat1, lon1, lat2, lon2) 
    {
        var latd = this.deg2rad(lat2 - lat1);
        var lond = this.deg2rad(lon2 - lon1);
        var a = Math.sin(latd / 2) * Math.sin(latd / 2) +
             Math.cos(this.deg2rad(lat1)) * Math.cos(this.deg2rad(lat2)) *
             Math.sin(lond / 2) * Math.sin(lond / 2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return this.EARTH_RADIUS * c; // average radius of earth
    };

    karto.prototype.lonToX = function (lon)
    {
        return Math.round(this.PIXELS_OFFSET + this.PIXELS_RADIUS * lon * Math.PI / 180);        
    };

    karto.prototype.latToY = function (lat)
    {
        return Math.round(this.PIXELS_OFFSET - this.PIXELS_RADIUS * 
                    Math.log((1 + Math.sin(lat * Math.PI / 180)) / 
                    (1 - Math.sin(lat * Math.PI / 180))) / 2);
    };

    karto.prototype.XToLon = function (x) {
        return ((Math.round(x) - this.PIXELS_OFFSET) / this.PIXELS_RADIUS) * 180 / Math.PI;
    }

    karto.prototype.YToLat = function (y) {
        return (Math.PI / 2 - 2 * Math.atan(Math.exp((Math.round(y) - this.PIXELS_OFFSET) / this.PIXELS_RADIUS))) * 180 / Math.PI;
    }

    karto.prototype.pixelDistanceFromLatLon = function (lat1, lon1, lat2, lon2, zoom)
    {
        x1 = this.lonToX(lon1);
        y1 = this.latToY(lat1);

        x2 = this.lonToX(lon2);
        y2 = this.latToY(lat2);
            
        return this.pixelDistance(x1, y1, x2, y2) >> (21 - zoom);
    };

    karto.prototype.pixelDistance = function (x1, y1, x2, y2)
    {
    	return Math.sqrt(Math.pow(y1 - y2, 2) + Math.pow(x1 - x2, 2))
    };

    karto.prototype.getStaticMapBoundingBoxFromTwoPoints = function (point1, point2, width, height)
    {
		// Coordinates of the inner bounding box containing the two points
		var box = {};
		var map = {};

		// Top left, with lat/longitude as pixel coordinates on full-size Mercator projection
		box.tl = {
			lat: this.latToY(Math.max(point1.lat, point2.lat)), 
			lon: this.lonToX(Math.min(point1.lon, point2.lon)),
			x: Math.min(point1.x, point2.x), 
			y: Math.min(point1.y, point2.y)
		};

		// Bottom right, with lat/longitude as pixel coordinates on full-size Mercator projection
		box.br = {
			lat: this.latToY(Math.min(point1.lat, point2.lat)),
			lon: this.lonToX(Math.max(point1.lon, point2.lon)),
			x: Math.max(point1.x, point2.x),
			y: Math.max(point1.y, point2.y)
		};

		// Box width and height
		box.width = box.br.x - box.tl.x;
		box.height = box.br.y - box.tl.y;

		// Horizontal and vertical distance in pixels on full-size projection
		var v_delta = box.br.lat - box.tl.lat;
		var h_delta = box.br.lon - box.tl.lon;

		// Get scale from the distance applied to map size
		map.v_scale = v_delta / box.height;
		map.h_scale = h_delta / box.width;

		// Get map top left
		map.topLeft = {
			x: box.tl.lon - (box.tl.x * map.h_scale),
			y: box.tl.lat - (box.tl.y * map.v_scale)
		};

		map.topLeft.lat = this.YToLat(map.topLeft.y);
		map.topLeft.lon = this.XToLon(map.topLeft.x);

		map.bottomRight = {
			x: box.br.lon + ((box.width - box.br.x) * map.h_scale),
			y: box.br.lat + ((box.height - box.br.y) * map.v_scale)
		};
		map.bottomRight.lat = this.YToLat(map.bottomRight.y);
		map.bottomRight.lon = this.XToLon(map.bottomRight.x);

		return map;
    };

    karto.prototype.populateStaticMap = function (map)
    {
    	if (typeof map.topLeft.x == 'undefined')
    		map.topLeft.x = this.lonToX(map.topLeft.lon);
    	if (typeof map.topLeft.y == 'undefined')
    		map.topLeft.y = this.latToY(map.topLeft.lat);
    	if (typeof map.bottomRight.x == 'undefined')
    		map.bottomRight.x = this.lonToX(map.bottomRight.lon);
    	if (typeof map.bottomRight.y == 'undefined')
    		map.bottomRight.y = this.latToY(map.bottomRight.lat);

    	if (!map.h_scale || !map.v_scale)
    	{
			var v_delta = map.bottomRight.y - map.topLeft.y;
			var h_delta = map.bottomRight.x - map.topLeft.x;

			// Get scale from the distance applied to map size
			map.v_scale = v_delta / map.height;
			map.h_scale = h_delta / map.width;
    	}

    	return map;
    };

    karto.prototype.getPointXYOnStaticMap = function (lat, lon, map)
    {
		return {
			x: (this.lonToX(lon) - map.topLeft.x) / map.h_scale,
			y: (this.latToY(lat) - map.topLeft.y) / map.v_scale
		};
    };

    karto.prototype.getPointLatLongOnStaticMap = function (x, y, map)
    {
		return {
			lat: this.YToLat(map.topLeft.y + (y * map.v_scale)),
			lon: this.XToLon(map.topLeft.x + (x * map.h_scale))
		};
    };
} ());