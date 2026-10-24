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
 * slowlog_truncate_query() applies the "Max Query Length" import option. It has to cut on a
 * character boundary and treat anything that is not a positive length as "no limit".
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('cuts a plain query to the requested length', function () {
	expect(slowlog_truncate_query('SELECT 1 FROM t', 8))->toBe('SELECT 1');
});

it('does not split a multibyte character', function () {
	// each of these characters is 3 bytes in UTF-8; 4 bytes would end inside the second one
	$cut = slowlog_truncate_query("\u{20AC}\u{20AC}\u{20AC}", 4);

	expect($cut)->toBe("\u{20AC}");
	expect(mb_check_encoding($cut, 'UTF-8'))->toBeTrue();
});

it('leaves the query alone for -1, 0 and negative lengths', function () {
	expect(slowlog_truncate_query('SELECT 1', -1))->toBe('SELECT 1');
	expect(slowlog_truncate_query('SELECT 1', 0))->toBe('SELECT 1');
	expect(slowlog_truncate_query('SELECT 1', -5))->toBe('SELECT 1');
});

it('keeps imported rows when the length option is zero', function () {
	$sql = slowlog_test_import(array(
		'# Time: 240116  9:00:00',
		'# User@Host: root[root] @ webhost [10.0.0.9]',
		'# Query_time: 0.5  Lock_time: 0.01  Rows_sent: 1  Rows_examined: 1',
		'SET timestamp=1705395600;',
		'SELECT 1;',
	), 0);

	expect($sql)->toContain("'SELECT 1; ')");
});
