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
 * Table names come from tokenizing imported slow log SQL, so a quoted identifier can
 * contain characters that are significant in HTML. They must be escaped when written into
 * the table filter's option value, not only when written as the option label.
 * slowlog.php cannot be loaded into the unit process, so this checks the source shape
 * like the other output escaping tests, and checks the tokenizer really can return such a
 * name.
 */

uses(TestCase::class);

it('escapes the table name used as the option value in the details filter', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->toContain("'<option value=\"' . html_escape(\$t['table_name']) . '\"'");
	expect($source)->not->toContain("'<option value=\"' . \$t['table_name'] . '\"'");
});

it('can extract a table name that contains markup characters from a quoted identifier', function () {
	TestCase::loadPluginSource('includes/functions.php');

	$tables = slowlog_extract_tables_from_query('SELECT 1 FROM `a""><b>` WHERE 1');

	expect(array_keys($tables))->toBe(array('a""><b>'));
	expect(html_escape('a""><b>'))->not->toContain('<');
});
