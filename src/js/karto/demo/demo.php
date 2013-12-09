<?php

$maps = [
	'tasmania'	=>	[
		'a'	=>	['name' => 'Devonport', 'lat' => -41.178353, 'lon' => 146.360953],
		'b'	=>	['name' => 'Hobart', 'lat' => -42.881903, 'lon' => 147.323814],
		'points'	=>	[
			['name' => 'Launceston', 'lat' => -41.42646, 'lon' => 147.11368],
			['name' => 'Strahan', 'lat' => -42.153477, 'lon' => 145.328124],
		],
		'width'		=>	829,
		'height'	=>	878,
	],
	'cobb'	=>	[
		'a'	=>	['name' => 'Cobb Hut', 'lat' => -41.0559274, 'lon' => 172.5250582],
		'b'	=>	['name' => 'Fenella Hut', 'lat' => -41.04960256, 'lon' => 172.5251969],
		'points'	=>	[
			['name' => 'Lake Cobb', 'lat' => -41.05596718, 'lon' => 172.513754],
			['name' => 'Mt Cobb', 'lat' => -41.06610878, 'lon' => 172.5014913],
			['name' => 'Tent Camp', 'lat' => -41.066173, 'lon' => 172.536339],
		],
		'width'		=>	723,
		'height'	=>	631,
	],
	'australia'	=>	[
		'a'	=>	['name' => 'Perth', 'lat' => -31.953004, 'lon' => 115.857469],
		'b'	=>	['name' => 'Brisbane', 'lat' => -27.471011, 'lon' => 153.023449],
		'points'	=>	[
			['name' => 'Adelaide', 'lat' => -34.928621, 'lon' => 138.599959],
			['name' => 'Darwin', 'lat' => -12.462827, 'lon' => 130.841777],
		],
		'width'		=>	576,
		'height'	=>	564,
	],
	'ign'	=>	[
		'a'	=>	['name' => 'Autun', 'lat' => 46.950914, 'lon' => 4.301565],
		'b'	=>	['name' => 'Ancy le Franc', 'lat' => 47.774047, 'lon' => 4.165437],
		'points'	=>	[
			['name' => 'Dijon', 'lat' => 47.322047, 'lon' => 5.041480],
			['name' => 'Chalon-sur-Saône', 'lat' => 46.780764, 'lon' => 4.853947],
		],
		'width'		=>	671,
		'height'	=>	705,
	],
];

if (isset ($_GET['map']) && array_key_exists($_GET['map'], $maps))
{
	$map = $maps[$_GET['map']];
}
else
{
	$map = false;
}

?><!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8" />
	<title>Karto</title>
	<style type="text/css">
	<?php if ($map): ?>
	#map {
		position: relative;
		float: left;
		margin-right: 1em;
		background: url("map_<?=$_GET['map']?>.jpg") no-repeat 0px 0px;
		width: <?=$map['width']?>px;
		height: <?=$map['height']?>px;
	}
	<?php endif; ?>
	#map span {
		background: #fff;
		border-radius: 10%;
		box-shadow: 2px 2px 2px #000;
		color: #000;
		font-family: sans-serif;
		position: absolute;
		margin-top: -20px;
		margin-left: -10px;
		padding: 3px;
		line-height: 14px;
		font-size: 12px;
	}

	#map span:after {
		content: "";
		position: absolute;
		bottom: -10px; /* value = - border-top-width - border-bottom-width */
		left: 10px; /* controls horizontal position */
		border-width: 10px 0 0 10px; /* vary these values to change the angle of the vertex */
		border-style: solid;
		border-color: #fff transparent; 
	    /* reduce the damage in FF3.0 */
	    display: block; 
	    width: 0;
   	}
	</style>
</head>

<body>
<?php if (!$map): ?>
<h1>Choose a map:</h1>
<ul>
	<li><a href="?map=tasmania">Tasmania</a> (large, satellite, from Google Maps)</li>
	<li><a href="?map=cobb">Cobb Valley (New-Zealand)</a> (small scale, topographic, from LINZ)</li>
	<li><a href="?map=australia">Australia</a> (large scale, HERE.com)</li>
	<li><a href="?map=ign">Burgundy</a> (medium scale, scanned map)</li>
</ul>
<?php else: ?>

<h1><?=$_GET['map']?></h1>

<div id="map"></div>

<dl>
	<dt><?=$map['a']['name']?></dt>
	<dd>GPS: <?=$map['a']['lat']?> <?=$map['a']['lon']?></dd>
	<dd id="coords_a">Click on this point on the map.</dd>
	<dt><?=$map['b']['name']?></dt>
	<dd>GPS: <?=$map['b']['lat']?> <?=$map['b']['lon']?></dd>
	<dd id="coords_b"></dd>
</dl>

<script type="text/javascript" src="../lib.karto.js"></script>
<script type="text/javascript">
var map = <?=json_encode($map)?>;

HTMLElement.prototype.relMouseCoords = function (event) {
    var totalOffsetX = 0;
    var totalOffsetY = 0;
    var canvasX = 0;
    var canvasY = 0;
    var currentElement = this;

    do{
        totalOffsetX += currentElement.offsetLeft - currentElement.scrollLeft;
        totalOffsetY += currentElement.offsetTop - currentElement.scrollTop;
    }
    while(currentElement = currentElement.offsetParent)

	canvasX = event.pageX - totalOffsetX - document.body.scrollLeft;
	canvasY = event.pageY - totalOffsetY - document.body.scrollTop;

    return {x:canvasX, y:canvasY}
};

var karto = new karto;

function addMarker(x, y, name)
{
	var e = document.createElement('span');
	e.innerHTML = name;
	e.style.left = (x - 8) + 'px';
	e.style.top = (y - 8) + 'px';
	document.getElementById("map").appendChild(e);
}

document.getElementById("map").onclick = function (e) {
	if (map.b.x && map.a.x)
		return;

	if (map.a.x)
	{
		var pos = e.target.relMouseCoords(e);
		map.b.x = pos.x;
		map.b.y = pos.y;

		document.getElementById("coords_b").innerHTML = 'x = ' + map.b.x + ' / y = ' + map.b.y;
		addMarker(map.b.x, map.b.y, map.b.name);

		var bbox = karto.getStaticMapBoundingBoxFromTwoPoints(map.a, map.b, map.width, map.height);
		//console.log(bbox);

		for (var i = 0; i < map.points.length; i++)
		{
			var point = karto.getPointXYOnStaticMap(map.points[i].lat, map.points[i].lon, bbox);
			addMarker(point.x, point.y, map.points[i].name);
		}

		e.preventDefault();
		return false;
	}
	else
	{
		var pos = e.target.relMouseCoords(e);
		map.a.x = pos.x;
		map.a.y = pos.y;

		document.getElementById("coords_b").innerHTML = document.getElementById("coords_a").innerHTML;
		document.getElementById("coords_a").innerHTML = 'x = ' + map.a.x + ' / y = ' + map.a.y;

		addMarker(map.a.x, map.a.y, map.a.name);

		e.preventDefault();
		return false;
	}
};
</script>
<?php endif; ?>
</body>
</html>