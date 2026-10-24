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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/


uses(TestCase::class);
beforeEach(function () { TestCase::loadPluginSource('includes/functions.php'); });

it('shrinks existing reservoirs as new groups arrive while keeping exact totals', function () {
	$values = $totals = [];
	mt_srand(12345);
	for ($i = 1; $i <= 25000; $i++) { slowlog_accumulate_stat_value($values, $totals, 'first', 'query_time', (float) $i); }
	expect($values['first']['query_time'])->toHaveCount(SLOWLOG_STATS_SAMPLE_CAP);
	for ($i = 1; $i < 64; $i++) { slowlog_accumulate_stat_value($values, $totals, 'group_' . $i, 'query_time', 1.0); }
	expect($values['first']['query_time'])->toHaveCount(slowlog_stats_sample_limit(64));
	$retained = 0;
	foreach ($values as $group) { foreach ($group as $sample) { $retained += count($sample); } }
	expect($retained)->toBeLessThanOrEqual(SLOWLOG_STATS_TOTAL_SAMPLE_CAP);
	expect($totals['first']['query_time'])->toBe(['count' => 25000, 'sum' => 312512500.0, 'min' => 1.0, 'max' => 25000.0]);
});

it('fails an excessive group count rather than exhausting memory', function () {
	$values = array_fill_keys(range(1, SLOWLOG_STATS_GROUP_CAP), []);
	$totals = [];
	expect(fn () => slowlog_accumulate_stat_value($values, $totals, 'extra', 'query_time', 1.0))->toThrow(RuntimeException::class, 'group limit');
});


it('shares the sample budget across every metric as well as every group', function () {
	$values = $totals = [];
	for ($group = 0; $group < 16; $group++) {
		for ($i = 0; $i < 3000; $i++) {
			foreach (SLOWLOG_STATS_METRICS as $metric) { slowlog_accumulate_stat_value($values, $totals, 'g' . $group, $metric, (float) $i); }
		}
	}
	$retained = 0;
	foreach ($values as $group) { foreach ($group as $sample) { $retained += count($sample); } }
	expect($retained)->toBeLessThanOrEqual(SLOWLOG_STATS_TOTAL_SAMPLE_CAP);
	foreach ($totals as $group) { foreach ($group as $total) { expect($total['count'])->toBe(3000); expect($total['sum'])->toBe(4498500.0); } }
});
