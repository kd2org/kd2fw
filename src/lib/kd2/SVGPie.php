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

	protected function encodeText($str)
	{
		return htmlspecialchars($str, ENT_XML1, 'UTF-8');
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
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="black" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
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
						.	'Arial, sans-serif;">'.$this->encodeText($row->label).'</text>' . PHP_EOL;
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="black" text-anchor="end" '
						.	'style="font-family: Verdana, Arial, sans-serif;">'.$this->encodeText($row->label).'</text>' . PHP_EOL;
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