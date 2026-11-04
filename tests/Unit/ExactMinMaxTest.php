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
 * Metric values are sampled once a scope has more than SLOWLOG_STATS_SAMPLE_CAP entries, so the
 * sample's smallest and largest values are not the real extremes. The running totals now keep
 * the exact minimum and maximum, and the summary reports them.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('keeps the exact minimum and maximum while sampling', function () {
	mt_srand(7);

	$values = array();
	$totals = array();

	// the extremes arrive first and last; the cap forces the reservoir to replace entries
	slowlog_accumulate_stat_value($values, $totals, 'SELECTS', 'query_time', 500.0);

	for ($i = 0; $i < SLOWLOG_STATS_SAMPLE_CAP + 5000; $i++) {
		slowlog_accumulate_stat_value($values, $totals, 'SELECTS', 'query_time', 100.0 + ($i % 50));
	}

	slowlog_accumulate_stat_value($values, $totals, 'SELECTS', 'query_time', 1.0);
	slowlog_accumulate_stat_value($values, $totals, 'SELECTS', 'query_time', 99999.0);

	$totals = $totals['SELECTS']['query_time'];

	expect($totals['count'])->toBe(SLOWLOG_STATS_SAMPLE_CAP + 5003);
	expect($totals['min'])->toBe(1.0);
	expect($totals['max'])->toBe(99999.0);
	expect(count($values['SELECTS']['query_time']))->toBe(SLOWLOG_STATS_SAMPLE_CAP);

	$summary = slowlog_summarize_values($values['SELECTS']['query_time'], $totals['count'], $totals['sum'], $totals['min'], $totals['max']);

	expect($summary['min_value'])->toBe(1.0);
	expect($summary['max_value'])->toBe(99999.0);
});

it('falls back to the sample extremes when no exact values are given', function () {
	$summary = slowlog_summarize_values(array(4, 1, 3, 2));

	expect($summary['min_value'])->toBe(1);
	expect($summary['max_value'])->toBe(4);
});

it('writes the exact extremes into the cached stats rows', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_methods', array(
		array('id' => 1, 'scope_key' => 'SELECTS', 'query_time' => 5, 'rows_sent' => 0, 'rows_examined' => 0, 'rows_affected' => 0, 'bytes_sent' => 0),
		array('id' => 2, 'scope_key' => 'SELECTS', 'query_time' => 9, 'rows_sent' => 0, 'rows_examined' => 0, 'rows_affected' => 0, 'bytes_sent' => 0),
	));

	slowlog_compute_stats(1);

	$insert = null;

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_stats') !== false) {
			$insert = $call;
		}
	}

	expect($insert)->not->toBeNull();
	// logid, scope, scope_key, metric, sample_count, total, min, p25, median, p75, p95, max
	expect($insert['params'][6])->toBe(5.0);
	expect($insert['params'][11])->toBe(9.0);
});
