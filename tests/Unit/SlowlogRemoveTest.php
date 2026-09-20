<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * api_slowlog_remove() must delete every per-logid row this plugin ever writes - a table
 * added here later (like the v2.4 plugin_slowlog_stats cache) is easy to forget and leaves
 * orphaned rows behind once its parent plugin_slowlog row is deleted.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
});

it('deletes from every per-logid table, including the stats cache', function () {
	api_slowlog_remove(7);

	$deletes = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'DELETE FROM') !== false;
	}));

	$tables = array_map(function ($call) {
		preg_match('/DELETE FROM (\w+)/', $call['sql'], $m);
		return $m[1];
	}, $deletes);

	expect($tables)->toBe(array(
		'plugin_slowlog',
		'plugin_slowlog_details',
		'plugin_slowlog_tables',
		'plugin_slowlog_details_tables',
		'plugin_slowlog_details_methods',
		'plugin_slowlog_stats',
	));

	foreach ($deletes as $call) {
		expect($call['params'])->toBe(array(7));
	}
});
