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
 * Regression test for a fixed bug: import_post_process() used to assign $start only
 * inside `if ($records > 0) { ... }`, but read $end - $start in a cacti_log() call after
 * that block regardless of $records, so a logid with zero plugin_slowlog_details rows
 * triggered an undefined variable warning. The stray trailing log statement was removed
 * (the correctly-scoped one inside the if-block already covers it). Guarded with a scoped
 * error handler rather than relying on phpunit.xml's failOnWarning to catch a regression.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 0);
});

it('does not warn on an undefined $start when a log has zero detail rows', function () {
	$captured = null;

	set_error_handler(function ($errno, $errstr) use (&$captured) {
		$captured = $errstr;

		return true;
	}, E_WARNING);

	import_post_process(1);

	restore_error_handler();

	expect($captured)->toBeNull();
});
