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
 * slowlog_percentile()/slowlog_summarize_values() are the pure-math building blocks behind
 * the plugin_slowlog_stats cache table - no database involved, so these are asserted directly
 * against known linear-interpolation percentile results (the same method numpy's default
 * percentile() and Excel's PERCENTILE.INC use).
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
});

it('computes the median of an even-length array as the average of the two middle values', function () {
	expect(slowlog_percentile(array(1, 2, 3, 4), 50))->toBe(2.5);
});

it('computes the median of an odd-length array as the middle value', function () {
	expect(slowlog_percentile(array(1, 2, 3, 4, 5), 50))->toBe(3.0);
});

it('interpolates p25/p75/p95 between the two closest ranks', function () {
	$values = array(1, 2, 3, 4);

	expect(slowlog_percentile($values, 25))->toBe(1.75);
	expect(slowlog_percentile($values, 75))->toBe(3.25);
	expect(slowlog_percentile($values, 95))->toEqualWithDelta(3.85, 0.0001);
});

it('returns the single value for a one-element array regardless of percentile', function () {
	expect(slowlog_percentile(array(42), 25))->toBe(42.0);
	expect(slowlog_percentile(array(42), 95))->toBe(42.0);
});

it('returns 0 for an empty array instead of a division error', function () {
	expect(slowlog_percentile(array(), 50))->toBe(0.0);
});

it('summarizes an unsorted value list into count/total/min/percentiles/max', function () {
	expect(slowlog_summarize_values(array(4, 1, 3, 2)))->toBe(array(
		'sample_count' => 4,
		'total_value'  => 10,
		'min_value'    => 1,
		'p25_value'    => 1.75,
		'median_value' => 2.5,
		'p75_value'    => 3.25,
		'p95_value'    => 3.8499999999999996,
		'max_value'    => 4,
	));
});

it('summarizes an empty value list as all zeros without warnings', function () {
	expect(slowlog_summarize_values(array()))->toBe(array(
		'sample_count' => 0,
		'total_value'  => 0.0,
		'min_value'    => 0.0,
		'p25_value'    => 0.0,
		'median_value' => 0.0,
		'p75_value'    => 0.0,
		'p95_value'    => 0.0,
		'max_value'    => 0.0,
	));
});
