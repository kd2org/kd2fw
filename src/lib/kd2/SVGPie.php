<?php
/*
	Copyleft (C) 2011-2015 BohwaZ <http://bohwaz.net/>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, version 3 of the
    License.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace KD2;

class SVGPie
{
	protected $width = null;
	protected $height = null;
	protected $data = array();
	protected $title = null;
	protected $legend = true;

	public function __construct($width = 600, $height = 400)
	{
		$this->width = (int) $width;
		$this->height = (int) $height;
	}

	public function add(SVGPie_Data $data)
	{
		$this->data[] = $data;
		return true;
	}

	public function setTitle($title)
	{
		$this->title = $title;
		return true;
	}

	public function toggleLegend()
	{
		$this->legend = !$this->legend;
	}

	public function display()
	{
		header('Content-Type: image/svg+xml');
		echo $this->output();
	}

	public function output()
	{
		$out = '<?xml version="1.0" encoding="utf-8" standalone="no"?>' . PHP_EOL;
		$out.= '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/SVG/DTD/svg10.dtd">' . PHP_EOL;
		$out.= '<svg width="'.$this->width.'" height="'.$this->height.'" viewBox="0 0 '.$this->width.' '.$this->height.'" xmlns="http://www.w3.org/2000/svg" version="1.1">' . PHP_EOL;

		$circle_size = min($this->width, $this->height);
		$cx = $circle_size / 2;
		$cy = $this->height / 2;
		$circle_size *= 0.98;
		$radius = $circle_size / 2;

		if (count($this->data) == 1)
		{
			$row = current($this->data);
			$out .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"{$radius}\" fill=\"{$row->fill}\" "
				.	"stroke=\"white\" stroke-width=\"".($circle_size * 0.005)."\" stroke-linecap=\"round\" "
				.	"stroke-linejoin=\"round\" />";
		}
		else
		{
			$sum = 0;
			$start_angle = 0;
			$end_angle = 0;

			foreach ($this->data as $row)
			{
				$sum += $row->data;
			}

			foreach ($this->data as $row)
			{
				$row->angle = ceil(360 * $row->data / $sum);

	            $start_angle = $end_angle;
	            $end_angle = $start_angle + $row->angle;

				$x1 = $cx + $radius * cos(deg2rad($start_angle));
				$y1 = $cy + $radius * sin(deg2rad($start_angle));

				$x2 = $cx + $radius * cos(deg2rad($end_angle));
				$y2 = $cy + $radius * sin(deg2rad($end_angle));

				$arc = $row->angle > 180 ? 1 : 0;

				$out .= "<path d=\"M{$cx},{$cy} L{$x1},{$y1} A{$radius},{$radius} 0 {$arc},1 {$x2},{$y2} Z\" 
					fill=\"{$row->fill}\" stroke=\"white\" stroke-width=\"".($circle_size * 0.005)."\" stroke-linecap=\"round\" 
					stroke-linejoin=\"round\" />";
			}
		}

		if ($this->title)
		{
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="white" '
				.	'stroke="white" stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" stroke-linecap="round" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->title.'</text>' . PHP_EOL;
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="black" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->title.'</text>' . PHP_EOL;
		}

		if ($this->legend)
		{
			$x = $this->width - ($this->width * 0.06);
			$y = $this->height * 0.1;

			foreach ($this->data as $row)
			{
				$out .= '<rect x="'.$x.'" y="'.($y - $this->height * 0.01).'" width="'.($this->width * 0.04).'" height="'.($this->height * 0.04).'" fill="'.$row->fill.'" stroke="black" stroke-width="1" rx="2" />' . PHP_EOL;

				if ($row->label)
				{
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="white" stroke="white" '
						.	'stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" '
						.	'stroke-linecap="round" text-anchor="end" style="font-family: Verdana, '
						.	'Arial, sans-serif;">'.$row->label.'</text>' . PHP_EOL;
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="black" text-anchor="end" '
						.	'style="font-family: Verdana, Arial, sans-serif;">'.$row->label.'</text>' . PHP_EOL;
				}

				$y += ($this->height * 0.05);
			}
		}

		$out .= '</svg>';
		return $out;
	}
}

class SVGPie_Data
{
	public $fill = 'blue';
	public $data = 0.0;
	public $label = null;

	public function __construct($data, $label = null, $fill = 'blue')
	{
		$this->data = $data;
		$this->fill = $fill;
		$this->label = $label;
	}
}

?>