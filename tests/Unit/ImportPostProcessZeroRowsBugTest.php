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

/*
 * KNOWN DEFECT regression test.
 *
 * import_post_process() only assigns $start inside `if ($records > 0) { ... }`,
 * but reads $end - $start in a cacti_log() call after that block regardless of
 * $records. When a logid has zero plugin_slowlog_details rows, that reference
 * to $start is undefined. This test documents the bug with a scoped error
 * handler rather than letting it fail the suite via phpunit.xml's
 * failOnWarning, so it stays visible until fixed.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 0);
});

it('KNOWN DEFECT: warns on an undefined $start when a log has zero detail rows', function () {
	$captured = null;

	set_error_handler(function ($errno, $errstr) use (&$captured) {
		$captured = $errstr;

		return true;
	}, E_WARNING);

	import_post_process(1);

	restore_error_handler();

	expect($captured)->toContain('$start');
});
