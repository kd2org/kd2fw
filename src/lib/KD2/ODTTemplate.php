<?php

namespace KD2;

use ZipArchive;
use DOMDocument;
use DOMXPath;
use DOMNode;

/*
Recherche de caractère :
{ dans n'importe quel nœud texte, 1ère occurrence
{ dans n'importe quel nœud texte tout de suite après le premier
autant d'espaces que voulu
a-z dans nœud texte

algo :
si caractère trouvé, aller au suivant (pos +1), si la chaîne est terminée, aller au nœud suivant (nextSibling), en parcourant éventuellement ses enfants (en ignorant les espaces), s'arrêter dès qu'un autre caractère que celui recherché est rencontré

TODO : gérer les FODT

Clean case:
<text:h text:style-name="P4" text:outline-level="1">Facture n° <text:span text:style-name="T4">{{numero_facture}}</text:span></text:h>

Mixed case:
<text:p text:style-name="P3"><text:span text:style-name="T3">Association : </text:span>{{ nom_asso }} <text:span text:style-name="T8">(loi 1901)</text:span></text:p>

Ugly case:
<text:p text:style-name="P3">{{ <text:span text:style-name="T10">adres</text:span>se_<text:span text:style-name="T1">as</text:span>so }}</text:p>

Still ugly:
<text:p text:style-name="P4">{{ siret_asso <text:span text:style-name="T10">}</text:span>}</text:p>

Table:
    <table:table-row>
     <table:table-cell table:style-name="Tableau1.A3" office:value-type="string">
      <text:p text:style-name="P10">{{ <text:span text:style-name="T5">lignes.</text:span>libelle }}</text:p>
     </table:table-cell>

 */

/**
 * @see https://github.com/iCircle/docxTemplateInPHP/blob/master/src/Template/Docx/DocxTemplate.php
 * @see https://github.com/berduj/odtphp/blob/master/src/Segment.php
 * @see https://github.com/PHPOffice/PHPWord/blob/develop/src/PhpWord/TemplateProcessor.php
 * @see https://github.com/lkhl2003/phpdocx/blob/master/Classes/Phpdocx/Create/CreateDocxFromTemplate.inc
 * @see https://mustache.github.io/mustache.5.html
 * @see https://docxtemplater.com/demo/#loop-table
 * @see https://github.com/open-xml-templating/docxtemplater/blob/master/es6/xml-matcher.js
 * @see https://carbone.io/documentation.html#repetitions
 * @see https://github.com/Ideolys/carbone/blob/master/lib/extracter.js
 */
class ODTTemplate
{
	const XML_FILE_NAME = 'content.xml';

	protected $source;
	protected $xml;
	protected $dom;
	protected $zip;
	protected $variables = [];

	public function __construct($source)
	{
		assert(is_string($source));

		if (!is_readable($source)) {
			throw new \RuntimeException('Cannot open file for reading: ' . $source);
		}

		$this->source = $source;
	}

	public function open()
	{
		$this->readZip($this->source);
	}

	protected function readZip($source)
	{
		$this->zip = new ZipArchive;
		$this->zip->open($source);
		$this->xml = $this->zip->getFromName(self::XML_FILE_NAME);
		$this->dom = new DOMDocument;
		$this->dom->preserveWhiteSpace = false;
		$this->dom->loadXML($this->xml);
	}

	protected function replaceInZip()
	{
		$this->zip->deleteName(self::XML_FILE_NAME);
		$this->zip->addFromString(self::XML_FILE_NAME, $this->replace());
	}

	public function replace()
	{
		$p_list = $this->dom->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'p');

		if (!$p_list->count()) {
			return;
		}

		foreach ($p_list as $p) {
			if (preg_match('/\{\{\s*([\w.]+)(?:\s*\+\s*(1))?\s*\}\}/', $p->nodeValue, $match)) {
				$this->replaceTags($p);
			}
		}

		$this->dom->formatOutput = true;
		var_dump($this->dom->saveXML());

		exit;
	}

	protected function replaceTags(DOMNode $node)
	{
		$nodeList = [];

		$root = $this->findStartPattern($node);
		var_dump($root);
	}

	protected function findNextPattern(DOMNode $node, $pattern, $pos = 0)
	{
		if ($node->nodeType == XML_TEXT_NODE) {
			if ($pos >= strlen($node->nodeValue)) {
				return findNextPattern($node->nextSibling);
			}
			return strpos('{', $node->nodeValue)) {
				return $node->parentNode;
			}
		}
		elseif ($node->hasChildNodes()) {
			foreach ($node->childNodes as $child) {
				if ($this->findStartPattern($child)) {
					return $node;
				}
			}
		}

		return null;
	}

	protected function getNodeDepth(DOMNode $node)
	{
		$depth = 0;
		$a = $node;

		while ($a = $a->parentNode) {
			$depth++;
		}

		return $depth;
	}

	protected function matchPatternInNode(DOMNode $node, $pattern)
	{
		if (substr($pattern, -1) === '+') {
			$pattern = substr($pattern, 0, -1);
				return false;
			while ($this->matchPatternInNode($node, $pattern)) {
			}
		}
		$value = trim($node->textContent);

		$position = isset($node->matchPatternPosition) ? $node->matchPatternPosition : 0;

		if ($position >= strlen($value)) {
			return false;
		}

		if (!$this->matchPattern($value, $pattern, $position)) {
			return false;
		}

		$node->matchPatternPosition = $position + 1;
	}

	protected function matchPattern($value, $pattern, $position)
	{
		$char = substr($value, $position, 1);

		switch ($pattern) {
			case '{':
			case '}':
				return $char === $pattern;
			case ' ':
				return trim($char) === '';
			case 'az':
				return ctype_alpha($char);
			case 'az.':
				return ctype_alpha(str_replace('.', '', $char));
			default:
				return false;
		}
	}

	protected function matchNextPatternInNode(DOMNode $node, $pattern)
	{
		if ($node->nodeType == XML_TEXT_NODE) {
			return $this->matchPatternInNode($node, $pattern);
		}
		elseif ($node->hasChildNodes()) {
			return $this->matchNextPatternInNode($node->firstChild, $pattern);
		}

		return false;
	}

	protected function findTag(DOMNode $node)
	{
		$patterns = ['{', '{', ' ', 'az', 'az.+', 'az+', ' ', '}', '}'];
		$nodes = [];

		foreach ($patterns as $pattern) {
			if ($found = $this->matchNextPatternInNode($node, $pattern)) {
				if (!in_array($found, $nodes, true)) {
					$nodes[] = $found;
				}
			}
			else {
				return false;
			}
		}

		return $nodes;
	}

	protected function findAndReplaceTags(DOMNode &$node, $depth, $min_depth)
	{
		$text = $node->nodeValue;
		var_dump($text, $this->findTag($node));

		preg_match_all('/\{\{\s*([\w.]+)(?:\s*\+\s*(1))?\s*\}\}/', $text, $tags, PREG_SET_ORDER);

		if (!count($tags)) {
			if ($depth > $min_depth) {
				$this->findAndReplaceTags($node->parentNode, --$depth, $min_depth);
			}

			return;
		}

		foreach ($tags as $tag) {
			$text = str_replace($tag[0], 'LOOOL', $text);//$this->variables[$tag[1]], $text);
		}

		// This will flatten the node (remove any children)
		$node->nodeValue = $text;

	}

	public function getXML()
	{
		return $this->xml;
	}

	public function assign($key, $value = null)
	{
		if (is_array($key) && null === $value) {
			foreach ($key as $subkey => $value) {
				$this->assign($subkey, $value);
			}
		}
		else {
			$this->variables[$key] = $value;
		}
	}

	public function save($destination)
	{
		if (!is_writeable($destination)) {
			throw new \RuntimeException('Cannot open file for writing: ' . $destination);
		}

		copy($this->source, $destination);

		$this->readZip($destination);
		$this->replaceInZip();
		$this->zip->close();
	}

	public function output()
	{
		$tempfile = tempnam(sys_get_temp_dir(), 'odttmp');
		copy($this->source, $tempfile);

		$this->readZip($tempfile);
		$this->replaceInZip();
		$this->zip->close();

		readfile($tempfile);
		unlink($tempfile);
	}
}