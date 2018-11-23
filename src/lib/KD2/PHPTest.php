<?php

namespace KD2;

class PHPTest
{
	protected $sections = ['EXPECT', 'EXPECTF', 'FILE', 'TEST', 'SKIPIF', 'INI'];

	protected $regexpf = [
		'%d' => '\d+',
		'%s' => '[^\r\n]+',
	];

	public $ini_settings = [
		'max_execution_time' => 30,
	];

	public function parse($file)
	{
		$sections = [];
		$name = null;

		foreach (file($file) as $i => $line)
		{
			if (substr($line, 0, 2) == '--' && substr(rtrim($line, "\r\n"), -2) == '--')
			{
				$name = substr(rtrim($line, "\r\n"), 2, -2);

				if (!in_array($name, $this->sections))
				{
					throw new \RuntimeException(sprintf('Invalid section name "%s" in %s line %d', $name, $file, $i+1));
				}

				$sections[$name] = '';

				continue;
			}
			elseif (!$name)
			{
				throw new \RuntimeException('Invalid file: no section in ' . $file);
			}

			$sections[$name] .= $line;
		}

		if (!isset($sections['FILE'], $sections['TEST']) || (!isset($sections['EXPECT']) && !isset($sections['EXPECTF'])))
		{
			throw new \RuntimeException(sprintf('Invalid file: no FILE, EXPECT or EXPECTF sections in %s', $file));
		}

		return $sections;
	}

	public function exec($file, array $ini)
	{
		$ini_args = [];

		foreach ($ini as $k=>$v)
		{
			$ini_args[] = '-d ' . escapeshellarg($k . '=' . $v);
		}

		$cmd = sprintf('php %s %s', implode(' ', $ini_args), escapeshellarg($file));
		return shell_exec(escapeshellcmd($cmd) . ' 2>&1');
	}

	public function run($file)
	{
		$sections = $this->parse($file);

		$php = getenv('PHP_TEST_EXECUTABLE') ?: 'php';

		@unlink($file . '.php');
		@unlink($file . '.diff');
		@unlink($file . '.out');
		@unlink($file . '.skip');

		if (isset($sections['SKIPIF']))
		{
			file_put_contents($file . '.skipif', $sections['SKIPIF']);
			$result = $this->exec($file . '.skipif', $this->ini_settings);
			@unlink($file . '.skip');

			if (trim($result) !== '')
			{
				$this->log('SKIP', $sections['TEST'], $result);
				return true;
			}
		}

		$ini = $this->ini_settings;

		if (isset($sections['INI']))
		{
			$ini = array_merge($ini, parse_ini_string($sections['INI']));
		}

		file_put_contents($file . '.php', $sections['FILE']);

		$out = $this->exec($file . '.php', $ini);

		$out = preg_replace("/\r\n/", "\n", $out);

		file_put_contents($file . '.out', $out);

		if (isset($sections['EXPECT']))
		{
			$exp = $sections['EXPECT'];
		}
		else
		{
			$exp = $sections['EXPECTF'];
			$exp = preg_quote($exp);
			$exp = strtr($exp, $this->regexpf);
		}

		$exp = preg_replace("/\r\n/", "\n", $exp);

		$diff = $this->diff($exp, $out, isset($sections['EXPECTF']));

		if ($diff)
		{
			$this->log('FAIL', $sections['TEST']);
			file_put_contents($file . '.diff', $diff);
			return false;
		}

		@unlink($file . '.php');
		$this->log('PASS', $sections['TEST']);

		return true;
	}

	public function diff($a, $b, $is_regexp = false)
	{
		return SimpleDiff::diff($a, $b);
	}

	public function log($type, $test, $details = null)
	{
		printf("[%s] %s", $type, $test);

		if ($details)
		{
			echo PHP_EOL . $details;
		}

		echo PHP_EOL;
	}

	public function runAll($path)
	{
		$dir = dir($path);
		$tests = [];

		while ($file = $dir->read())
		{
			if ($file[0] == '.')
			{
				continue;
			}

			if (substr($file, -5) == '.phpt')
			{
				$tests[] = $path . DIRECTORY_SEPARATOR . $file;
			}
		}

		$dir->close();

		echo '... ' . $path . PHP_EOL;

		$count = 0;

		foreach ($tests as $test)
		{
			$count += (int) $this->run($test);
		}

		echo PHP_EOL;
		printf("Results:\n - %d tests failed\n- %d tests passed\n", count($tests) - $count, count($tests));
	}
}