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

it('masks escaped and doubled quotes without inventing table references', function () {
	$sql = "SELECT 'it\\'s FROM hidden', 'a'' JOIN hidden', \"a\\\" FROM hidden\" FROM visible";
	expect(slowlog_extract_tables_from_query($sql))->toBe(['visible' => 'visible']);
	// Keep the standalone quoted-string helper consistent with the combined scanner.
	expect(slowlog_mask_quoted_strings($sql))->not->toContain('hidden');
});

it('stops safely at truncated or empty table references', function () {
	expect(slowlog_match_balanced_parens('(SELECT 1'))->toBeFalse();
	expect(slowlog_first_identifier('!'))->toBe('');
	expect(slowlog_scan_clause_span('table)', 0))->toBe(5);
	$tables = [];
	slowlog_extract_single_table_ref('', $tables);
	slowlog_extract_join_chain('', $tables);
	slowlog_extract_join_chain('(SELECT 1', $tables);
	slowlog_extract_table_ref_list('', $tables);
	slowlog_extract_table_ref_list(' , ', $tables);
	expect($tables)->toBe([]);
	expect(slowlog_extract_tables_from_query(''))->toBe([]);
});

it('extracts derived join chains and ignores USING column lists', function () {
	$tables = [];
	slowlog_extract_join_chain('(SELECT * FROM inner_table) AS derived JOIN next_table ON 1=1 JOIN last_table ON 1=1', $tables);
	expect(array_keys($tables))->toBe(['inner_table', 'next_table', 'last_table']);
	$tables = [];
	slowlog_extract_using_clause_tables('DELETE FROM a USING (id)', $tables);
	expect($tables)->toBe([]);
});
