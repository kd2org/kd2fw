<?php

define('STORAGE_DIR', __DIR__);

define('MAX_UPLOAD_SIZE', min([
    return_bytes(ini_get('upload_max_filesize')),
    return_bytes(ini_get('post_max_size'))
]));

$nb = 0;

class FileManager
{
	protected $files = [];

	public function __construct()
	{
		// Fix _FILES array as a multidimensional array
		if (!empty($_FILES))
		{
			$this->files = $_FILES;

			foreach ($this->files as $field_name=>&$value)
			{
				if (is_array($value['name']))
				{
					$new = [];

					foreach ($value as $key => $all)
					{
						foreach ($all as $i => $val)
						{
							$new[$i][$key] = $val;   
						}   
					}

					$value = $new;
				}
			}
		}
	}

	public function store($file)
	{
		if (empty($file['size']) || empty($file['tmp_name']) || !empty($file['error']))
		{
			return $file['error'];
		}

		if (count($this->files) == 1 
			&& !empty($_POST['uploadHelper_fileHash'])
			&& preg_match('/^[a-f0-9]+$/', $_POST['uploadHelper_fileHash']))
		{
			// Original file hash was found by Javascript
			$hash = $_POST['uploadHelper_fileHash'];
		}
		else
		{
			$hash = sha1_file($file['tmp_name']);
		}

		$path = STORAGE_DIR . '/upload_' . $hash;
		
		if (file_exists($path))
			return true;

		return move_uploaded_file($file['tmp_name'], $path);
	}

	public function storeAll()
	{
		$i = 0;

		if (empty($this->files))
			return 0;

		foreach ($this->files['myFile'] as $file)
		{
			if (!$this->store($file))
			{
				return false;
			}
			$i++;
		}

		return $i;
	}

	public function check($hash)
	{
		if (!preg_match('/^[a-f0-9]+$/', $hash))
		{
			throw new \RuntimeException($hash . ' is not a valid SHA1 hash');
		}

		$path = STORAGE_DIR . '/upload_' . $hash;
		return file_exists($path);
	}
}

$fm = new FileManager;

function return_bytes ($size_str)
{
	if ($size_str == -1)
	{
		return null;
	}

    switch (substr($size_str, -1))
    {
        case 'G': case 'g': return (int)$size_str * pow(1024, 3);
        case 'M': case 'm': return (int)$size_str * pow(1024, 2);
        case 'K': case 'k': return (int)$size_str * 1024;
        default: return $size_str;
    }
}

// Hash check
if (!empty($_POST['uploadHelper_hashCheck']) && is_array($_POST['uploadHelper_hashCheck']))
{
	$exist = [];

	foreach ($_POST['uploadHelper_hashCheck'] as $hash)
	{
		if ($fm->check($hash))
		{
			$exist[$hash] = true;
		}
	}

	echo json_encode($exist);
	exit;
}

if (!empty($_POST))
{
	if (isset($_POST['uploadHelper_status']))
	{
		$status = (int) $_POST['uploadHelper_status'];

		if ($fm->storeAll() === false)
		{
			$return = ['error' => 'Storage error.'];
		}
		else if ($status == 0)
		{
			$return = ['redirect' => './demo.php?ok'];
		}
		else
		{
			$return = ['next' => true];
		}

		echo json_encode($return);
		exit;
	}
	else if (!empty($_FILES))
	{
		$nb = $fm->storeAll();
	}
}

?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title>Upload.js demo</title>
	<script type="text/javascript" src="../upload_helper.js"></script>
</head>

<body>
<?php if ($nb): ?>
	<h2><?=$nb?> files uploaded without the upload helper</h2>
<?php elseif (isset($_GET['ok'])): ?>
	<h2>Upload was completed using upload helper!</h2>
<?php elseif (isset($_GET['already_ok'])): ?>
	<h2>All files were already uploaded!</h2>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" action="demo.php">
    <fieldset>
        <legend>Upload a file</legend>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?=MAX_UPLOAD_SIZE?>" />
        <p>Your name : <input type="text" name="myName" value="Calvin Hobbes" /></p>
        <p><input type="file" name="myFile[]" id="myFile" multiple data-hash-check /></p>
        <p><input type="submit" name="submit" value="Upload" /></p>
    </fieldset>
</form>

<script>
window.uploadHelper(document.forms[0].myFile, {
	width: -200,
	height: null,
	resize: true,
	bytes: 'o',
	size_error_msg: 'Le fichier %file fait %size, soit plus que la taille maximale autorisée de %max_size.'
});
</script>

</body>
</html>