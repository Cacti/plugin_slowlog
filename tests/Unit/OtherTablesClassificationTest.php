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
 * 'OTHER TABLES' is a separate method from 'OTHERS': OTHERS flags a query that didn't match
 * any known SQL construct at all; OTHER TABLES flags a query that references at least one
 * table not recognized as a Cacti table (per plugin_slowlog_table_names.is_cacti_table). The
 * two are independent and a row can carry either, both, or neither.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_method_calls')) {
	function slowlog_test_method_calls(): array {
		return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false;
		}));
	}

	function slowlog_test_method_tuples(): array {
		$tuples = array();

		foreach (slowlog_test_method_calls() as $call) {
			foreach (array_chunk($call['params'], 3) as $t) {
				$tuples[] = array((int) $t[0], (int) $t[1], (int) $t[2]);
			}
		}

		return $tuples;
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', "WHERE method = 'OTHER TABLES'", 22);
});

it('tags a logentry that touches a non-Cacti table', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT dt.logentry', array(
		array('logentry' => 2),
	));

	slowlog_classify_other_tables(1);

	expect(slowlog_test_method_tuples())->toBe(array(array(1, 2, 22)));
});

it('does nothing when the OTHER TABLES method row does not exist', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', "WHERE method = 'OTHER TABLES'", 0);
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT dt.logentry', array(
		array('logentry' => 2),
	));

	slowlog_classify_other_tables(1);

	expect(slowlog_test_method_calls())->toBe(array());
});

it('does nothing when every table is a recognized Cacti table', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT dt.logentry', array());

	slowlog_classify_other_tables(1);

	expect(slowlog_test_method_calls())->toBe(array());
});

it('is only invoked from import_post_process() when usecacti is used', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 3);
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_methods', array(
		array('method' => 'SELECTS', 'query' => 'SELECT ', 'methodid' => 4),
		array('method' => 'OTHERS', 'query' => 'OTHERS', 'methodid' => 8),
		array('method' => 'OTHER TABLES', 'query' => 'OTHER TABLES', 'methodid' => 22),
	));
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'select * from mystery_tbl'),
	));
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT dt.logentry', array(
		array('logentry' => 1),
	));

	import_post_process(1, 'mystery_tbl', false);

	expect(slowlog_test_method_tuples())->toBe(array(array(1, 1, 4)));
});

it("does not let the 'OTHER TABLES' fragment leak into ordinary query-text matching", function () {
	// If 'OTHER TABLES' were treated like a normal fragment-matched method, stripos() against
	// its own (never-appearing-in-real-SQL) fragment would simply never match - this proves it
	// is excluded from that loop entirely rather than relying on it coincidentally not matching.
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog_functions.php'));

	expect($source)->toContain("\$row['method'] != 'OTHER TABLES'");
});

/*
 * slowlog_classify_other_tables_against_list() is 'reference' mode's counterpart to
 * slowlog_classify_other_tables(): same tagging, but compared against a caller-supplied list
 * instead of the shared plugin_slowlog_table_names.is_cacti_table flag, so a per-import
 * reference list never has to (and must not) overwrite that shared dictionary column.
 */
it('tags a logentry whose table is missing from the supplied reference list', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT table_name', array(
		array('table_name' => 'host'),
		array('table_name' => 'mystery_tbl'),
	));
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'AND table_name IN', array(
		array('logentry' => 2),
	));

	slowlog_classify_other_tables_against_list(1, array('host'));

	expect(slowlog_test_method_tuples())->toBe(array(array(1, 2, 22)));
});

it('does nothing when every table is in the supplied reference list', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT table_name', array(
		array('table_name' => 'host'),
	));

	slowlog_classify_other_tables_against_list(1, array('host', 'graph_local'));

	expect(slowlog_test_method_calls())->toBe(array());
});

it('never writes to the shared table-name dictionary from a reference list', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT table_name', array(
		array('table_name' => 'host'),
	));

	slowlog_classify_other_tables_against_list(1, array());

	$dictionary_writes = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_table_names') !== false;
	}));

	expect($dictionary_writes)->toBe(array());
});
