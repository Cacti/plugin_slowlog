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
 * slowlog_chart_measures()/slowlog_get_chart_object()/slowlog_get_stats_chart_object() back
 * the By Method/By Table chart pages. They live in slowlog_functions.php (not slowlog.php)
 * specifically so they're safe to require_once in isolation for testing - slowlog.php itself
 * has top-level dispatch code (chdir(), include('./include/auth.php'), a switch on
 * get_request_var('action')) that only runs inside a real Cacti request.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'SELECT description', 'My Log');
});

it('defines a unit/suffix pair for every chartable metric', function () {
	$measures = slowlog_chart_measures();

	foreach (array('count', 'rows_sent', 'rows_examined', 'lock_time', 'query_time', 'rows_affected', 'bytes_sent') as $metric) {
		expect($measures)->toHaveKey($metric);
		expect($measures[$metric])->toHaveKeys(array('unit', 'suffix'));
	}
});

it('shapes cached method totals into categories/values ordered by the requested measure', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_stats', array(
		array('scope_key' => 'SELECTS', 'value' => 30),
		array('scope_key' => 'UPDATES', 'value' => 5),
	));

	$data = slowlog_get_chart_object('methods', 'query_time');

	expect($data['categories'])->toBe(array('SELECTS', 'UPDATES'));
	expect($data['values'])->toBe(array(30, 5));
	expect($data['title'])->toBe('My Log [ Query Seconds ]');
	expect($data['yaxislabel'])->toBe('Seconds');
});

it('shapes cached table totals (including the others bucket) into categories/values', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_stats', array(
		array('scope_key' => 'users', 'value' => 1500),
		array('scope_key' => 'others', 'value' => 50),
	));

	$data = slowlog_get_chart_object('tables', 'bytes_sent');

	expect($data['categories'])->toBe(array('users', 'others'));
	expect($data['values'])->toBe(array(1500, 50));
});

it('reads sample_count off the cached stats (not total_value) for the count measure', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'FROM plugin_slowlog_stats') !== false && strpos($sql, 'sample_count AS value') !== false;
	}, array(
		array('scope_key' => 'SELECTS', 'value' => 15),
	));

	$data = slowlog_get_chart_object('methods', 'count');

	expect($data['categories'])->toBe(array('SELECTS'));
	expect($data['values'])->toBe(array(15));
	expect($data['title'])->toBe('My Log [ Total Queries ]');
});

it('returns the full shape with empty categories/values when there are no rows to chart', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_stats', array());

	$data = slowlog_get_chart_object('methods', 'query_time');

	expect($data['categories'])->toBe(array());
	expect($data['values'])->toBe(array());
	expect($data['title'])->toBe('My Log [ Query Seconds ]');
	expect($data['yaxislabel'])->toBe('Seconds');
});

it('does not cap table results to 10 when an explicit scope filter is applied', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'FROM plugin_slowlog_stats') !== false && strpos($sql, 'LIMIT 10') === false;
	}, array(
		array('scope_key' => 'users', 'value' => 5),
	));

	$data = slowlog_get_chart_object('tables', 'bytes_sent', array('users'));

	expect($data['categories'])->toBe(array('users'));
});

it('falls back to live-aggregating plugin_slowlog_details for logs imported before the stats cache existed', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'FROM plugin_slowlog_stats', false);
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_stats', array());
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_methods', array(
		array('scope_key' => 'SELECTS', 'value' => 30),
		array('scope_key' => 'UPDATES', 'value' => 5),
	));

	$data = slowlog_get_chart_object('methods', 'query_time');

	expect($data['categories'])->toBe(array('SELECTS', 'UPDATES'));
	expect($data['values'])->toBe(array(30, 5));
});

it('does not fall back to live aggregation when the log has a stats cache but this scope/metric legitimately has no rows', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'FROM plugin_slowlog_stats', 1);
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_stats', array());

	$data = slowlog_get_chart_object('methods', 'query_time');

	expect($data['categories'])->toBe(array());
	expect($data['values'])->toBe(array());

	$live_calls = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_fetch_assoc_prepared' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false;
	}));

	expect($live_calls)->toBe(array());
});

it('shapes cached percentile rows into box-whisker data plus a separate p95 series', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_stats', array(
		array('scope_key' => 'SELECTS', 'sample_count' => 2, 'total_value' => 30,
			'min_value' => 10, 'p25_value' => 12.5, 'median_value' => 15, 'p75_value' => 17.5, 'p95_value' => 19.5, 'max_value' => 20),
	));

	$data = slowlog_get_stats_chart_object('methods', 'query_time');

	expect($data['categories'])->toBe(array('SELECTS'));
	expect($data['box_data'])->toBe(array(
		array('x' => 'SELECTS', 'y' => array(10.0, 12.5, 15.0, 17.5, 20.0)),
	));
	expect($data['p95_data'])->toBe(array(
		array('x' => 'SELECTS', 'y' => 19.5),
	));
	expect($data['title'])->toBe('My Log [ Query Seconds Distribution ]');
	expect($data['yaxislabel'])->toBe('Seconds');
});

it('scopes the stats lookup to table (not method) for the tables chart type', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'FROM plugin_slowlog_stats') !== false && in_array('table', $params, true);
	}, array(
		array('scope_key' => 'users', 'sample_count' => 1, 'total_value' => 5,
			'min_value' => 5, 'p25_value' => 5, 'median_value' => 5, 'p75_value' => 5, 'p95_value' => 5, 'max_value' => 5),
	));

	$data = slowlog_get_stats_chart_object('tables', 'query_time');

	expect($data['categories'])->toBe(array('users'));
});

it('does not cap box-whisker table results to 10 when an explicit scope filter is applied', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'FROM plugin_slowlog_stats') !== false && strpos($sql, 'LIMIT 10') === false;
	}, array(
		array('scope_key' => 'users', 'sample_count' => 1, 'total_value' => 5,
			'min_value' => 5, 'p25_value' => 5, 'median_value' => 5, 'p75_value' => 5, 'p95_value' => 5, 'max_value' => 5),
	));

	$data = slowlog_get_stats_chart_object('tables', 'query_time', array('users'));

	expect($data['categories'])->toBe(array('users'));
});
