<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

describe('details filter output escaping in slowlog.php', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	$required = array(
		"<input type='text' class='ui-state-default ui-corner-all' id='date1' size='18' value='<?php print html_escape_request_var('date1');?>'>",
		"<input type='text' class='ui-state-default ui-corner-all' id='date2' size='18' value='<?php print html_escape_request_var('date2');?>'>",
	);

	foreach ($required as $snippet) {
		it("contains escaped details filter output: {$snippet}", function () use ($source, $snippet) {
			expect($source)->toContain($snippet);
		});
	}
});
