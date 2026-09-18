<?php

$source = file_get_contents(dirname(__DIR__, 2) . '/slowlog.php');

if ($source === false) {
	fwrite(STDERR, "Unable to read slowlog.php\n");
	exit(1);
}

$required = array(
	"<input type='text' class='ui-state-default ui-corner-all' id='date1' size='18' value='<?php print html_escape_request_var('date1');?>'>",
	"<input type='text' class='ui-state-default ui-corner-all' id='date2' size='18' value='<?php print html_escape_request_var('date2');?>'>",
);

foreach ($required as $snippet) {
	if (strpos($source, $snippet) === false) {
		fwrite(STDERR, "Missing expected escaped details filter output\n");
		exit(1);
	}
}

echo "OK\n";
