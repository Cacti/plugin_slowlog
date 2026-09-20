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
 * slowlog_compute_stats() drives the three chunked collectors
 * (slowlog_collect_stats_by_method()/slowlog_collect_stats_by_matched_table()/
 * slowlog_collect_stats_by_unmatched_table()) and bulk-inserts one plugin_slowlog_stats row
 * per (scope, scope_key, metric). The collectors' own SQL joins aren't re-executed here (no
 * real database) - instead each collector query is matched by a substring unique to it and
 * stubbed to return the already-joined rows a real database would produce, so this exercises
 * the actual grouping/percentile-summarizing logic end to end.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_stats_insert_tuples')) {
	function slowlog_test_stats_insert_tuples(): array {
		$tuples = array();

		foreach ($GLOBALS['__test_db_calls'] as $call) {
			if ($call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_stats') !== false) {
				foreach (array_chunk($call['params'], 12) as $t) {
					$tuples[$t[1] . ':' . $t[2] . ':' . $t[3]] = array(
						'logid'        => $t[0],
						'scope'        => $t[1],
						'scope_key'    => $t[2],
						'metric'       => $t[3],
						'sample_count' => $t[4],
						'total_value'  => $t[5],
						'min_value'    => $t[6],
						'p25_value'    => $t[7],
						'median_value' => $t[8],
						'p75_value'    => $t[9],
						'p95_value'    => $t[10],
						'max_value'    => $t[11],
					);
				}
			}
		}

		return $tuples;
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'plugin_slowlog_details_methods', array(
		array('id' => 1, 'scope_key' => 'SELECTS', 'query_time' => 10, 'rows_sent' => 1, 'rows_examined' => 100, 'rows_affected' => 0, 'bytes_sent' => 500),
		array('id' => 2, 'scope_key' => 'SELECTS', 'query_time' => 20, 'rows_sent' => 2, 'rows_examined' => 200, 'rows_affected' => 0, 'bytes_sent' => 1000),
		array('id' => 3, 'scope_key' => 'UPDATES', 'query_time' => 5, 'rows_sent' => 0, 'rows_examined' => 10, 'rows_affected' => 1, 'bytes_sent' => 50),
	));

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'sldt.tableid', array(
		array('tableid' => 1, 'scope_key' => 'users', 'query_time' => 10, 'rows_sent' => 1, 'rows_examined' => 100, 'rows_affected' => 0, 'bytes_sent' => 500),
		array('tableid' => 2, 'scope_key' => 'orders', 'query_time' => 20, 'rows_sent' => 2, 'rows_examined' => 200, 'rows_affected' => 0, 'bytes_sent' => 1000),
	));

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'IS NULL', array(
		array('logentry' => 3, 'query_time' => 5, 'rows_sent' => 0, 'rows_examined' => 10, 'rows_affected' => 1, 'bytes_sent' => 50),
	));
});

it('summarizes a method that matched two entries with interpolated percentiles', function () {
	slowlog_compute_stats(1);

	$tuples = slowlog_test_stats_insert_tuples();
	$row    = $tuples['method:SELECTS:query_time'];

	expect($row['sample_count'])->toBe(2);
	expect($row['total_value'])->toEqualWithDelta(30.0, 0.0001);
	expect($row['min_value'])->toEqualWithDelta(10.0, 0.0001);
	expect($row['p25_value'])->toEqualWithDelta(12.5, 0.0001);
	expect($row['median_value'])->toEqualWithDelta(15.0, 0.0001);
	expect($row['p75_value'])->toEqualWithDelta(17.5, 0.0001);
	expect($row['p95_value'])->toEqualWithDelta(19.5, 0.0001);
	expect($row['max_value'])->toEqualWithDelta(20.0, 0.0001);
});

it('summarizes a method that matched only one entry as a degenerate (flat) box', function () {
	slowlog_compute_stats(1);

	$row = slowlog_test_stats_insert_tuples()['method:UPDATES:query_time'];

	expect($row['sample_count'])->toBe(1);
	foreach (array('min_value', 'p25_value', 'median_value', 'p75_value', 'p95_value', 'max_value') as $field) {
		expect($row[$field])->toEqualWithDelta(5.0, 0.0001);
	}
});

it('buckets entries with no recognized table under the others scope_key', function () {
	slowlog_compute_stats(1);

	$row = slowlog_test_stats_insert_tuples()['table:others:bytes_sent'];

	expect($row['sample_count'])->toBe(1);
	expect($row['total_value'])->toEqualWithDelta(50.0, 0.0001);
});

it('caches one row per scope/scope_key/metric combination (2 methods + 3 tables x 5 metrics)', function () {
	slowlog_compute_stats(1);

	expect(slowlog_test_stats_insert_tuples())->toHaveCount((2 + 3) * 5);
});

it('inserts the stats cache with a single batched statement', function () {
	slowlog_compute_stats(1);

	$calls = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_stats') !== false;
	}));

	expect($calls)->toHaveCount(1);
	expect($calls[0]['sql'])->toContain('ON DUPLICATE KEY UPDATE');
});

it('does not drop rows when a page boundary lands inside a same-logentry group of method matches', function () {
	// 3 association rows all sharing logentry=1 (e.g. a query matching SELECTS, JOINS, and
	// GROUP BY at once), paginated one row at a time (chunk_size=1) so every possible page
	// boundary falls inside that group - this is exactly the scenario the non-unique
	// logentry cursor used to lose rows on.
	slowlog_test_reset_db_mocks();

	$page_rows = array(
		1 => array('id' => 1, 'scope_key' => 'SELECTS', 'query_time' => 1, 'rows_sent' => 0, 'rows_examined' => 0, 'rows_affected' => 0, 'bytes_sent' => 0),
		2 => array('id' => 2, 'scope_key' => 'JOINS', 'query_time' => 2, 'rows_sent' => 0, 'rows_examined' => 0, 'rows_affected' => 0, 'bytes_sent' => 0),
		3 => array('id' => 3, 'scope_key' => 'GROUP BY', 'query_time' => 3, 'rows_sent' => 0, 'rows_examined' => 0, 'rows_affected' => 0, 'bytes_sent' => 0),
	);

	foreach ($page_rows as $cursor => $row) {
		slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) use ($cursor) {
			return strpos($sql, 'plugin_slowlog_details_methods') !== false && $params[1] === $cursor - 1;
		}, array($row));
	}

	$values = array();
	slowlog_collect_stats_by_method(1, $values, 1);

	expect($values['SELECTS']['query_time'])->toBe(array(1.0));
	expect($values['JOINS']['query_time'])->toBe(array(2.0));
	expect($values['GROUP BY']['query_time'])->toBe(array(3.0));
});
