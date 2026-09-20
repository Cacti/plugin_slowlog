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
		$tuples = array();

		foreach ($GLOBALS['__test_db_calls'] as $call) {
			if ($call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false) {
				foreach (array_chunk($call['params'], 3) as $t) {
					$tuples[] = array((int) $t[0], (int) $t[1], (int) $t[2]);
				}
			}
		}

		return $tuples;
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
		array('method' => 'INFILES',    'query' => 'INFILE ', 'methodid' => 15),
		array('method' => 'GROUP BY',   'query' => 'GROUP BY ', 'methodid' => 16),
		array('method' => 'COUNTS',     'query' => 'COUNT(', 'methodid' => 17),
		array('method' => 'SHOWS',      'query' => 'SHOW ', 'methodid' => 18),
		array('method' => 'UNION ALLS', 'query' => 'UNION ALL', 'methodid' => 19),
		array('method' => 'MAX_EXECUTION_TIME', 'query' => 'MAX_EXECUTION_TIME(', 'methodid' => 20),
		array('method' => 'MAX_STATEMENT_TIME', 'query' => 'MAX_STATEMENT_TIME', 'methodid' => 21),
		array('method' => 'FORCE INDEX', 'query' => 'FORCE INDEX', 'methodid' => 23),
		array('method' => 'ALTERS', 'query' => 'ALTER TABLE', 'methodid' => 24),
		array('method' => 'DROPS', 'query' => 'DROP TABLE,DROP TEMPORARY TABLE', 'methodid' => 25),
		array('method' => 'ANALYZES', 'query' => 'ANALYZE TABLE,ANALYZE NO_WRITE_TO_BINLOG TABLE,ANALYZE LOCAL TABLE', 'methodid' => 26),
		array('method' => 'OPTIMIZES', 'query' => 'OPTIMIZE TABLE,OPTIMIZE NO_WRITE_TO_BINLOG TABLE,OPTIMIZE LOCAL TABLE', 'methodid' => 27),
		array('method' => 'CREATES', 'query' => 'CREATE TABLE', 'methodid' => 28),
		array('method' => 'CREATE TEMPS', 'query' => 'CREATE TEMPORARY TABLE', 'methodid' => 29),
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
		array('logentry' => 1, 'query' => 'commit'),
	));

	import_post_process(1, 'accounts');

	expect(slowlog_test_method_insert_tuples())->toBe(array(array(1, 1, 8)));
});

it('classifies rows matching each of the method rows added by this PR', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => "load data infile 'x.txt' into table t"),
		array('logentry' => 2, 'query' => 'select * from t group by id'),
		array('logentry' => 3, 'query' => 'select count(*) from t'),
		array('logentry' => 4, 'query' => 'show tables'),
		array('logentry' => 5, 'query' => 'select * from t union all select * from u'),
		array('logentry' => 6, 'query' => 'select /*+ MAX_EXECUTION_TIME(5000) */ * from t'),
		array('logentry' => 7, 'query' => 'set statement max_statement_time=30 for select * from t'),
	));

	import_post_process(1, 'accounts');

	$tuples = slowlog_test_method_insert_tuples();

	// Every query here also matches other, pre-existing methods (e.g. SELECTS) - the
	// point is just that the new method dictionary rows are actually reachable.
	expect($tuples)->toContain(array(1, 1, 15)); // INFILES
	expect($tuples)->toContain(array(1, 2, 16)); // GROUP BY
	expect($tuples)->toContain(array(1, 3, 17)); // COUNTS
	expect($tuples)->toContain(array(1, 4, 18)); // SHOWS
	expect($tuples)->toContain(array(1, 5, 19)); // UNION ALLS
	expect($tuples)->toContain(array(1, 6, 20)); // MAX_EXECUTION_TIME
	expect($tuples)->toContain(array(1, 7, 21)); // MAX_STATEMENT_TIME
});

it('classifies a query using a FORCE INDEX hint under the FORCE INDEX method', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => "update grid_jobs_pendreasons force index (clusterid_end_time) set end_time='2023-02-07 22:17:07' where clusterid='86'"),
	));

	import_post_process(1, 'accounts');

	expect(slowlog_test_method_insert_tuples())->toContain(array(1, 1, 23));
});

it('classifies rows matching each of the ALTERS/DROPS/ANALYZES/OPTIMIZES methods', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'alter table users add column age int'),
		array('logentry' => 2, 'query' => 'drop table if exists tmp_users'),
		array('logentry' => 3, 'query' => 'drop temporary table tmp_users'),
		array('logentry' => 4, 'query' => 'analyze table users'),
		array('logentry' => 5, 'query' => 'analyze no_write_to_binlog table users'),
		array('logentry' => 6, 'query' => 'optimize table users'),
		array('logentry' => 7, 'query' => 'optimize no_write_to_binlog table users'),
	));

	import_post_process(1, 'accounts');

	$tuples = slowlog_test_method_insert_tuples();

	expect($tuples)->toContain(array(1, 1, 24)); // ALTERS
	expect($tuples)->toContain(array(1, 2, 25)); // DROPS
	expect($tuples)->toContain(array(1, 3, 25)); // DROPS (DROP TEMPORARY TABLE)
	expect($tuples)->toContain(array(1, 4, 26)); // ANALYZES
	expect($tuples)->toContain(array(1, 5, 26)); // ANALYZES (NO_WRITE_TO_BINLOG)
	expect($tuples)->toContain(array(1, 6, 27)); // OPTIMIZES
	expect($tuples)->toContain(array(1, 7, 27)); // OPTIMIZES (NO_WRITE_TO_BINLOG)
});

it('classifies a permanent CREATE TABLE under CREATES but not CREATE TEMPS', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'create table archive like users'),
	));

	import_post_process(1, 'accounts');

	$tuples = slowlog_test_method_insert_tuples();

	expect($tuples)->toContain(array(1, 1, 28)); // CREATES
	expect($tuples)->not->toContain(array(1, 1, 29)); // CREATE TEMPS
});

it('classifies a CREATE TEMPORARY TABLE under CREATE TEMPS but not CREATES', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'create temporary table users_temp like users'),
	));

	import_post_process(1, 'accounts');

	$tuples = slowlog_test_method_insert_tuples();

	expect($tuples)->toContain(array(1, 1, 29)); // CREATE TEMPS
	expect($tuples)->not->toContain(array(1, 1, 28)); // CREATES
});

it('inserts the method classification with a single batched statement', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', array(
		array('logentry' => 1, 'query' => 'select * from users'),
		array('logentry' => 2, 'query' => 'insert into users values (1)'),
		array('logentry' => 3, 'query' => 'analyze table users'),
	));

	import_post_process(1, 'accounts');

	$calls = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false;
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
