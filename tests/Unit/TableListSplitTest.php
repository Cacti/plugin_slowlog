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

/*
 * The Tables of Interest field is a textarea, so the list can be separated by spaces, tabs
 * or line breaks. Splitting only on a single space left "a\nb" as one unmatched name and
 * produced empty names for repeated spaces.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 1);
});

function slowlog_test_listed_tables(): array {
	$names = array();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details_tables') !== false && strpos($call['sql'], 'query LIKE') !== false) {
			$names[] = $call['params'][1];
		}
	}

	return $names;
}

it('splits an explicit table list on any whitespace and skips empty names', function () {
	import_post_process(1, "accounts\n users\t\tgroups  ", false, 'list');

	expect(slowlog_test_listed_tables())->toBe(array('accounts', 'users', 'groups'));
});

it('compares a newline separated reference list table by table', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'import_tables', "host\ngraph_local\n");
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT table_name', array(
		array('table_name' => 'host'),
		array('table_name' => 'graph_local'),
	));

	import_post_process(1, '', false, 'reference');

	$classified = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_fetch_assoc_prepared' && strpos($call['sql'], 'AND table_name IN') !== false;
	});

	// both tables are in the reference list, so nothing is grouped as OTHER TABLES
	expect($classified)->toBeEmpty();
});
