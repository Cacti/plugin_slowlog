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
 * import_post_process() classifies each detail row by method (via a series
 * of LIKE/NOT LIKE db_execute_prepared calls) and by table. This exercises
 * that classification through the mock database using the exact method
 * dictionary setup.php seeds into plugin_slowlog_methods, and asserts the
 * generated SQL/params instead of needing a real MySQL/MariaDB instance.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_prepared_calls_matching')) {
	function slowlog_test_prepared_calls_matching(array $calls, string $needle): array {
		return array_values(array_filter($calls, function ($call) use ($needle) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], $needle) !== false;
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 3);

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_methods', array(
		array('method' => 'INSERTS',    'query' => 'INSERT INTO,INSERT IGNORE INTO', 'methodid' => 1),
		array('method' => 'REPLACES',   'query' => 'REPLACE INTO,REPLACE IGNORE INTO', 'methodid' => 2),
		array('method' => 'DELETES',    'query' => 'DELETE ', 'methodid' => 3),
		array('method' => 'SELECTS',    'query' => 'SELECT ', 'methodid' => 4),
		array('method' => 'DISTINCTS',  'query' => 'SELECT DISTINCT', 'methodid' => 5),
		array('method' => 'UNIONS',     'query' => 'UNION', 'methodid' => 6),
		array('method' => 'JOINS',      'query' => 'JOIN ', 'methodid' => 7),
		array('method' => 'OTHERS',     'query' => 'OTHERS', 'methodid' => 8),
		array('method' => 'UPDATES',    'query' => 'UPDATE ', 'methodid' => 9),
		array('method' => 'RENAMES',    'query' => 'RENAME TABLE', 'methodid' => 10),
		array('method' => 'FLUSHES',    'query' => 'FLUSH TABLE', 'methodid' => 11),
		array('method' => 'TRUNCATES',  'query' => 'TRUNCATE ', 'methodid' => 12),
		array('method' => 'LOAD DATA',  'query' => 'LOAD DATA INFILE ', 'methodid' => 13),
		array('method' => 'OUTFILES',   'query' => 'INTO OUTFILE ', 'methodid' => 14),
	));
});

it('inserts a methodid mapping row per LIKE fragment for a simple method', function () {
	import_post_process(1, 'accounts');

	$calls = slowlog_test_prepared_calls_matching($GLOBALS['__test_db_calls'], 'plugin_slowlog_details_methods');

	$select = array_values(array_filter($calls, function ($call) {
		return $call['params'][1] === 4; // SELECTS methodid
	}));

	expect($select)->toHaveCount(1);
	expect($select[0]['params'])->toBe(array(1, 4, 1, '%SELECT %'));
});

it('inserts one mapping row per comma-separated query fragment', function () {
	import_post_process(1, 'accounts');

	$calls = slowlog_test_prepared_calls_matching($GLOBALS['__test_db_calls'], 'plugin_slowlog_details_methods');

	$inserts = array_values(array_filter($calls, function ($call) {
		return $call['params'][1] === 1; // INSERTS methodid
	}));

	expect($inserts)->toHaveCount(2);
	expect(array_column($inserts, 'params'))->toBe(array(
		array(1, 1, 1, '%INSERT INTO%'),
		array(1, 1, 1, '%INSERT IGNORE INTO%'),
	));
});

it('excludes every other method fragment from the OTHERS bucket', function () {
	import_post_process(1, 'accounts');

	$calls = slowlog_test_prepared_calls_matching($GLOBALS['__test_db_calls'], 'plugin_slowlog_details_methods');

	$others = array_values(array_filter($calls, function ($call) {
		return $call['params'][1] === 8; // OTHERS methodid
	}));

	expect($others)->toHaveCount(1);
	expect(substr_count($others[0]['sql'], 'NOT LIKE'))->toBe(16);
	expect($others[0]['params'])->toContain('%SELECT %', '%UPDATE %', '%JOIN %');
});

it('records the table dictionary row and its detail associations', function () {
	import_post_process(1, 'accounts users');

	$dictionary = slowlog_test_prepared_calls_matching($GLOBALS['__test_db_calls'], 'INSERT INTO plugin_slowlog_tables');
	expect(array_column($dictionary, 'params'))->toBe(array(
		array(1, 'accounts'),
		array(1, 'users'),
	));

	$details = slowlog_test_prepared_calls_matching($GLOBALS['__test_db_calls'], 'INSERT INTO plugin_slowlog_details_tables');
	expect($details)->toHaveCount(2);
	expect($details[0]['params'])->toBe(array(
		1, 'accounts', 1,
		'%FROM accounts %', '%FROM (accounts,%', '%FROM (%,accounts)%',
		'%JOIN accounts %', '%`accounts`%', '%UPDATE accounts %', '%INTO accounts %', '%FROM accounts',
	));
});

it('marks the log fully processed once table classification completes', function () {
	import_post_process(1, 'accounts');

	$status = slowlog_test_prepared_calls_matching($GLOBALS['__test_db_calls'], 'UPDATE plugin_slowlog');
	$final  = end($status);

	expect($final['sql'])->toContain('import_status = 2');
	expect($final['params'])->toBe(array('All Tables Processed', 1));
});
