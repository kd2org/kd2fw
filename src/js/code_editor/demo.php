<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title>CodeEditor demo</title>
	<link rel="stylesheet" type="text/css" href="code_editor.css" />
</head>

<body>

<fieldset>
	<legend>Edit some code</legend>
	<p><textarea name="code" id="f_code" rows="15" cols="70"><?php 
	echo htmlspecialchars(file_get_contents(__DIR__ . '/code_editor.js')); 
	?></textarea></p>
</fieldset>

<script src="../text_editor/text_editor.js" type="text/javascript"></script>
<script src="code_editor.js" type="text/javascript"></script>
<script type="text/javascript">
var code = new codeEditor('f_code');
</script>

</body>
</html>