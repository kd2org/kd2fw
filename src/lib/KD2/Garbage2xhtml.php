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

/*
    Garbage2xhtml lib
    Takes a html text and returns something semantic and maybe valid

    Copyleft (C) 2006-13 BohwaZ - http://bohwaz.net/
*/

class Garbage_Exception extends \Exception
{
}

class Garbage2xhtml
{
    /**
     * Secure attributes contents?
     * Will check for url scheme and url content in href and src
     * It's advised to disable <script> and <style> tags and style attribute
     * because they could be used for XSS attacks
     */
    public $secure = true;

    /**
     * Enclose text which is not in any element in <p> tags?
     */
    public $enclose_text = true;

    /**
     * Auto-add <br /> in text blocks?
     * Will also break <p> blocks when encountering a double line break.
     *
     * Example:
     *  <p>One Two
     *
     *      Three
     *      Four
     *  </p>
     *
     * Will render as:
     *  <p>One Two</p>
     *  <p>Three<br />
     *  Four</p>
     */
    public $auto_br = true;

    /**
     * Text encoding (used for escaping)
     */
    public $encoding = 'UTF-8';

    /**
     * Remove forbidden tags from ouput?
     * If true, "<em>" will disappear if it's not in allowed tags.
     * If false, "<em>" will become a text node with &lt;em&gt;
     */
    public $remove_forbidden_tags = false;

    /**
     * Remove forbidden tags contents?
     * If true "<b>Hello</b>" will become "" if <b> is not allowed
     * If false "<b>Hello</b>" will become "Hello"
     */
    public $remove_forbidden_tags_content = false;

    public $indent = true;

    /**
     * Core attributes allowed on each element
     */
    public $core_attributes = array('lang', 'class', 'id', 'title', 'dir');

    /**
     * Allowed block tags
     *
     *  'tag'   =>  true,   // Allows core attributes
     *  'tag'   =>  false,  // Disallow core attributes
     *  'tag'   =>  array('allowed attribute 1', 'href', 'src'),
     *      // Allow core attributes and those specific attributes
     */
    public $block_tags = array(
        'ul'    =>  true,
        'ol'    =>  true,
        'li'    =>  true,
        'dl'    =>  true,

        'p'     =>  true,
        'div'   =>  true,

        'h1'    =>  true,
        'h2'    =>  true,
        'h3'    =>  true,
        'h4'    =>  true,
        'h5'    =>  true,
        'h6'    =>  true,

        'pre'   =>  true,
        'hr'    =>  true,
        'address'   =>  true,
        'blockquote'=>  array('cite'),

        'object'=>  array('type', 'width', 'height', 'data'),
        'iframe'=>  array('src', 'width', 'height', 'frameborder', 'scrolling'),

        'table' =>  array('summary'),
        'tbody' =>  true,
        'thead' =>  true,
        'tfoot' =>  true,
        'caption'   =>  true,
        'colgroup'  =>  array('span'),
        'col'   =>  true,
        'tr'    =>  true,
        'th'    =>  array('colspan', 'rowspan', 'scope', 'headers'),
        'td'    =>  array('colspan', 'rowspan', 'headers'),

        // XHTML 5
        'article'   =>  true,
        'aside'     =>  true,
        'audio'     =>  array('src', 'controls', 'loop'),
        'figure'    =>  true,
        'footer'    =>  true,
        'header'    =>  true,
        'hgroup'    =>  true,
        'section'   =>  true,
        'video'     =>  array('src', 'controls', 'width', 'height', 'poster'),

    );

    /**
     * Allowed inline elements
     */
    public $inline_tags = array(
        // 'tag' => array of allowed attributes
        'abbr'  =>  array('title'),
        'dfn'   =>  true,
        'acronym'   =>  array('title'),

        'cite'  =>  true,
        'q'     =>  array('cite'),

        'code'  =>  true,
        'kbd'   =>  true,
        'samp'  =>  true,

        'strong'=>  true,
        'b'     =>  true,
        'i'     =>  true,
        'u'     =>  true,
        'em'    =>  true,

        'del'   =>  true,
        'ins'   =>  true,
        'sup'   =>  true,
        'sub'   =>  true,

        'dt'    =>  true,
        'dd'    =>  true,

        'span'  =>  true,
        'br'    =>  false,

        'a'     =>  array('href', 'hreflang', 'rel'),
        'img'   =>  array('src', 'alt', 'width', 'height'),

        'param' =>  array('name', 'value', 'type'),

        // XHTML 5
        'mark'  =>  true,
        'var'   =>  true,
        'time'  =>  array('pubdate', 'datetime'),
        'figcaption'=>  true,
    );

    public $allowed_url_schemes = array(
        'http'  =>  '://',
        'https' =>  '://',
        'ftp'   =>  '://',
        'mailto'=>  ':',
        'xmpp'  =>  ':',
        'news'  =>  ':',
        'nntp'  =>  '://',
        'tel'   =>  ':',
        'callto'=>  ':',
        'ed2k'  =>  '://',
        'irc'   =>  '://',
        'magnet'=>  ':',
        'mms'   =>  '://',
        'rtsp'  =>  '://',
        'sip'   =>  ':',
        );

    /**
     * Tags who need content to be enclosed
     */
    public $elements_need_enclose = array('blockquote', 'form', 'address', 'noscript');

    /**
     * Tags elements who accept <br /> inside
     */
    public $elements_allow_break = array('p', 'dd', 'dt', 'li', 'td', 'th', 'div');

    /**
     * Autoclosing tags (eg. <br />)
     */
    public $autoclosing_tags = array('br', 'hr', 'img', 'param');

    ///////// PRIVATE PROPERTIES

    private $opened = array();
    private $matches = array();
    private $line = 0;
    private $check_only = false;

    private $allowed_tags = array();

    const SPLIT_REGEXP = '!<(/?)([^><]*)>!';
    const ATTRIBUTE_REGEXP = '/(?:(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\') | (?>[^"\'=\s]+))+|[=]/x';

    public function parse($string)
    {
        $string = preg_replace('#<!--.*-->#Us', '', $string);
        $string = preg_replace('#<!\[CDATA\[.*\]\]>#Us', '', $string);

        $string = str_replace(array("\r\n", "\r"), "\n", $string);
        $string = preg_replace('!<br\s*/?>!i', '<br />', $string);
        $string = trim($string);

        $this->resetInternals();
        $this->allowed_tags = array_merge($this->inline_tags, $this->block_tags);

        $this->matches = preg_split(self::SPLIT_REGEXP, $string, null, PREG_SPLIT_DELIM_CAPTURE);
        unset($string);

        $nodes = $this->buildTree();
        $this->resetInternals();

        return $nodes;
    }

    /**
     * Checks a string validity
     */
    public function check($string)
    {
        $this->check_only = true;
        $this->parse($string);
        $this->check_only = false;
        return true;
    }

    /**
     * Processes a string
     */
    public function process($string)
    {
        $nodes = $this->parse($string);
        unset($string);

        if ($this->enclose_text)
        {
            $nodes = $this->encloseChildren($nodes, false);
        }

        if ($this->auto_br)
        {
            $nodes = $this->autoLineBreak($nodes);
        }

        return $this->outputNodes($nodes);
    }

    /**
     * Outputs a string from a nodes array
     */
    public function outputNodes($nodes, $level = 0)
    {
        $out = '';

        foreach ($nodes as $node)
        {
            if (is_array($node))
            {
                $close = '';
                $content = '';
                $open = '<'.$node['name'];

                foreach ($node['attrs'] as $key=>$value)
                {
                    $open .= ' '.$key.'="'.$value.'"';
                }

                if ($this->isTagAutoclosing($node['name']))
                {
                    $open .= ' />';
                }
                else
                {
                    $open .= '>';
                    $close = '</'.$node['name'].'>';
                }

                if (!empty($node['children']))
                {
                    $content = $this->outputNodes($node['children'], $level + 1);
                }

                if ($close && $this->indent !== false && array_key_exists($node['name'], $this->block_tags) && $node['name'] != 'pre')
                {
                    $tag = $this->indentTag($open, $content, $close, $level * ($this->indent === true ? 1 : (int) $this->indent));
                }
                else
                {
                    $tag = $open . $content . $close;

                    if ($node['name'] == 'br')
                        $tag.= "\n";
                }
            }
            else
            {
                $tag = $node;
            }

            $out .= $tag;
        }

        return $out;
    }

    private function indentTag($open, $content, $close, $indent)
    {
        $out = "\n";
        $out.= str_repeat(' ', $indent);
        $out.= $open;
        $out.= "\n";

        $content = explode("\n", $content);

        foreach ($content as $line)
        {
            if (!trim($line))
                continue;

            $out.= str_repeat(' ', $indent + ($this->indent === true ? 2 : (int) $this->indent));
            $out.= $line . "\n";
        }

        unset($content);

        $out.= str_repeat(' ', $indent);
        $out.= $close;
        $out.= "\n";

        return $out;
    }

    private function resetInternals()
    {
        $this->opened = array();
        $this->matches = array();
        $this->line = 0;
    }

    /**
     * Break line and paragraphs following this rule :
     * in a paragraph : one line break = <br />,
     *   two line breaks = closing paragraph and opening a new one
     * in other elements : nl2br
     */
    private function autoLineBreak($nodes, $parent = false)
    {
        $n = array();
        $nb_nodes = count($nodes);
        $k = 0;

        foreach ($nodes as $node)
        {
            // Text node inside an element allowing for line breaks
            if (is_string($node) && trim($node) != '' && in_array($parent, $this->elements_allow_break))
            {
                $matches = preg_split('!(\n+)!', $node, -1, PREG_SPLIT_DELIM_CAPTURE);
                $i = 1;
                $max = count($matches);

                while (($line = array_shift($matches)) !== null)
                {
                    // Line break
                    if ($i++ % 2 == 0)
                    {
                        if (!empty($n) && ($k < $nb_nodes - 1 || $i < $max))
                        {
                            $n[] = array('name' => 'br', 'attrs' => array(), 'children' => array());
                        }
                    }
                    elseif ($line != "")
                    {
                        $n[] = $line;
                    }
                }
            }
            // In paragraphs we'll try to split them each two-line breaks
            elseif (is_array($node) && $node['name'] == 'p')
            {
                $n[] = array('name' => 'p', 'attrs' => $node['attrs'], 'children' => array());
                $current_node = count($n) - 1;

                // Because we need to work on parent-level we will loop on children here
                // (so we don't do recursive calls)
                while (($child = array_shift($node['children'])) !== null)
                {
                    // Text node ? try to split it
                    if (is_string($child))
                    {
                        $matches = preg_split('!(\n+)!', $child, -1, PREG_SPLIT_DELIM_CAPTURE);
                        $i = 0;
                        $max = count($matches);

                        while (($line = array_shift($matches)) !== null)
                        {
                            if ($i++ % 2)
                            {
                                // More than 2 line-breaks then we create a new paragraph and we continue
                                if (strlen($line) >= 2)
                                {
                                    $n[] = array('name' => 'p', 'attrs' => $node['attrs'], 'children' => array());
                                    $current_node = count($n) - 1;

                                    $nb_nodes++;
                                    $k++;
                                }
                                // Simple line break
                                // but no line break just after or before end
                                elseif (!empty($n[$current_node]['children']) && ($i - 1 < $max || $k < $nb_nodes - 1))
                                {
                                    $n[$current_node]['children'][] = array('name' => 'br', 'attrs' => array(), 'children' => array());
                                }
                            }
                            elseif ($line != "")
                            {
                                $n[$current_node]['children'][] = $line;
                            }
                        }
                    }
                    else
                    {
                        $n[$current_node]['children'][] = $child;
                    }
                }
            }
            else
            {
                if (!is_string($node) && !empty($node['children']))
                {
                    $node['children'] = $this->autoLineBreak($node['children'], $node['name']);
                }

                $n[] = $node;
            }

            $k++;
        }

        unset($nodes);
        return $n;
    }

    private function getTagAttributes($value, $tag)
    {
        $attributes = array();

        if (array_key_exists($tag, $this->allowed_tags))
        {
            $tag =& $this->allowed_tags[$tag];
        }
        elseif (preg_match('!^[a-zA-Z0-9-]+:!', $tag, $match) && array_key_exists($match[0], $this->allowed_tags))
        {
            $tag =& $this->allowed_tags[$match[0]];
        }

        $value = preg_replace('!^.*\s+!U', '', $value);

        if (preg_match_all(self::ATTRIBUTE_REGEXP, $value, $match))
        {
            $state = 0;
            $name = false;

            foreach($match[0] as $value)
            {
                if ($state == 0)
                {
                    $name = strtolower((string) $value);
                    $state = 1;
                    $pass = false;

                    // Allowed attribute ?
                    if ($tag && in_array($name, $this->core_attributes))
                        $pass = true;
                    elseif (is_array($tag) && in_array($name, $tag))
                        $pass = true;
                    elseif (preg_match('!^(data-|[a-z0-9-]+:)!', $name, $m))
                    {
                        // Allow namespaces and data- (html5) attributes
                        if ($tag && in_array($m[1], $this->core_attributes))
                            $pass = true;
                        elseif (is_array($tag) && in_array($m[1], $tag))
                            $pass = true;
                    }

                    if (!$pass)
                    {
                        $name = false;
                        continue;
                    }
                }
                elseif ($state == 1)
                {
                    if ($value != '=' && $name && $this->check_only)
                        throw new Garbage_Exception("Expecting '=' after $name on line ".$this->line);

                    $state = 2;
                }
                elseif ($state == 2)
                {
                    $state = 0;

                    if (!$name)
                        continue;

                    if ($value == '=' && $this->check_only)
                        throw new Garbage_Exception("Unexpected '=' after $name on line ".$this->line);

                    if ($value[0] == '"' || $value[0] == "'")
                        $value = substr($value, 1, -1);

                    $value = $this->protectAttribute($name, $value);

                    $attributes[$name] = $value;
                }
            }
        }

        return $attributes;
    }

    private function decodeObfuscated($value)
    {
        // Don't try to trick me
        $value = rawurldecode($value);
        $value = html_entity_decode($value, ENT_QUOTES, $this->encoding);

        // Convert unicode entities back to ASCII
        // unicode entities don't always have a semicolon ending the entity
        $value = preg_replace_callback('~&#x0*([0-9a-f]+);?~i', 
            function($match) { return chr(hexdec($match[1])); }, 
            $value);
        $value = preg_replace_callback('~&#0*([0-9]+);?~', 
            function ($match) { return chr($match[1]); },
            $value);

        return $value;
    }

    private function protectAttribute($name, $value)
    {
        if (!$this->secure)
            return $str;

        if ($name == 'src' || $name == 'href')
        {
            $value = self::decodeObfuscated($value);

            // parse_url already have some tricks against XSS
            $url = parse_url($value);
            $value = '';

            if (!empty($url['scheme']))
            {
                $url['scheme'] = strtolower($url['scheme']);

                if (!array_key_exists($url['scheme'], $this->allowed_url_schemes))
                    return '';

                $value .= $url['scheme'] . $this->allowed_url_schemes[$url['scheme']];
            }

            if (!empty($url['host']))
            {
                $value .= $url['host'];
            }

            if (!empty($url['path']))
            {
                $value .= $url['path'];
            }

            if (!empty($url['query']))
            {
                // We can't use parse_str and build_http_string to sanitize url here
                // Or else we'll get things like ?param1&param2 transformed in ?param1=&param2=
                $query = explode('&', $url['query']);

                foreach ($query as &$item)
                {
                    $item = explode('=', $item);

                    if (isset($item[1]))
                        $item = rawurlencode(rawurldecode($item[0])) . '=' . rawurlencode(rawurldecode($item[1]));
                    else
                        $item = rawurlencode(rawurldecode($item[0]));
                }

                $value .= '?' . $this->escape(implode('&', $query));
            }

            if (!empty($url['fragment']))
            {
                $value .= '#' . $url['fragment'];
            }
        }
        else
        {
            $value = str_replace('&amp;', '&', $value);
            $value = $this->cleanEntities($value);
            $value = $this->escape($value);
        }

        return $value;
    }

    private function getTagName($value)
    {
        $value = trim($value);

        if (preg_match('!^([a-zA-Z0-9-]+)(?:[:]([a-zA-Z0-9-]+))?!', $value, $match))
        {
            if (!empty($match[2]) && array_key_exists($match[1] . ':', $this->allowed_tags))
                return $match[0];
            elseif (array_key_exists($match[0], $this->allowed_tags))
                return $match[0];
        }

        return false;
    }

    private function isTagAutoclosing($tag)
    {
        if (in_array($tag, $this->autoclosing_tags))
            return true;

        if (preg_match('!^[a-zA-Z0-9-]+:!', $tag, $match) && in_array($match[0], $this->autoclosing_tags))
            return true;

        return false;
    }

    /**
     * Build HTML tree
     */
    private function buildTree()
    {
        $i = 0;
        $nodes = array();
        $closing = false;
        $in_forbidden_tag = false;

        while (($value = array_shift($this->matches)) !== null)
        {
            // Line count
            $this->line += (int) substr_count($value, "\n");

            switch ($i++ % 3)
            {
                // Text node
                case 0:
                {
                    if ($value != "" && !$this->check_only
                        && !($in_forbidden_tag && $this->remove_forbidden_tags_content))
                    {
                        $nodes[] = $this->escape($value);
                    }
                    break;
                }

                // Next iteration is closing tag (probably ?)
                case 1:
                {
                    $closing = ($value == '/');
                    break;
                }

                // Tag itself
                case 2:
                {
                    $tag = $this->getTagName($value);

                    // Self-closing tag
                    if (substr($value, -1) == '/' || $this->isTagAutoclosing($tag))
                    {
                        $value = preg_replace('!\s*/$!', '', $value);

                        // Dismis un-authorized tag
                        if (!$tag)
                        {
                            if ($this->check_only)
                                throw new Garbage_Exception("Un-authorized tag <$value>");

                            if (!$this->remove_forbidden_tags)
                                $nodes[] = '&lt;'.$this->escape($value).' /&gt;';

                            $in_forbidden_tag = false;

                            continue;
                        }

                        if (!$this->check_only)
                        {
                            $nodes[] = array(
                                'name'  =>  $tag,
                                'attrs' =>  $this->getTagAttributes($value, $tag),
                                'children'=> array(),
                            );
                        }
                    }
                    // Closing tag
                    else if ($closing)
                    {
                        // Dismis un-authorized tag
                        if (!$tag)
                        {
                            if (!$this->remove_forbidden_tags)
                                $nodes[] = '&lt;/'.$this->escape($value).'&gt;';

                            continue;
                        }

                        $open = array_pop($this->opened);

                        // Uh-oh parse error !
                        // We could try to just dismiss tag errors or repair dirty HTML but
                        // it's too complicated. Just write valid xHTML.
                        if ($value != $open)
                        {
                            if ($this->check_only)
                                throw new Garbage_Exception("Tag <$value> closed, which is not open, on line ".$this->line);
                        }

                        return $nodes;
                    }
                    // Opening tag
                    else
                    {
                        if (!$tag)
                        {
                            if ($this->check_only)
                                throw new Garbage_Exception("Invalid tag <$value>");

                            if (!$this->remove_forbidden_tags)
                                $nodes[] = '&lt;'.$this->escape($value).'&gt;';

                            $in_forbidden_tag = true;

                            continue;
                        }

                        if (!$this->check_only)
                        {
                            $node = array(
                                'name'  =>  $tag,
                                'attrs' =>  $this->getTagAttributes($value, $tag),
                                'children'=> array(),
                            );
                        }

                        $this->opened[] = $tag;

                        if ($this->check_only)
                        {
                            $this->buildTree();
                        }
                        else
                        {
                            // Build child tree
                            $node['children'] = $this->buildTree();

                            // You need to enclose text in paragraphs in some tags
                            // (Yes, read the XHTML spec)
                            $node['children'] = $this->encloseChildren($node['children'], $node['name']);

                            $nodes[] = $node;
                        }
                    }
                }
            }
        }

        return $nodes;
    }

    /**
     * Enclose sub elements which need to be enclosed
     */
    private function encloseChildren($children, $parent)
    {
        if (!empty($children) && (in_array($parent, $this->elements_need_enclose) || !$parent))
        {
            $n = array();
            $open = false;

            while (($child = array_shift($children)) !== NULL)
            {
                if (is_string($child) || !array_key_exists($child['name'], $this->block_tags))
                {
                    if ($open === false)
                    {
                        $open = count($n);
                        $n[$open] = array('name' => 'p', 'attrs' => array(), 'children' => array());
                    }

                    $n[$open]['children'][] = $child;
                }
                else
                {
                    $open = false;

                    $n[] = $child;
                }
            }

            $children = $n;
            unset($n, $open, $child);
        }

        return $children;
    }

    public function escape($str)
    {
        $out = htmlspecialchars($str, ENT_QUOTES, $this->encoding, false);

        if (empty($out) && !empty($str))
        {
            throw new Garbage_Exception("Encoding error.");
        }

        return $out;
    }

    /**
     * Clean entities
     */
    private function cleanEntities($str)
    {
        return preg_replace('/&amp;(#[0-9a-fx]+|[a-z]+);/i', '&\\1;', $str);
    }
}
