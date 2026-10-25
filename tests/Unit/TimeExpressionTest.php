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
 * The time of a slow log entry goes into a TIMESTAMP column. An epoch (a "SET timestamp=" line, or a
 * MySQL 8 ISO time with its zone) is an absolute instant and is handed to FROM_UNIXTIME(), so the
 * stored value does not depend on PHP's time zone. A wall-clock "# Time:" from an older server has
 * no zone and is stored as written. The result must be the same under any PHP time zone.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

afterEach(function () {
	date_default_timezone_set('UTC');
});

it('turns a header time into SQL', function ($line, $expected) {
	expect(slowlog_time_expression($line))->toBe($expected);
})->with(array(
	'MariaDB wall clock'              => array("# Time: 240115  1:02:03\n", "'2024-01-15 1:02:03'"),
	'MariaDB two digit hour'          => array("# Time: 240115 11:02:03\n", "'2024-01-15 11:02:03'"),
	'MySQL 8 UTC with fraction'       => array("# Time: 2024-01-15T01:02:03.123456Z\n", 'FROM_UNIXTIME(1705280523)'),
	'MySQL 8 UTC'                     => array("# Time: 2024-01-15T01:02:03Z\n", 'FROM_UNIXTIME(1705280523)'),
	'MySQL 8 with a positive offset'  => array("# Time: 2024-01-15T06:32:03+05:30\n", 'FROM_UNIXTIME(1705280523)'),
	'MySQL 8 with a negative offset'  => array("# Time: 2024-01-14T20:02:03-05:00\n", 'FROM_UNIXTIME(1705280523)'),
	'ISO time that cannot be read'    => array("# Time: 2024-99-99T99:99:99Z\n", "'2020-24--9'"),
));

it('gives the same SQL under any PHP time zone', function ($zone) {
	date_default_timezone_set($zone);

	expect(slowlog_time_expression("# Time: 2024-01-15T01:02:03.5Z\n"))->toBe('FROM_UNIXTIME(1705280523)');
	expect(slowlog_epoch_expression(1705280523))->toBe('FROM_UNIXTIME(1705280523)');
})->with(array('UTC', 'America/New_York', 'Asia/Kolkata', 'Pacific/Auckland'));

it('keeps an epoch inside the range of a TIMESTAMP column', function ($epoch, $expected) {
	expect(slowlog_epoch_expression($epoch))->toBe($expected);
})->with(array(
	'before 1970'   => array(-5, 'FROM_UNIXTIME(0)'),
	'after 2038'    => array(4102444800, 'FROM_UNIXTIME(2147483647)'),
	'ordinary'      => array(1705280523, 'FROM_UNIXTIME(1705280523)'),
));

it('reads the epoch of a SET timestamp line, whatever the PHP time zone', function ($zone) {
	date_default_timezone_set($zone);

	$sql = slowlog_test_import(array(
		'# Time: 2024-01-15T01:02:03.123456Z',
		'# User@Host: root[root] @ localhost [127.0.0.1]  Id:     8',
		'# Query_time: 0.000123  Lock_time: 0.000000 Rows_sent: 1  Rows_examined: 1',
		'SET timestamp=1705280523;',
		'SELECT 1;',
	));

	expect($sql)->toContain("VALUES (1, FROM_UNIXTIME(1705280523), 'root', 'localhost', '127.0.0.1'");
})->with(array('UTC', 'America/New_York', 'Asia/Kolkata'));
