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
 * Simple and loosy feed parser
 *
 * This parser is not using any XML or DOM parsing library
 * as a result of this it can parse any kind of feed, even if it
 * is invalid or broken.
 *
 * It will parse and provide most common properties for every item
 * and also an array of all the tags found for the item, allowing
 * easy extension on specific features (like medias for example).
 *
 * Copyleft (C) 2012-2013 BohwaZ <http://bohwaz.net/>
 */

class FeedParser
{
	/**
	 * Possible feed mime types
	 * @var array
	 */
	protected static $mime_types = array(
		'application/atom+xml',
		'application/rss+xml',
		'application/rdf+xml'
		);

	/**
	 * Feed format (rss/atom)
	 * @var string
	 */
	public $format = null;

	/**
	 * Feed vendor (netscape/w3c/userland/rss-dev-wg/imc)
	 * @var string
	 */
	public $vendor = null;

	/**
	 * Feed spec version (1.0/0.9x/2.0/0.3)
	 * @var string
	 */
	public $version = null;

	/**
	 * Items contained in the feed
	 * @var array
	 */
	protected $items = array();

	/**
	 * Channel data
	 * @var string
	 */
	protected $channel = null;

	/**
	 * Current item, to iterate over items
	 * @var integer
	 */
	protected $current_item = 0;

	/**
	 * Returns discovered feeds from web URL
	 * @param  string $url HTML page URL
	 * @return array List of discovered feeds
	 */
	static public function discoverFeedsFromURL($url)
	{
		$feeds = self::discoverFeeds(file_get_contents($url));

		if (empty($feeds))
			return $feeds;

		foreach ($feeds as &$feed)
		{
			$feed['url'] = self::getRealURL($feed['href'], $url);
		}

		return $feeds;
	}

	/**
	 * Returns a complete URL with scheme and host from an unknown or incomplete URI
	 * @param  string $href     Incomplete URI
	 * @param  string $base_url Base URL used to build a complete URL
	 * @return string           Complete URL
	 */
	static public function getRealURL($href, $base_url)
	{
		$_href = parse_url($href);
		
		// already an absolute URL
		if (!empty($_href['scheme']))
			return $href;

		$_base = parse_url($base_url);

		// protocol-relative URL ie. //bits.wikimedia.org/static/elements/rss.xml
		if (substr($href, 0, 2) == '//')
			return $_base['scheme'] . ':' . $href;

		$url = $_base['scheme'] . '://';

		if (!empty($_base['user']))
		{
		 	$url .= $_base['user'];

		 	if (!empty($_base['pass']))
		 		$url .= ':' . $_base['pass'];

		 	$url .= '@';
		}

		$url .= $_base['host'];

		// absolute URI
		if (preg_match('!^/!', $href))
			return $url . $href;

		$url .= preg_replace('!/[^/]*$!', '/', $_base['path']);

		// query-based URI, eg. ?feed
		if (!empty($_href['path']))
			return $url . $href;

		$url .= preg_replace('!^.*/!', '', $_base['path']);

		// relative URI
		return $url . $href;
	}

	/**
	 * Discover feeds from an HTML string
	 * @param  string  $content                   HTML content
	 * @param  boolean $fallback_discover_content If set to TRUE, it will try to find feeds in <a href... links
	 * if no feed is found in <link rel... tags.
	 * @return array                              List of feeds found
	 */
	static public function discoverFeeds($content, $fallback_discover_content = false)
	{
		$feeds = array();
		$possible_rels = array('alternate', 'feed', 'alternate feed');

		// Standard auto discovery
		if (preg_match_all('/<\s*link\s+(.*?)\/?>/is', $content, $links, PREG_SET_ORDER))
		{
			foreach ($links as $link)
			{
				$params = self::parseAttributes($link[1]);

				if (empty($params['rel']) || empty($params['type']) || empty($params['href']))
					continue;

				$rel = strtolower($params['rel']);

				if (!in_array($rel, $possible_rels))
					continue;

				$type = strtolower($params['type']);

				if (!in_array($type, self::$mime_types))
					continue;

				$feeds[] = array(
					'type'	=>	$type,
					'href'	=>	$params['href'],
					'title'	=>	isset($params['title']) ? trim(html_entity_decode($params['title'], ENT_COMPAT, '')) : null,
					);
			}
		}
		// Discover feed links from page links
		elseif ($fallback_discover_content && preg_match_all('/<\s*a\s+(.*?)/?>(.*?)</a>/is', $content, $links, PREG_SET_ORDER))
		{
			foreach ($links as $link)
			{
				$params = self::parseAttributes($link[1]);

				if (empty($params['href']))
					continue;

				if (!preg_match('/[^\w\d](?:atom|rss|rdf)[^\w\d]/i', $params['href']))
					continue;

				$feeds[] = array(
					'type'	=>	null,
					'href'	=>	$params['href'],
					'title'	=>	html_entity_decode(strip_tags($link[2]), ENT_COMPAT, ''),
				);
			}
		}

		return $feeds;
	}

	/**
	 * Returns a valid UNIX timestamp from a RSS/ATOM date, even broken ones
	 * From https://github.com/fguillot/picoFeed/blob/master/lib/PicoFeed/Parser.php
	 * @param  string $value Input date, any format
	 * @return int 	         Unix Timestamp
	 */
    static public function parseDate($value)
    {
        // Format => truncate to this length if not null
        $formats = array(
            DATE_ATOM => null,
            DATE_RSS => null,
            DATE_COOKIE => null,
            DATE_ISO8601 => null,
            DATE_RFC822 => null,
            DATE_RFC850 => null,
            DATE_RFC1036 => null,
            DATE_RFC1123 => null,
            DATE_RFC2822 => null,
            DATE_RFC3339 => null,
            'D, d M Y H:i:s' => 25,
            'D, d M Y h:i:s' => 25,
            'D M d Y H:i:s' => 24,
            'Y-m-d H:i:s' => 19,
            'Y-m-d\TH:i:s' => 19,
            'd/m/Y H:i:s' => 19,
            'D, d M Y' => 16,
            'Y-m-d' => 10,
            'd-m-Y' => 10,
            'm-d-Y' => 10,
            'd.m.Y' => 10,
            'm.d.Y' => 10,
            'd/m/Y' => 10,
            'm/d/Y' => 10,
        );

        $value = trim($value);

        foreach ($formats as $format => $length) {
            $timestamp = self::getValidDate($format, substr($value, 0, $length));
            if ($timestamp > 0) return $timestamp;
        }

        return time();
    }

    /**
     * Creates a valid timestamp from a given date format
     * @param  string $format Date format
     * @param  string $value  Date string
     * @return integer 		  Timestamp
     */
    static protected function getValidDate($format, $value)
    {
        $date = \DateTime::createFromFormat($format, $value);

        if ($date !== false) {
            $errors = \DateTime::getLastErrors();
            if ($errors['error_count'] === 0 && $errors['warning_count'] === 0) return $date->getTimestamp();
        }

        return 0;
    }

    /**
     * Returns the content of an XML tag, without entities
     * @param  string $string Raw XML string
     * @return string 		  Decoded string
     */
    static protected function getXmlContent($string)
    {
    	$string = trim($string);
    	$string = preg_replace('/^.*<!\[CDATA\[/is', '', $string);
    	$string = preg_replace('/\]\]>.*$/s', '', $string);
    	$string = str_replace('&apos;', '&#039;', $string);
    	$string = html_entity_decode(self::utf8_encode($string), ENT_QUOTES, 'UTF-8');
    	return $string;
    }

    static protected function utf8_encode($str)
    {
		// Check if string is already UTF-8 encoded or not
		if (!preg_match('//u', $str))
        {
            return utf8_encode($str);
        }

        return $str;
    }

    /**
     * Parses attributes from a XML/HTML tag
     * @param  string $str String containing all attributes
     * @return array       List of attributes
     */
	static protected function parseAttributes($str)
	{
		$params = array();
		preg_match_all('/(\w[\w\d]*(?::\w[\w\d]*)*)(?:\s*=\s*(?:([\'"])(.*?)\2|([^>\s\'"]+)))?/i', $str, $_params, PREG_SET_ORDER);

		if (empty($_params))
		{
			return $params;
		}

		foreach ($_params as $_p)
		{
			$value = isset($_p[4]) ? trim($_p[4]) : (isset($_p[3]) ? trim($_p[3]) : null);
			$params[strtolower($_p[1])] = $value ? self::utf8_encode($value) : $value;
		}

		return $params;
	}

	/**
	 * Constructor
	 */
	public function __construct()
	{
	}

	/**
	 * Load and parse a feed from an URL
	 * @param  string $url Feed URL
	 * @return boolean	   false if feed parsing or loading failed
	 */
	public function load($url)
	{
		if (!$this->parse(file_get_contents($url)))
			return false;

		if (!empty($this->items))
		{
			foreach ($this->items as &$item)
			{
				$item->url = self::getRealURL($item->link, $url);
			}
		}

		return true;
	}

	/**
	 * Parse a feed from a string
	 * @param  string $content Feed as string
	 * @return boolean         false if parsing failed (or string is empty)
	 */
	public function parse($content)
	{
		$this->format = $this->version = $this->vendor = false;
		$this->items = array();
		$this->channel = null;

		if (preg_match('!<feed\s+[^>]*xmlns\s*=\s*["\']?http://www\.w3\.org/2005/Atom["\']?!i', $content))
		{
			$this->format = 'atom';
			$this->version = '1.0';
			$this->vendor = 'w3c';
		}
		elseif (preg_match('!<feed\s+[^>]*version\s*=\s*["\']?0\.3["\']?!i', $content))
		{
			$this->format = 'atom';
			$this->version = '0.3';
			$this->vendor = 'imc';
		}
		// Source: http://web.archive.org/web/20100315092011/http://diveintomark.org/archives/2004/02/04/incompatible-rss
		elseif (preg_match('!<rdf:RDF\s+[^>]*http://my\.netscape\.com/rdf/simple/0\.9/!i', $content))
		{
			$this->format = 'rss';
			$this->version = '0.90';
			$this->vendor = 'netscape';
		}
		elseif (preg_match('!http://my\.netscape\.com/publish/formats/rss-0\.91\.dtd!i', $content))
		{
			$this->format = 'rss';
			$this->version = '0.91';
			$this->vendor = 'netscape';
		}
		elseif (preg_match('!<rss\s+[^>]*version\s*=\s*["\']?(0\.9[1234]|2\.0)["\']?!i', $content))
		{
			$this->format = 'rss';
			$this->version = '0.91';
			$this->vendor = 'userland';
		}
		elseif (preg_match('!<rdf:RDF\s+[^>]*http://purl\.org/rss/1\.0/!i', $content))
		{
			$this->format = 'rss';
			$this->version = '1.0';
			$this->vendor = 'rss-dev-wg';
		}

		// Separate items from channel
		$pos = $end = false;

		if (($items = preg_split('/<\/?(item|entry)(\s+.*?)?>/is', $content, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY))
			&& !empty($items[1]))
		{
			$pos = $items[1][1];
			$end = $items[count($items) - 2][1] + strlen($items[count($items) - 2][0]);
			
			unset($items[count($items)-1]);
			unset($items[0]);

			foreach ($items as $item)
			{
				if (trim($item[0]) == '')
					continue;

				$this->items[] = $item[0];
			}

			unset($items);
		}

		if ($pos)
		{
			$start = stripos($content, '<channel');
			$this->channel = substr($content, ($start === false ? 0 : $start), $pos - $start);

			// Get the start of the channel
			if (($start = strpos($this->channel, '>')) !== false)
			{
				$this->channel = substr($this->channel, $start);
			}

			$channel_end = strpos($this->channel, '</channel');

			// If the channel ends before the items
			if ($channel_end !== false)
			{
				$this->channel = substr($this->channel, 0, $channel_end);
			}
			// If there is still somethin from the channel after the items
			elseif ($end)
			{
				$channel_end = strpos($content, '</channel');
				$last = substr($content, $end, $channel_end - $end);

				if (($start = strpos($last, '>')) !== false)
				{
					$last = substr($last, $start);
				}

				$this->channel .= $last;
			}

			$this->parseChannel();
		}

		if ($this->items)
		{
			$this->parseItems();
		}

		// Obviously something went wrong
		if (empty($this->items) && empty($this->channel))
		{
			return false;
		}

		return true;
	}

	/**
	 * Parse channel tags into something useful
	 * @return void
	 */
	protected function parseChannel()
	{
		$channel = new \stdClass;
		$channel->title = null;
		$channel->description = null;
		$channel->link = null;
		$channel->date = null;
		$channel->raw = $this->channel;
		$channel->xml = array();

		preg_match_all('!<\s*([\w\d-]+(?::[\w\d-]+)*)\s*(.*?)/?>(?:(.*?)</\1>)?!is', $this->channel, $tags, PREG_SET_ORDER);

		foreach ($tags as $_tag)
		{
			$tag = new \stdClass;
			$tag->name = $_tag[1];
			$tag->content = isset($_tag[3]) ? self::getXmlContent($_tag[3]) : null;
			$tag->attributes = !empty($_tag[2]) ? self::parseAttributes($_tag[2]) : array();
			$channel->xml[] = $tag;

			$_tag = strtolower($tag->name);

			switch ($_tag)
			{
				case 'title':
				case 'description':
					$channel->{$_tag} = $tag->content;
					break;
				case 'dc:date':
				case 'pubdate':
				case 'modified':
				case 'published':
				case 'updated':
					$channel->date = $tag->content;
					break;
				case 'link':
					if (!empty($channel->link))
						break;

					if (!empty($tag->attributes['href']))
					{
						$channel->link = $tag->attributes['href'];
					}
					else
					{
						$channel->link = $tag->content;
					}
					break;
				default:
					break;
			}
		}

		// Convert the date string to a timestamp
		$channel->date = self::parseDate($channel->date);

		$this->channel = $channel;
	}

	/**
	 * Parse feed items into an array of objects
	 * @return void
	 */
	protected function parseItems()
	{
		foreach ($this->items as $key=>$_item)
		{
			$item = new \stdClass;
			$item->title = null;
			$item->description = null;
			$item->link = null;
			$item->date = null;
			$item->content = null;
			$item->raw = $_item;
			$item->xml = array();

			preg_match_all('!<\s*([\w\d-]+(?::[\w\d-]+)*)\s*(.*?)/?>(?:(.*?)</\1>)?!is', $_item, $tags, PREG_SET_ORDER);

			foreach ($tags as $_tag)
			{
				$tag = new \stdClass;
				$tag->name = $_tag[1];
				$tag->content = isset($_tag[3]) ? self::getXmlContent($_tag[3]) : null;
				$tag->attributes = !empty($_tag[2]) ? self::parseAttributes($_tag[2]) : array();
				$item->xml[] = $tag;

				$_tag = strtolower($tag->name);

				switch ($_tag)
				{
					case 'title':
					case 'description':
					case 'content':
						$item->{$_tag} = $tag->content;
						break;
					case 'dc:date':
					case 'pubdate':
					case 'modified':
					case 'published':
					case 'updated':
					case 'issued':
						if (!empty($tag->content))
							$item->date = $tag->content;
						break;
					case 'link':
						if (!empty($item->link))
							break;

						if (!empty($tag->attributes['href']))
						{
							$item->link = $tag->attributes['href'];
						}
						else
						{
							$item->link = $tag->content;
						}
						break;
					case 'summary':
						$item->description = $tag->content;
					case 'content:encoded':
						$item->content = $tag->content;
					default:
						break;
				}
			}

			// Convert the date string to a timestamp
			$item->date = self::parseDate($item->date);

			if (is_null($item->description) && !is_null($item->content))
				$item->description = $item->content;
			elseif (!is_null($item->description) && is_null($item->content))
				$item->content = $item->description;

			$this->items[$key] = $item;
		}
	}

	/**
	 * Get the channel object
	 * @return object stdClass object containg channel informations
	 */
	public function getChannel()
	{
		if (!$this->channel)
			return false;

		return $this->channel;
	}

	/**
	 * Get the array of items
	 * @return array An array of stdClass objects
	 */
	public function getItems()
	{
		return $this->items;
	}

	/**
	 * Calculates the average publication interval between items.
	 * Useful to know when to check the feed for new stuff.
	 * @return integer Average publication time (in seconds)
	 */
	public function getAveragePublicationInterval()
	{
		$start = null;

		if (count($this->items) < 1)
		{
			return 3600 * 24;
		}

		$start = $this->items[0]->date;
		$end = $this->items[count($this->items) - 1]->date;

		$diff = abs($end - $start);
		return (int) round($diff / count($this->items));
	}

	/**
	 * Get a feed item
	 * @param  integer $key Item number, if omitted it will return the next item in the list
	 * @return object 		stdClass object with informations and raw content of the item
	 */
	public function getItem($key = false)
	{
		if (!$this->items)
		{
			return false;
		}

		if ($key === false)
		{
			$key = $this->current_item++;

			if ($key > count($this->items))
			{
				$this->reset();
				return false;
			}
		}

		if (!array_key_exists($key, $this->items))
			return null;

		return $this->items[$key];
	}

	/**
	 * Reset the iteration counter for getItem()
	 * @return void
	 */
	public function reset()
	{
		$this->current_item = 0;
	}
}