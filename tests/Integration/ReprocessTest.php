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
*/

/*
 * slowlog_reprocess()/slowlog_reprocess_all() back the import_log.php --reprocess=N|all
 * flag: they let method/table/timeout classification be re-run against already-imported
 * raw log data (e.g. after new methods/tables are added), without needing the original
 * logfile. import_post_process()'s method inserts aren't safe to run twice on their own
 * (no ON DUPLICATE KEY UPDATE), so the previously-derived associations must be cleared first.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_calls_matching')) {
	function slowlog_test_calls_matching(array $calls, string $needle): array {
		return array_values(array_filter($calls, function ($call) use ($needle) {
			return strpos($call['sql'], $needle) !== false;
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	// No plugin_slowlog_details rows fixture needed - this just verifies the cleanup/dispatch.
	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 0);
});

it('clears the previously-derived method, table, and timeout state before reprocessing', function () {
	slowlog_reprocess(5);

	$calls = $GLOBALS['__test_db_calls'];

	expect(slowlog_test_calls_matching($calls, 'DELETE FROM plugin_slowlog_details_methods'))->toHaveCount(1);
	expect(slowlog_test_calls_matching($calls, 'DELETE FROM plugin_slowlog_details_tables'))->toHaveCount(1);
	expect(slowlog_test_calls_matching($calls, 'DELETE FROM plugin_slowlog_tables'))->toHaveCount(1);
	expect(slowlog_test_calls_matching($calls, 'DELETE FROM plugin_slowlog_stats'))->toHaveCount(1);
	expect(slowlog_test_calls_matching($calls, 'SET timeout = 0'))->toHaveCount(1);

	foreach (slowlog_test_calls_matching($calls, 'DELETE FROM plugin_slowlog_details_methods') as $call) {
		expect($call['params'])->toBe(array(5));
	}
});

it('marks the log as reprocessing before handing off to import_post_process()', function () {
	slowlog_reprocess(5);

	$calls  = slowlog_test_calls_matching($GLOBALS['__test_db_calls'], 'SET import_status = 1');
	expect($calls)->toHaveCount(1);
	expect($calls[0]['params'])->toBe(array('Reprocessing', 5));
});

it('still marks the log fully processed afterward, same as a normal import', function () {
	slowlog_reprocess(5);

	$calls = slowlog_test_calls_matching($GLOBALS['__test_db_calls'], 'import_status = 2');
	expect($calls)->toHaveCount(1);
});

it('reprocesses every logid in plugin_slowlog when called with no argument', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logid FROM plugin_slowlog', array(
		array('logid' => 1),
		array('logid' => 2),
		array('logid' => 3),
	));

	slowlog_reprocess_all();

	$calls   = slowlog_test_calls_matching($GLOBALS['__test_db_calls'], 'DELETE FROM plugin_slowlog_details_methods');
	$touched = array_map(function ($call) { return $call['params'][0]; }, $calls);

	expect($touched)->toBe(array(1, 2, 3));
});

it('wires a --reprocess option into the import_log.php CLI', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../import_log.php'));

	expect($source)->toContain("'reprocess:'");
	expect($source)->toContain('slowlog_reprocess_all(');
	expect($source)->toContain('slowlog_reprocess(');
});
