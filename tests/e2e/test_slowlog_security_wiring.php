<?php

$source = file_get_contents(dirname(__DIR__, 2) . '/slowlog.php');

if ($source === false) {
	fwrite(STDERR, "Unable to read slowlog.php\n");
	exit(1);
}

$expectations = array(
	'summary filter uses prepared placeholder' => "(description LIKE ?)",
	'summary filter binds wildcard value'      => "\$sql_params[] = '%' . get_request_var('filter') . '%';",
	'date1 request is escaped in UI'           => "html_escape_request_var('date1')",
	'date2 request is escaped in UI'           => "html_escape_request_var('date2')",
	'tab is JSON encoded for JS'               => "json_encode(get_request_var('tab'))",
	'logid is integer cast for JS'             => "(int)get_request_var('logid')",
);

foreach ($expectations as $label => $needle) {
	if (strpos($source, $needle) === false) {
		fwrite(STDERR, "Missing expected security wiring: $label\n");
		exit(1);
	}
}

echo "OK\n";
