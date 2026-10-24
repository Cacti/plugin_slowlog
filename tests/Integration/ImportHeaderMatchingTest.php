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
 * Header lines of a slow log entry start in the first column. Text inside a query that
 * happens to contain a header keyword (a column named timestamp, a comment) must stay part
 * of the query and must not be parsed as a header.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('keeps a query that contains SET timestamp= as its own entry', function () {
	$sql = slowlog_test_import(array(
		'# Time: 240115  1:02:03',
		'# User@Host: root[root] @ dbhost [10.0.0.5]',
		'# Query_time: 1.0  Lock_time: 0.0  Rows_sent: 1  Rows_examined: 1',
		'SET timestamp=1705280523;',
		'SELECT 1;',
		'# Time: 240115  1:03:10',
		'# User@Host: root[root] @ dbhost [10.0.0.5]',
		'# Query_time: 2.0  Lock_time: 0.0  Rows_sent: 1  Rows_examined: 1',
		'SET timestamp=1705280590;',
		'UPDATE events SET timestamp=NOW() WHERE id = 3',
		'AND note = \'see # Time: here\' -- # User@Host: x',
		'# Time: 240115  1:04:00',
	));

	expect($sql)->toContain("'" . date('Y-m-d H:i:s', 1705280590) . "'");
	expect($sql)->toContain('UPDATE events SET timestamp=NOW() WHERE id = 3');
	expect($sql)->toContain('see # Time: here');
	expect(substr_count($sql, "'root', 'dbhost', '10.0.0.5'"))->toBe(2);
});

it('imports an entry whose header has no Time line, as the server writes for the same second', function () {
	$sql = slowlog_test_import(array(
		'# Time: 240115  1:02:03',
		'# User@Host: cacti[cacti] @ dbhost [10.0.0.7]',
		'# Query_time: 0.7  Lock_time: 0.0  Rows_sent: 2  Rows_examined: 9',
		'SET timestamp=1705280523;',
		'SELECT 1;',
		'# User@Host: app[app] @ dbhost [10.0.0.6]',
		'# Query_time: 3.1  Lock_time: 0.2  Rows_sent: 4  Rows_examined: 8',
		'SET timestamp=1705280523;',
		'SELECT 2;',
	), 100);

	expect($sql)->toContain("'cacti', 'dbhost', '10.0.0.7'");
	expect($sql)->toContain("'app', 'dbhost', '10.0.0.6'");
	expect($sql)->toContain("'SELECT 1; ')");
	expect($sql)->toContain("'SELECT 2; ')");
});
