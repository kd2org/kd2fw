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

class SVGPlot
{
	protected $width = null;
	protected $height = null;
	protected $data = array();
	protected $title = null;
	protected $labels = array();
	protected $legend = true;

	public function __construct($width = 600, $height = 400)
	{
		$this->width = (int) $width;
		$this->height = (int) $height;
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

	public function setLabels($labels)
	{
		$this->labels = $labels;
		return true;
	}

	public function add(SVGPlot_Data $data)
	{
		$this->data[] = $data;
		return true;
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

		if ($this->title)
		{
			$out .= '<text x="'.round($this->width/2).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="white" '
				.	'stroke="white" stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" stroke-linecap="round" '
				.	'text-anchor="middle" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
			$out .= '<text x="'.round($this->width/2).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="black" '
				.	'text-anchor="middle" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
		}

		$out .= $this->_renderLinegraph();

		if ($this->legend)
		{
			$x = $this->width - ($this->width * 0.06);
			$y = $this->height * 0.1;

			foreach ($this->data as $row)
			{
				$out .= '<rect x="'.$x.'" y="'.($y - $this->height * 0.01).'" width="'.($this->width * 0.04).'" height="'.($this->height * 0.04).'" fill="'.$row->color.'" stroke="black" stroke-width="1" rx="2" />' . PHP_EOL;

				if ($row->title)
				{
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="white" stroke="white" '
						.	'stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" '
						.	'stroke-linecap="round" text-anchor="end" style="font-family: Verdana, Arial, '
						.	'sans-serif;">'.$this->encodeText($row->title).'</text>' . PHP_EOL;
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="black" text-anchor="end" '
						.	'style="font-family: Verdana, Arial, sans-serif;">'.$this->encodeText($row->title).'</text>' . PHP_EOL;
				}

				$y += ($this->height * 0.07);
			}
		}

		$out .= '</svg>';

		return $out;
	}

	protected function _renderLinegraph()
	{
		$out = '';

		if (empty($this->data))
		{
			return $out;
		}

		// Figure out the maximum Y-axis value
		$max_value = 0;
		$nb_elements = 0;

		foreach ($this->data as $row)
		{
			if (count($row->get()) < 1)
				continue;
			
			if ($max_value == 0)
			{
				$nb_elements = count($row->get());
			}

			$max = max($row->get());

			if ($max > $max_value)
			{
				$max_value = $max;
			}
		}

		if ($nb_elements < 1)
		{
			return $out;
		}

		$divide = round($max_value / ($this->height * 0.8), 2) ?: 1;
		$y_axis_val = ceil(abs($max_value) / ($this->height * 0.8)) * 50;
		$space = round(($this->width - ($this->width * 0.1)) / $nb_elements, 2);

		for ($i = 0; $i < 10; $i++)
		{
			if (($y_axis_val * $i) <= $max_value)
			{
				$line_y = ($this->height * 0.93) - (($y_axis_val / $divide) * $i);
				$out .= '<line x1="'.($this->width * 0.1).'" y1="'.($line_y).'" x2="'.$this->width.'" y2="'.($line_y).'" stroke-width="1" stroke="#ccc" />' . PHP_EOL;
				$out .= '<g><text x="'.($this->width * 0.08).'" y="'.($line_y).'" font-size="'.($this->height * 0.04).'" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">'.($y_axis_val * $i).'</text></g>' . PHP_EOL;
			}
		}

		// X-axis lines
		$y = $this->height - ($this->height * 0.07);
		$x = $this->width * 0.1;
		$i = 0;

		foreach ($this->data[0]->get() as $k=>$v)
		{
			if ($x >= $this->width)
				break;

			$out .= '<line x1="'.$x.'" y1="'.($y).'" x2="'.$x.'" y2="'.($this->height * 0.1).'" stroke-width="1" stroke="#ccc" />' . PHP_EOL;
			$x += $space + $this->data[0]->width;
		}

		if (!empty($this->labels))
		{
			// labels for x axis
			$y = $this->height - ($this->height * 0.07);
			$i = 0;
			$step = round($nb_elements / 5);

			for ($i = 0; $i <= $nb_elements; $i += $step)
			{
				//echo
				$x = ($i * ($space + $this->data[0]->width)) + ($this->width * 0.1);

				if ($x >= $this->width)
					break;

				if (isset($this->labels[$i]))
				{
					$out .= '<g><text x="'.$x.'" y="'.($y+($this->height * 0.06)).'" '
						.	'font-size="'.($this->height * 0.04).'" fill="gray" text-anchor="middle" '
						.	'style="font-family: Verdana, Arial, sans-serif;">'
						.	$this->encodeText($this->labels[$i]).'</text></g>' . PHP_EOL;
				}
			}
		}

		$y = ($this->height * 0.1);
		$w = $this->width - ($this->width * 0.1);
		$h = $this->height - ($this->height * 0.17);

		foreach ($this->data as $row)
		{
			$out .= '<polyline fill="none" stroke="'.$row->color.'" stroke-width="'.$row->width.'" '
				.'stroke-linecap="round" points="';

			$x = ($this->width * 0.1);

			foreach ($row->get() as $k=>$v)
			{
				$_y = $y + ($h - round($v / $divide, 2)) + round($row->width / 2);
				$out.= $x.','.$_y.' ';
				$x += $space + $row->width;
			}

			$out .= '" />' . PHP_EOL;
		}

		return $out;
	}
}

class SVGPlot_Data
{
	public $color = 'blue';
	public $width = '10';
	public $title = null;
	protected $data = array();

	public function __construct($data)
	{
		if (is_array($data))
			$this->data = $data;
		elseif (!is_object($data))
			$this->append($data);
	}

	public function append($data)
	{
		$this->data[] = $data;
		return true;
	}

	public function get()
	{
		return $this->data;
	}
}