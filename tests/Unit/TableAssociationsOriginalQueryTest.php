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
 * The import stores the query with its line breaks collapsed. A trailing comment then runs
 * to the end of the collapsed text, so table detection has to read the original query,
 * which keeps its newlines, when it is available.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

function slowlog_test_table_rows(): array {
	$names = array();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details_tables') !== false) {
			for ($i = 2; $i < count($call['params']); $i += 3) {
				$names[] = $call['params'][$i];
			}
		}
	}

	return $names;
}

it('finds a table that follows a trailing comment on an earlier line', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details', array(array(
		'logentry' => 1,
		'query'    => 'SELECT * FROM a -- pick rows JOIN b ON a.id = b.id WHERE 1 ',
		'oquery'   => "SELECT * FROM a -- pick rows\nJOIN b ON a.id = b.id\nWHERE 1\n",
	)));

	get_table_associations(1);

	expect(slowlog_test_table_rows())->toBe(array('a', 'b'));
});

it('falls back to the stored query when there is no original text', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details', array(array(
		'logentry' => 1,
		'query'    => 'SELECT * FROM a JOIN b ON a.id = b.id',
		'oquery'   => '',
	)));

	get_table_associations(1);

	expect(slowlog_test_table_rows())->toBe(array('a', 'b'));
});
