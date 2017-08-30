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
 * This class manages technical and general exceptions
 */
class MiniSkelException extends \Exception
{
    protected $tpl_filename = '';

    public function __construct($msg, $file='')
    {
        $this->tpl_filename = $file;
        parent::__construct($msg);
    }

    public function getTemplateFilename()
    {
        return $this->tpl_filename;
    }
}

/**
 * This class only manages markup exceptions
 */
class MiniSkelMarkupException extends MiniSkelException
{
}

class MiniSkel
{
    /**
     * The path where templates belongs
     */
    public $template_path = './';

    /**
     * You can change the name of the loop tag, by default it's BOUCLE, to be compatible with SPIP syntax
     * be warned that your old templates using <BOUCLE...> syntax will not work anymore
     */
    public $loopTagName = 'BOUCLE';

    /**
     * You can change the name of short loop tags, by default it's B (like B in BOUCLE)
     */
    public $loopShortTagName = 'B';

    /**
     * As by default the loop keywords are in french, you can change them here
     */
    public $loopKeywords = array(
        'orderBy'   =>  'par',
        'orderDesc' =>  'inverse',
        'begin'     =>  'debut',
        'random'    =>  'hasard',
        'duplicates'=>  'doublons',
        'unique'    =>  'unique',
    );

    public $includeTagName = 'INCLURE';

    /**
     * Throw exceptions for warnings ? (bad criterias, modifiers that don't exists, etc.)
     */
    public $strictMode = true;

    /**
     * For internal use : name of the current loop
     */
    protected $currentLoop = "Unknown";

    /**
     * For internal use : file name of current template
     */
    protected $currentTemplate = '';

    /**
     * For internal use : avoid kloops and bad templates
     */
    protected $parentLoopLevel = 0;
    protected $loopCounter = 0;

    /**
     * Internal global variables, like in smarty's assign
     */
    protected $variables = array();

    /**
     * External modifiers, like in smarty
     */
    protected $modifiers = array();

    /**
     * Line counter
     * @var integer
     */
    public $lines = 0;

    /**
     * Criteria actions
     */
    const ACTION_ORDER_BY = 1;
    const ACTION_ORDER_DESC = 2;
    const ACTION_AVOID_DUPLICATES = 3;
    const ACTION_LIMIT = 4;
    const ACTION_MATCH_FIELD = 5;
    const ACTION_MATCH_FIELD_BY_VALUE = 6;
    const ACTION_MATCH_FIELD_BY_REGEXP = 7;
    const ACTION_MATCH_FIELD_NOT_BY_REGEXP = 8;
    const ACTION_MATCH_FIELD_IN = 9;
    const ACTION_DISPLAY_SEPARATOR = 10;

    /**
     * Loop content types
     */
    const LOOP_CONTENT = 1;
    const PRE_CONTENT = 2;
    const POST_CONTENT = 3;
    const ALT_CONTENT = 4;

    /**
     * Variables context (inside or outside a loop)
     */
    const CONTEXT_IN_LOOP = 1;
    const CONTEXT_GLOBAL = 2;
    const CONTEXT_IN_ARG = 3;
    const CONTEXT_IN_PRE = 4;
    const CONTEXT_IN_POST = 5;

    /**
     * Replace first occurence of string
     */
    protected function replaceFirst($search, $replace, $subject)
    {
        $pos = strpos($subject, $search);

        if ($pos !== false)
        {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }

    /**
     * Internal parsing of common loop criterias (tries to be compatible with SPIP syntax)
     * You can't extend this method
     *
     * @param string $criteria The unparsed criteria
     */
    private function parseCriteria($criteria)
    {
        $criteria = trim($criteria);

        // {inverse} -> ORDER BY ... DESC
        if (strtolower($criteria) == $this->loopKeywords['orderDesc'])
        {
            return array(
                'action'    =>  self::ACTION_ORDER_DESC,
            );
        }
        // {doublons} -> avoid duplicates in a page
        elseif (preg_match('/^('.$this->loopKeywords['duplicates'].'|'.$this->loopKeywords['unique'].')\s*([a-z0-9_-]+)?$/i', $criteria))
        {
            return array(
                'action'    =>  self::ACTION_AVOID_DUPLICATES,
                'name'      =>  isset($match[2]) ? $match[2] : false,
            );
        }
        // {par id_article} -> ORDER BY id_article
        elseif (preg_match('/^'.$this->loopKeywords['orderBy'].'\s+([a-z0-9_-]+)$/i', $criteria, $match))
        {
            return array(
                'action'    =>  self::ACTION_ORDER_BY,
                'field'     =>  $match[1],
            );
        }
        // {0,10} -> LIMIT 0,10
        elseif (preg_match('/^([0-9]+),([0-9]+)$/', $criteria, $match))
        {
            return array(
                'action'    =>  self::ACTION_LIMIT,
                'begin'     =>  (int) $match[1],
                'number'    =>  isset($match[2]) ? (int) $match[2] : false,
            );
        }
        // begin_list,20 -> LIMIT {$_GET['begin_list']},20
        elseif (preg_match('/^('.$this->loopKeywords['begin'].'_[a-z0-9_-]+)(,([0-9]+))?$/i', $criteria, $match))
        {
            if (isset($_REQUEST[$match[1]]))
            {
                $begin = (int) $_REQUEST[$match[1]];
            }
            else
            {
                $begin = $match[1];
            }

            if (isset($match[2]) && isset($match[3]))
            {
                $number = (int) $match[3];
            }
            else
            {
                $number = false;
            }

            return array(
                'action'    =>  self::ACTION_LIMIT,
                'begin'     =>  $begin,
                'number'    =>  $number,
            );
        }
        // {id_article} -> WHERE id_article = "{$id_article}" (???)
        elseif (preg_match('/^([a-z0-9_-]+)$/i', $criteria, $match))
        {
            return array(
                'action'    =>  self::ACTION_MATCH_FIELD,
                'field'     =>  $match[1],
            );
        }
        // {id_article=5} -> WHERE id_article = 5
        elseif (preg_match('/^([a-z0-9_-]+)\s*(>=|<=|=|!=|>|<)\s*"?(.*?)"?$/i', $criteria, $match))
        {
            return array(
                'action'    =>  self::ACTION_MATCH_FIELD_BY_VALUE,
                'field'     =>  $match[1],
                'comparison'=>  $match[2],
                'value'     =>  $match[3],
            );
        }
        // {titre==^France} -> WHERE id_article REGEXP "^France"
        elseif (preg_match('/^([a-z0-9_-]+)\s*(==|!==)\s*"?(.+)"?$/i', $criteria, $match))
        {
            return array(
                'action'    =>  ($match[2] == '==') ? self::ACTION_MATCH_FIELD_BY_REGEXP : self::ACTION_MATCH_FIELD_NOT_BY_REGEXP,
                'field'     =>  $match[1],
                'value'     =>  $match[3],
            );
        }
        // {pays IN "Japon", "France"} -> WHERE pays IN "Japon", "France"
        elseif (preg_match('/^([a-z0-9_-]+)\s+IN\s+(.+)$/i', $criteria, $match))
        {
            $content = explode(',', $match[2]);
            $values = array();

            foreach ($content as $item)
            {
                $item = preg_replace('/^["\']?(.*)["\']?$/', '\\1', $item);
                $values[] = $item;
            }

            unset($content);

            return array(
                'action'    =>  self::ACTION_MATCH_FIELD_IN,
                'field'     =>  $match[1],
                'values'    =>  $values,
            );
        }
        // {"<br />"} -> Inserts a <br /> between each loop iteration
        elseif (preg_match('/^"(.+)"$/', $criteria, $match))
        {
            return array(
                'action'    =>  self::ACTION_DISPLAY_SEPARATOR,
                'value'     =>  $match[1],
            );
        }
        else
        {
            throw new MiniSkelMarkupException("Unknown criteria '".$criteria."' in ".$this->currentLoop." loop.", $this->currentTemplate);

            return $criteria;
        }
    }

    /**
     * Internal parsing of loops (tries to be compatible with SPIP)
     * You can't extend this method
     *
     * @param string $content
     * @param string $parentLoop
     */
    private function parseLoops($content, $parentLoop=false)
    {
        if ($parentLoop)
        {
            $this->parentLoopLevel++;

            // This is a security to keep your server cool
            if ($this->parentLoopLevel > 10)
            {
                throw new MiniSkelException("Too many imbricated loops !", $this->currentTemplate);
            }
        }

        while (preg_match('/<'.$this->loopTagName.'([_-][.a-z0-9_-]+|[0-9]+)\s*\(([a-z0-9_-]+)\)\s*(\{.*?\})*>/Ui', $content, $match))
        {
            if ($this->loopCounter > 100)
            {
                throw new MiniSkelException("Too many loops for one template !", $this->currentTemplate);
            }

            $loopCounter = 0;
            $loopName = $match[1];
            $loopType = strtolower($match[2]);
            $loopTag = $match[0];

            $loopContent = false;
            $preContent = false;
            $postContent = false;
            $altContent = false;

            $this->currentLoop = $loopName;

            $loopCriterias = array();

            if (!empty($match[3]))
            {
                preg_match_all('/\{(.*)\}/U', $match[3], $match, PREG_SET_ORDER);

                foreach ($match as $item)
                {
                    $loopCriterias[] = $this->parseCriteria($item[1]);
                }
            }

            if (preg_match('/<\/'.$this->loopTagName.$loopName.'>/i', $content, $match_end))
            {
                $loopTagEnd = $match_end[0];
            }
            else
            {
                throw new MiniSkelMarkupException("Loop tag ".$loopName." is not closed properly.", $this->currentTemplate);
            }

            unset($match, $match_end);

            $loopB = strpos($content, $loopTag);
            $loopE = strpos($content, $loopTagEnd);

            $tagB = $loopB;
            $tagE = $loopE + strlen($loopTagEnd);

            if ($loopB > $loopE)
            {
                throw new MiniSkelMarkupException("Loop tag ".$loopName." was closed before it was opened ?!", $this->currentTemplate);
            }

            // Extract the loop content
            $loopContent = substr($content, $loopB + strlen($loopTag), $loopE - $loopB - strlen($loopTag));

            // The things before the loop (if any)
            $loopShortTagName = '<'.$this->loopShortTagName.$loopName.'>';
            $preB = strpos($content, $loopShortTagName);

            if ($preB > $loopB)
            {
                throw new MiniSkelMarkupException("Can't open ".$loopShortTagName." after ".$loopTag."...", $this->currentTemplate);
            }

            if ($preB !== false)
            {
                $preContent = substr($content, $preB + strlen($loopShortTagName), $tagB - $preB - strlen($loopShortTagName));
                $tagB = $preB;
            }
            unset($preB, $loopShortTagName);

            // After the loop (if any)
            $loopShortEndTagName = '</'.$this->loopShortTagName.$loopName.'>';
            $postE = strpos($content, $loopShortEndTagName);

            if ($postE !== false && $postE < $loopE)
            {
                throw new MiniSkelMarkupException("Can't close ".$loopShortEndTagName." before ".$loopTagEnd."...", $this->currentTemplate);
            }

            if ($postE !== false)
            {
                $postContent = substr($content, $tagE, $postE - $tagE);
                $tagE = $postE + strlen($loopShortEndTagName);
            }
            unset($postE, $loopShortEndTagName);

            // alternative
            $loopAltTagName = '<//'.$this->loopShortTagName.$loopName.'>';
            $altE = strpos($content, $loopAltTagName);

            if ($altE !== false && $altE < $tagE)
            {
                throw new MiniSkelMarkupException("Can't close ".$loopAltTagName." before ".$loopTagEnd."...", $this->currentTemplate);
            }

            if ($altE !== false)
            {
                $altContent = substr($content, $tagE, $altE - $tagE);
                $tagE = $altE + strlen($loopAltTagName);
            }

            unset($loopShortEndTagName, $loopAltTagName, $loopShortTagName, $loopB, $loopE, $altE, $postE, $preB);

            $tagContent = $this->processLoop($loopName, $loopType, $loopCriterias,
                $loopContent, $preContent, $postContent, $altContent);

            $content = substr($content, 0, $tagB) . $tagContent . substr($content, $tagE);

            unset($altContent, $postContent, $preContent, $loopContent, $tagContent, $tagB, $tagE);

            $this->loopCounter++;
            $this->currentLoop = false;
        }

        if ($parentLoop)
        {
            $this->currentLoop = $parentLoop;
            $this->parentLoopLevel--;
        }

        return $content;
    }

    /**
     * Builds a tree of variables out of a string entry.
     * It's quite the same as parsing HTML actually.
     *
     * @param array $parent Parent variable tag
     */
    private function _buildVariablesTree(&$splitted_text, &$parent = [])
    {
        $i = 0;
        $nodes = [];

        while (($token = array_shift($splitted_text)) !== null)
        {
            $this->lines += substr_count($token, "\n");

            // Not a tag, not a bracket, just a text node
            if (($i % 2) == 0)
            {
                // Don't store empty text nodes
                if ($token !== '')
                {
                    $nodes[] = $token;
                }
            }
            // Opening bracket, we don't know yet if it will be linked to a tag or not
            elseif ($token == '[')
            {
                $tag = ['name' => false, 'post' => []];
                $tag['pre'] = $this->_buildVariablesTree($splitted_text, $tag);

                // If the tag name is empty it means we met a matching closing bracket
                // before the actual variable name and modifiers (just something between brackets)
                if ($tag['name'] === false)
                {
                    $nodes[] = $token;
                    $nodes = array_merge($nodes, $tag['pre']);
                    $nodes[] = ']';
                }
                // It is an actual tag, we continue
                else
                {
                    $tag['post'] = $this->_buildVariablesTree($splitted_text, $tag);
                    $nodes[] = $tag;
                }
            }
            // Closing bracket, end of tag (or not)
            elseif ($token == ']')
            {
                // We are in a tag, close it
                if (isset($parent['name']))
                {
                    return $nodes;
                }
                // We are not in a tag, this is just a text node
                else
                {
                    $nodes[] = $token;
                }
            }
            // Single tag
            else if (preg_match('/^#[A-Z_]+$/S', $token))
            {
                $nodes[] = [
                    'applyDefault'  =>  true,
                    'name'          =>  strtolower(substr($token, 1)),
                ];
            }
            // Extended tag
            else if (preg_match('/^\(#([A-Z_]+)(\*)?(?:\|([^\)]+)*)*\)$/S', $token, $match))
            {
                // There was an opening bracket before, so it's a valid extended tag
                if (isset($parent['name']))
                {
                    $parent['name'] = strtolower($match[1]);
                    $parent['applyDefault'] = empty($match[2]) ? true : false;

                    if (!empty($match[3]))
                    {
                        // Parse modifiers
                        $parent['modifiers'] = explode('|', $match[3]);
                        foreach ($parent['modifiers'] as &$modifier)
                        {
                            preg_match('/^([0-9a-z_><!=?-]+)(?:\{(.*)\})?$/i', $modifier, $match_mod);

                            if (!isset($match_mod[1]))
                            {
                                throw new MiniSkelMarkupException("Invalid modifier syntax: ".$modifier);
                            }

                            $modifier = ['name' => $match_mod[1], 'arguments' => []];

                            if (isset($match_mod[2]))
                            {
                                preg_match_all('/["\']?([^"\',]+)["\']?/', $match_mod[2], $match_args, PREG_SET_ORDER);
                                foreach ($match_args as $arg)
                                {
                                    $arg = trim($arg[1]);
                                    $modifier['arguments'][] = $arg ? $this->parseVariables($arg, self::CONTEXT_IN_ARG) : $arg;
                                }
                            }
                        }

                    }

                    return $nodes;
                }
                // Not a valid tag, treat it as simple text node
                else
                {
                    $nodes[] = $token;
                }
            }

            $i++;
        }

        return $nodes;
    }

    /**
     * Returns a text string out of supplied nodes
     * @param  array  $nodes Array of nodes
     * @return string        Output of variables
     */
    protected function outputVariables($nodes, $context)
    {
        $out = '';

        foreach ($nodes as $node)
        {
            if (is_array($node))
            {
                // Not a tag, just something between brackets
                if ($node['name'] === false)
                {
                    $out .= '[';
                    $out .= isset($node['pre']) ? $this->outputVariables($node['pre'], $context) : '';
                    $out .= isset($node['post']) ? $this->outputVariables($node['post'], $context) : '';
                    $out .= ']';
                }
                // [(#REM) Comments] comments are ignored
                elseif ($node['name'] != 'rem')
                {
                    $out .= $this->processVariable($node['name'],
                        $node['applyDefault'],
                        isset($node['modifiers']) ? $node['modifiers'] : [],
                        isset($node['pre']) ? $this->outputVariables($node['pre'], $context) : '',
                        isset($node['post']) ? $this->outputVariables($node['post'], $context) : '',
                        $context);
                }
            }
            else
            {
                $out .= $node;
            }
        }

        return $out;
    }

    /**
     * Internal parsing of variables
     * You can't extend this method
     *
     * @param string $content
     * @param int $context (Constant)
     */
    protected function parseVariables($content, $context = self::CONTEXT_GLOBAL)
    {
        $variables_split_text = preg_split('/((?<!\\\\)[\[\]]|\(#[A-Z_]+\*?(?:\|(?:[^\)]+)*)*\)|(?<!\\\\)#(?:[A-Z_]+))/S', $content, null, PREG_SPLIT_DELIM_CAPTURE);

        $nodes = $this->_buildVariablesTree($variables_split_text);
        unset($variables_split_text);

        return $this->outputVariables($nodes, $context);
    }

    protected function parseIncludes($content)
    {
        preg_match_all('/<'.$this->includeTagName.'\{(.*)\}>/U', $content, $match, PREG_SET_ORDER);

        if (empty($match))
            return $content;

        foreach ($match as $m)
        {
            $m_args = explode(',', $m[1]);
            $args = array();

            foreach ($m_args as $m_arg)
            {
                $m_arg = trim($m_arg);
                $m_arg = explode('=', $m_arg);
                $args[trim($m_arg[0])] = isset($m_arg[1]) ? trim($m_arg[1]) : true;
            }

            $content = $this->replaceFirst($m[0], $this->processInclude($args), $content);
        }

        unset($m_arg, $args, $m, $match);
        return $content;
    }

    /**
     * Here we call modifiers
     * It's just a standard method doing simple things
     * You're encouraged to rewrite this method to suit your needs
     */
    protected function callModifier($name, $value, $args=false)
    {
        $method_name = 'variableModifier_'.$name;

        // We can use internal methods as modifiers
        if (method_exists($this, $method_name))
        {
            $value = $this->$method_name($value, $args);
        }

        // Are external functions or objects
        elseif (isset($this->modifiers[$name]))
        {
            $value = call_user_func($this->modifiers[$name], $value, $args);
        }

        // Default is just an escape, but you can change this
        elseif ($name == 'default')
        {
            $value = htmlspecialchars($value, ENT_QUOTES);
        }

        // Strict mode throw an exception here if we try to use an undefined modifier
        elseif ($this->strictMode)
        {
            throw new MiniSkelMarkupException("Modifier '".$name."' isn't defined in loop '".$this->currentLoop."'.");
        }

        return $value;
    }

    /**
     * Here we process the loop
     * This is somehow basic, but a good example
     * You're encouraged to extend this method to suit your needs
     */
    protected function processLoop($loopName, $loopType, $loopCriterias, $loopContent, $preContent, $postContent, $altContent)
    {
        $out = '';

        // We can call an internal method (use extends !) to match the loop type
        $method_name = 'processLoopType_' . $loopType;

        if (!method_exists($this, $method_name))
        {
            throw new MiniSkelException("There is no known '".$loopType."' loop type.");
        }

        $loopContent = $this->$method_name($loopCriterias, $loopContent);

        // If the loop isn't empty (!=false)
        if ($loopContent)
        {
            // we put the pre-content before the loop content
            if ($preContent)
            {
                $out .= $this->parse($preContent, $loopName, self::PRE_CONTENT);
            }

            $out .= $loopContent;

            // we put the post-content after the loop content
            if ($postContent)
            {
                $out .= $this->parse($postContent, $loopName, self::POST_CONTENT);
            }
        }

        // If the loop is empty and we have an alternate content we show it
        else
        {
            if ($altContent)
            {
                $out .= $this->parse($altContent, $loopName, self::ALT_CONTENT);
            }
        }

        return $out;
    }

    /**
     * Here we process a single variable
     * You're encouraged to extend this method to suit your needs
     *
     * @param string $name
     * @param bool $applyDefault Apply the default modifier ?
     * @param array $modifiers Modifiers to apply
     * @param string $pre Optional pre-content
     * @param string $post Optional $post-content
     * @param bool $context Variable context (may be self::CONTEXT_GLOBAL or self::CONTEXT_IN_LOOP)
     */
    protected function processVariable($name, $applyDefault, $modifiers, $pre, $post, $context)
    {
        // If $value == false it seems it's not set in the variables array used in the loop,
        // so maybe it's a global variable that we want (but you can change this)
        if ($value === false && isset($this->variables[$name]))
        {
            $value = $this->variables[$name];
        }

        // The applyDefault bit is used here to apply a modifier, but you can use it for some other things
        if ($applyDefault)
            $value = $this->callModifier('default', $value);

        // We process modifiers
        foreach ($modifiers as &$modifier)
        {
            $value = $this->callModifier($modifier['name'], $value, $modifier['arguments']);
        }

        // It's important to put this here, because we can have tricky things like:
        // [(#TITLE|orIfEmpty{"Empty title"})]
        // where the orIfEmpty modifier will replace the $value with "Empty title" if $value is empty
        // so $value is not empty anymore after the modifier call
        if (empty($value))
        {
            return '';
        }

        $out = '';

        // Getting pre-content
        if ($pre)
            $out .= $this->parseVariables($pre, $context);

        $out .= $value;

        // Getting post-content
        if ($post)
            $out .= $this->parseVariables($post, $context);

        return $out;
    }

    /**
     * Processing an include instruction
     */
    protected function processInclude($args)
    {
        if (empty($args))
            throw new MiniSkelMarkupException($this->includeTagName . ' requires at least an argument');

        $file = key($args);
        return $this->fetch($file);
    }

    /**
     * Parsing a text section for loops and global variables
     * You're encouraged to rewrite this method to suit your needs
     *
     * @param string $content
     * @param string $parent The parent loop, if this function is called inside a loop
     * @param string $content_type The content type, like self::LOOP_CONTENT and others
     */
    protected function parse($content, $parent=false, $content_type=false)
    {
        $content = $this->parseIncludes($content);
        $content = $this->parseLoops($content, $parent);
        $content = $this->parseVariables($content, $this->variables, self::CONTEXT_GLOBAL);
        return $content;
    }

    /**
     * Like in smarty we can assign global variables in the template
     */
    public function assign($name, $value)
    {
        $this->variables[$name] = $value;
    }

    /**
     * Like in smarty we can register external modifiers
     */
    public function register_modifier($name, $function)
    {
        $this->modifiers[$name] = $function;
    }

    /**
     * Returns the parsed template file $template
     */
    public function fetch($template)
    {
        $this->currentTemplate = $template;
        $template = file_get_contents($this->template_path . $template);
        return $this->parse($template);
    }

    /**
     * Displays the parsed template file $template
     */
    public function display($template)
    {
        echo $this->fetch($template);
    }
}

?>