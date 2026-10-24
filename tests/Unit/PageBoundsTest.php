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
 * The Details view takes rows per page and the page number from the request, where they are
 * only validated as integers. slowlog_page_bounds() keeps them within a range that is safe
 * to put in a LIMIT clause.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('passes through ordinary values', function () {
	expect(slowlog_page_bounds('50', '3'))->toBe(array(50, 3));
	expect(slowlog_page_bounds(1000, 1))->toBe(array(1000, 1));
});

it('caps a very large page size', function () {
	expect(slowlog_page_bounds('100000000', 1))->toBe(array(SLOWLOG_MAX_PAGE_ROWS, 1));
});

it('falls back to 30 rows for zero, negative and non-numeric sizes', function () {
	expect(slowlog_page_bounds(0, 1)[0])->toBe(30);
	expect(slowlog_page_bounds(-5, 1)[0])->toBe(30);
	expect(slowlog_page_bounds('', 1)[0])->toBe(30);
});

it('never returns a page below 1', function () {
	expect(slowlog_page_bounds(30, 0)[1])->toBe(1);
	expect(slowlog_page_bounds(30, -4)[1])->toBe(1);
	expect(slowlog_page_bounds(30, '')[1])->toBe(1);
});

it('builds the Details LIMIT from the bounded values', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php')) . slowlog_test_helper_source();

	expect($source)->toContain('list($rows, $page) = slowlog_page_bounds($rows, get_request_var(\'page\'));');
	expect($source)->toContain("\$sql_limit   = ' LIMIT ' . (\$rows * (\$page - 1)) . ',' . \$rows;");
});
