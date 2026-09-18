<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
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
