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

describe('filter SQL and JS guards in slowlog.php', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	it('uses a prepared LIKE placeholder in slowlog_view()', function () use ($source) {
		expect($source)->toContain("(description LIKE ?)");
	});

	it('does not use legacy raw SQL filter concatenation', function () use ($source) {
		expect($source)->not->toContain("description LIKE '%%");
	});

	it('JSON-encodes the pageTab assignment for JS', function () use ($source) {
		expect($source)->toContain("var pageTab   = <?php print json_encode(get_request_var('tab'));?>;");
	});

	it('integer-casts the logid assignment for JS', function () use ($source) {
		expect($source)->toContain("var logid     = <?php print (int)get_request_var('logid');?>;");
	});
});
