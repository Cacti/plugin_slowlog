<?php

$source = file_get_contents(dirname(__DIR__, 2) . '/slowlog.php');

if ($source === false) {
	fwrite(STDERR, "Unable to read slowlog.php\n");
	exit(1);
}

if (strpos($source, "(description LIKE ?)") === false) {
	fwrite(STDERR, "Expected prepared LIKE placeholder in slowlog_view()\n");
	exit(1);
}

if (strpos($source, "description LIKE '%%") !== false) {
	fwrite(STDERR, "Found legacy raw SQL filter concatenation\n");
	exit(1);
}

if (strpos($source, "var pageTab   = <?php print json_encode(get_request_var('tab'));?>;") === false) {
	fwrite(STDERR, "Expected JSON-encoded pageTab assignment\n");
	exit(1);
}

if (strpos($source, "var logid     = <?php print (int)get_request_var('logid');?>;") === false) {
	fwrite(STDERR, "Expected integer-cast logid assignment\n");
	exit(1);
}

echo "OK\n";
