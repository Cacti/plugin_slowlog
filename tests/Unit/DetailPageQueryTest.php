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

it('binds detail filters and shares joins between the page and count', function () {
	slowlog_fetch_detail_page(['filter' => "x' OR 1=1", 'logid' => 7, 'user' => 'app', 'host' => 'host', 'date1' => '2024-01-01', 'date2' => '2024-01-02', 'table' => 'widgets', 'method_name' => 'SELECTS', 'rows' => 50, 'page' => 3, 'sort_column' => 'query_time', 'sort_direction' => 'DESC']);
	$calls = $GLOBALS['__test_db_calls'];
	expect($calls)->toHaveCount(2);
	expect($calls[0]['params'])->toBe(["%x' OR 1=1%", "%x' OR 1=1%", "%x' OR 1=1%", "%x' OR 1=1%", 7, 'app', 'host', '2024-01-01', '2024-01-02', 'widgets', 'SELECTS']);
	expect($calls[1]['params'])->toBe($calls[0]['params']);
	expect($calls[0]['sql'])->toContain('ORDER BY `query_time` DESC', 'LIMIT 100,50')->not->toContain("x' OR");
	expect($calls[1]['sql'])->not->toContain('LIMIT');
});

it('supports table and method aggregation without joining those associations', function () {
	$page = slowlog_fetch_detail_page(['table' => '-3', 'mmethod' => '-2', 'rows' => -1, 'page' => -2, 'sort_column' => 'injected', 'sort_direction' => 'DESC; DROP TABLE t']);
	expect($page['rows'])->toBe(30);
	expect($page['page'])->toBe(1);
	$sql = $GLOBALS['__test_db_calls'][0]['sql'];
	expect($sql)->toContain('"N/A" AS table_name', '"N/A" AS method', 'ORDER BY `table_name` ASC')->not->toContain('LEFT JOIN');
});

it('filters unmatched tables and the method dropdown', function () {
	slowlog_fetch_detail_page(['table' => '-2', 'mmethod' => 'UPDATES']);
	$call = $GLOBALS['__test_db_calls'][0];
	expect($call['sql'])->toContain('sldt.table_name IS NULL', 'sldm.method = ?');
	expect($call['params'])->toBe(['', '', 'UPDATES']);
});
