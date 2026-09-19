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
 * import_post_process() classifies each detail row by method in a single PHP-side pass
 * (one fetch of the logid's rows, matched against each method's comma-separated fragments
 * via stripos(), then one batched INSERT) rather than one LIKE/NOT LIKE table scan per
 * method. This exercises that classification through the mock database using the exact
 * method dictionary setup.php seeds into plugin_slowlog_methods, and asserts the generated
 * SQL/params instead of needing a real MySQL/MariaDB instance.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_prepared_calls_matching')) {
	function slowlog_test_prepared_calls_matching(array $calls, string $needle): array {
		return array_values(array_filter($calls, function ($call) use ($needle) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], $needle) !== false;
		}));
	}
}

if (!function_exists('slowlog_test_method_insert_tuples')) {
	function slowlog_test_method_insert_tuples(): array {
		foreach ($GLOBALS['__test_db_calls'] as $call) {
			if ($call['fn'] === 'db_execute' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false) {
				preg_match_all('/\((\d+),\s*(\d+),\s*(\d+)\)/', $call['sql'], $m, PREG_SET_ORDER);

				return array_map(function ($t) {
					return array((int) $t[1], (int) $t[2], (int) $t[3]);
				}, $m);
			}
		}

		return array();
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

it('classifies a row that matches a simple method', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'select * from users'),
	));

	import_post_process(1, 'accounts');

	expect(slowlog_test_method_insert_tuples())->toBe(array(array(1, 1, 4)));
});

it('classifies a row matching either alternative of a comma-separated method', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'insert into users values (1)'),
		array('logentry' => 2, 'query' => 'insert ignore into users values (1)'),
	));

	import_post_process(1, 'accounts');

	expect(slowlog_test_method_insert_tuples())->toBe(array(array(1, 1, 1), array(1, 2, 1)));
});

it('gives a row multiple methodid rows when it matches more than one method', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'select a.id from a join b on a.id=b.id'),
	));

	import_post_process(1, 'accounts');

	expect(slowlog_test_method_insert_tuples())->toBe(array(array(1, 1, 4), array(1, 1, 7)));
});

it('buckets a row matching no other method as OTHERS', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'analyze table users'),
	));

	import_post_process(1, 'accounts');

	expect(slowlog_test_method_insert_tuples())->toBe(array(array(1, 1, 8)));
});

it('inserts the method classification with a single batched statement', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'select * from users'),
		array('logentry' => 2, 'query' => 'insert into users values (1)'),
		array('logentry' => 3, 'query' => 'analyze table users'),
	));

	import_post_process(1, 'accounts');

	$calls = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false;
	}));

	expect($calls)->toHaveCount(1);
	expect($calls[0]['sql'])->toContain('ON DUPLICATE KEY UPDATE methodid=VALUES(methodid)');
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
