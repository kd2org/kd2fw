<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2\Graphics\SVG;

class Plot
{
	protected $width = null;
	protected $height = null;
	protected $data = array();
	protected $title = null;
	protected $labels = array();
	protected $legend = true;
	protected $count, $min, $max, $margin_top, $margin_left;

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

	public function add(Plot_Data $data)
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

		if (!count($this->data))
		{
			return $out;
		}

		// Figure out the minimum/maximum Y-axis value
		foreach ($this->data as $row)
		{
			if (count($row->get()) < 1) {
				continue;
			}

			if (null === $this->count) {
				$this->count = count($row->get());
			}

			$this->max = max((int)$this->max, max($row->get()));
			$this->min = min((int)$this->min, min($row->get()));
		}

		if ($this->count < 1) {
			return $out;
		}

		$this->margin_left = $this->width * 0.1;
		$this->margin_top = $this->height * 0.1;
		$column_space = ($this->width - $this->margin_left) / (($this->count - 1) ?: 1);

		$lines = [];
		$step = ($this->max - $this->min) / 7;

		for ($i = $this->min; $i < $this->max; $i += $step) {
			$lines[] = round($i);
		}

		$lines[] = $this->max;

		// Horizontal lines and Y axis legends
		foreach ($lines as $k => $v) {
			$v = ($k*($this->max-$this->min))/count($lines);
			$v += $this->min;
			$y = $this->y($v);
			$out .= sprintf('<line x1="%f" y1="%f" x2="%f" y2="%f" stroke-width="1" stroke="#ccc" />' . PHP_EOL, $this->margin_left, $y, $this->width, $y);

			if ($k > 0 && $step > 100) {
				$v = round($v / 100) * 100;
				//$v = $k < count($lines) - 1;
			}

			$out .= sprintf('<g><text x="%f" y="%f" font-size="%f" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">%s</text></g>' . PHP_EOL, $this->width * 0.08, $y, $this->height * 0.04, round($v));
		}

		// X-axis lines
		$y = 10 + $this->height - ($this->margin_top);
		$x = $this->width * 0.1;
		$i = 0;
		$step = max(1, round($this->count / ($this->width / 50)));

		foreach ($this->data[0]->get() as $k=>$v)
		{
			if ($x >= $this->width)
				break;

			$out .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke-width="1" stroke="%s" />', $x, $y, $x, 0, !($i++ % $step) ? '#ccc' : '#eee');
			$x += $column_space + $this->data[0]->width;
		}

		if (!empty($this->labels))
		{
			// labels for x axis
			$y = $this->height - $this->margin_top + 10;
			$i = 0;
			$step = max(1, round($this->count / ($this->width / 50)));

			for ($i = 0; $i <= $this->count; $i += $step)
			{
				$x = ($i * ($column_space + $this->data[0]->width)) + ($this->width * 0.1);

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

		$h = $this->height - ($this->height * 0.17);

		foreach ($this->data as $row)
		{
			$out .= '<polyline fill="none" stroke="'.$row->color.'" stroke-width="'.$row->width.'" '
				.'stroke-linecap="round" points="';

			$i = 0;

			foreach ($row->get() as $v)
			{
				$x = $this->margin_left + ($column_space * $i++);
				$out.= sprintf('%f,%f ', $x, $this->y($v));
			}

			$out .= '" />' . PHP_EOL;
		}

		return $out;
	}

	protected function y($value)
	{
		return 10 + $this->height - $this->margin_top - (($value - $this->min)*($this->height - $this->margin_top))/(($this->max - $this->min)?:1);
	}
}

class Plot_Data
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