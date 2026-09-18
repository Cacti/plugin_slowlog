<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

describe('slowlog security wiring', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	$expectations = array(
		'summary filter uses prepared placeholder' => "(description LIKE ?)",
		'summary filter binds wildcard value'      => "\$sql_params[] = '%' . get_request_var('filter') . '%';",
		'date1 request is escaped in UI'           => "html_escape_request_var('date1')",
		'date2 request is escaped in UI'           => "html_escape_request_var('date2')",
		'tab is JSON encoded for JS'               => "json_encode(get_request_var('tab'))",
		'logid is integer cast for JS'             => "(int)get_request_var('logid')",
	);

	foreach ($expectations as $label => $needle) {
		it($label, function () use ($source, $needle) {
			expect($source)->toContain($needle);
		});
	}
});
