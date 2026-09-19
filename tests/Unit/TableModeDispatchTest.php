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
 * import_post_process() supports 4 table-detection modes: 'list' (explicit table_names,
 * legacy behavior), 'cacti' (use this Cacti DB), 'reference' (detect everything, compare
 * against a saved reference list), and 'all' (detect everything, no comparison - the default).
 * When $table_mode isn't passed explicitly, it's inferred from $table_names/$usecacti to
 * preserve exact legacy behavior for existing callers.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_table_inserts')) {
	function slowlog_test_table_inserts(): array {
		return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_tables') !== false;
		}));
	}

	function slowlog_test_dictionary_call_count(): int {
		return count(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_table_names') !== false;
		}));
	}

	function slowlog_test_other_tables_call_count(): int {
		return count(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_details_methods') !== false;
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 1);
	slowlog_test_mock_db('db_fetch_cell_prepared', "WHERE method = 'OTHER TABLES'", 22);
	slowlog_test_mock_db('db_fetch_cell_prepared', 'import_tables', 'host graph_local');

	slowlog_test_mock_db('db_fetch_assoc', 'SHOW DATABASES', array(array('Database' => 'cacti')));
	slowlog_test_mock_db('db_fetch_assoc', 'SHOW TABLES FROM', array(
		array('Tables_in_cacti' => 'host'),
		array('Tables_in_cacti' => 'graph_local'),
	));

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT table_name', array(
		array('table_name' => 'host'),
		array('table_name' => 'mystery_tbl'),
	));

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT DISTINCT dt.logentry', array(
		array('logentry' => 1),
	));

	// slowlog_classify_other_tables_against_list()'s per-log comparison (reference mode).
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'AND table_name IN', array(
		array('logentry' => 1),
	));
});

it('defaults to detecting every table with no reference comparison', function () {
	import_post_process(1, '', false);

	expect(slowlog_test_table_inserts())->toBe(array());
	expect(slowlog_test_dictionary_call_count())->toBe(2);
	expect(slowlog_test_other_tables_call_count())->toBe(0);
});

it('uses the explicit table list when table_names is given (legacy behavior)', function () {
	import_post_process(1, 'accounts users', false);

	expect(array_column(slowlog_test_table_inserts(), 'params'))->toBe(array(
		array(1, 'accounts'),
		array(1, 'users'),
	));
	expect(slowlog_test_other_tables_call_count())->toBe(0);
});

it('uses the live Cacti DB and classifies OTHER TABLES when usecacti is true', function () {
	import_post_process(1, '', true);

	// 'cacti' mode must run the tokenizer (like 'all'/'reference'), not the old
	// known-tables-only LIKE scan, otherwise a table NOT in get_cacti_tables() could never
	// be discovered and OTHER TABLES could never actually be classified.
	expect(slowlog_test_table_inserts())->toBe(array());
	expect(slowlog_test_other_tables_call_count())->toBe(1);
});

it("detects everything and compares against the saved reference list in 'reference' mode", function () {
	import_post_process(1, '', false, 'reference');

	expect(slowlog_test_table_inserts())->toBe(array());
	expect(slowlog_test_other_tables_call_count())->toBe(1);
});

it('lets an explicit table_mode override legacy inference', function () {
	import_post_process(1, 'accounts', false, 'all');

	expect(slowlog_test_table_inserts())->toBe(array());
	expect(slowlog_test_other_tables_call_count())->toBe(0);
});

it('defaults the UI dropdown to "all" (properly detect every table)', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->toContain("'table_mode' => array(");
	expect(preg_match('/\'table_mode\'\s*=>\s*array\(.*?\'value\'\s*=>\s*\'all\'/s', $source))->toBe(1);
});

it('validates --table-mode against the 3 allowed values on the CLI', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../import_log.php'));

	expect($source)->toContain("in_array(\$value, array('cacti', 'reference', 'all'), true)");
});

it('wires a --table-names option into the import_log.php CLI and forwards it', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../import_log.php'));

	expect($source)->toContain("'table-names:'");
	expect($source)->toContain('import_logfile($logfile, \'Imported using import_log.php\', -1, $table_names, $usecacti, false, $table_mode);');
});

it('rejects --table-mode=reference with --logfile when --table-names is missing', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../import_log.php'));

	expect($source)->toContain("\$table_mode === 'reference' && trim(\$table_names) === ''");
});

it('forwards the reference/list table names to the background worker', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog_functions.php'));

	expect($source)->toContain("\$cmd .= trim(\$table_names) !== '' ? ' --table-names=' . cacti_escapeshellarg(trim(\$table_names)) : '';");
});
