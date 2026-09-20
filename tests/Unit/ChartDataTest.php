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

it('shapes raw method totals into categories/values ordered by the requested measure', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_methods', array(
		array('type' => 'SELECTS', 'count' => 10, 'query_time' => 30),
		array('type' => 'UPDATES', 'count' => 5, 'query_time' => 5),
	));

	$data = slowlog_get_chart_object('methods', 'query_time');

	expect($data['categories'])->toBe(array('SELECTS', 'UPDATES'));
	expect($data['values'])->toBe(array(30, 5));
	expect($data['title'])->toBe('My Log [ Query Seconds ]');
	expect($data['yaxislabel'])->toBe('Seconds');
});

it('shapes raw table totals (including the others bucket) into categories/values', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_tables', array(
		array('type' => 'users', 'count' => 3, 'bytes_sent' => 1500),
		array('type' => 'others', 'count' => 1, 'bytes_sent' => 50),
	));

	$data = slowlog_get_chart_object('tables', 'bytes_sent');

	expect($data['categories'])->toBe(array('users', 'others'));
	expect($data['values'])->toBe(array(1500, 50));
});

it('returns an empty array when there are no rows to chart', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_methods', array());

	expect(slowlog_get_chart_object('methods', 'query_time'))->toBe(array());
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
