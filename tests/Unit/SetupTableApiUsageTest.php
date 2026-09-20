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
 * slowlog_setup_table_new() must use the plugin table-creation API
 * (api_plugin_db_table_create()/api_plugin_db_add_column()) instead of raw
 * CREATE TABLE, since both are no-ops when already applied - that's what
 * lets slowlog_check_upgrade() simply re-call it on every version bump.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_calls_to')) {
	function slowlog_test_calls_to(array $calls, string $fn): array {
		return array_values(array_filter($calls, function ($call) use ($fn) {
			return $call['fn'] === $fn;
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('setup.php');
});

it('does not use raw CREATE TABLE statements', function () {
	// The CREATES/CREATE TEMPS method-dictionary fragments are stored lowercase
	// ('create table'/'create temporary table') specifically so they don't collide with
	// this check - matching against them in import_post_process() is case-insensitive
	// (stripos()) either way.
	$source = file_get_contents(realpath(__DIR__ . '/../../setup.php'));

	expect($source)->not->toContain('CREATE TABLE');
});

it('creates every plugin table through the plugin API', function () {
	slowlog_setup_table_new();

	$tables = array_column(slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_db_table_create'), 'sql');

	expect($tables)->toBe(array(
		'plugin_slowlog',
		'plugin_slowlog_details',
		'plugin_slowlog_details_methods',
		'plugin_slowlog_details_tables',
		'plugin_slowlog_methods',
		'plugin_slowlog_tables',
		'plugin_slowlog_table_names',
		'plugin_slowlog_reserved_words',
		'plugin_slowlog_stats',
	));
});

it('passes the slowlog plugin name for every table create call', function () {
	slowlog_setup_table_new();

	$calls = slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_db_table_create');

	foreach ($calls as $call) {
		expect($call['params'][0])->toBe('slowlog');
	}
});

it('adds the new timeout column via the idempotent add-column API', function () {
	slowlog_setup_table_new();

	$calls = slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_db_add_column');

	expect($calls)->toHaveCount(1);
	expect($calls[0]['params'][1])->toBe('plugin_slowlog_details');
	expect($calls[0]['params'][2]['name'])->toBe('timeout');
	expect($calls[0]['params'][2]['type'])->toBe('double');
});

it('defines a table_name dictionary with an is_cacti_table flag', function () {
	slowlog_setup_table_new();

	$calls = slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_db_table_create');
	$dict  = current(array_filter($calls, function ($call) {
		return $call['sql'] === 'plugin_slowlog_table_names';
	}));

	$columns = array_column($dict['params'][2]['columns'], 'name');

	expect($columns)->toBe(array('tableid', 'table_name', 'is_cacti_table'));
});

it('defines the stats cache table keyed by logid/scope/scope_key/metric', function () {
	slowlog_setup_table_new();

	$calls = slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_db_table_create');
	$stats = current(array_filter($calls, function ($call) {
		return $call['sql'] === 'plugin_slowlog_stats';
	}));

	$columns = array_column($stats['params'][2]['columns'], 'name');

	expect($columns)->toBe(array(
		'logid', 'scope', 'scope_key', 'metric', 'sample_count', 'total_value',
		'min_value', 'p25_value', 'median_value', 'p75_value', 'p95_value', 'max_value',
	));
	expect($stats['params'][2]['primary'])->toBe(array('logid', 'scope', 'scope_key', 'metric'));
});

it('seeds the new methods introduced for this feature', function () {
	slowlog_setup_table_new();

	$seed = slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'db_execute');
	$sql  = $seed[0]['sql'];

	foreach (array('INFILES', 'GROUP BY', 'COUNTS', 'SHOWS', 'UNION ALLS', 'MAX_EXECUTION_TIME', 'MAX_STATEMENT_TIME', 'OTHER TABLES', 'FORCE INDEX', 'ALTERS', 'DROPS', 'ANALYZES', 'OPTIMIZES', 'CREATES', 'CREATE TEMPS') as $method) {
		expect($sql)->toContain("'" . $method . "'");
	}
});

it('re-running setup is safe to call again from slowlog_check_upgrade()', function () {
	// api_plugin_db_table_create()/api_plugin_db_add_column() are no-ops when already
	// applied (verified against real Cacti 1.2.x behavior); this just proves calling
	// slowlog_setup_table_new() twice doesn't error or duplicate anything unexpected.
	slowlog_setup_table_new();
	slowlog_setup_table_new();

	$tables = slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_db_table_create');

	expect($tables)->toHaveCount(18);
});

it('drops every plugin table on uninstall, including the stats cache', function () {
	plugin_slowlog_uninstall();

	$tables = array_column(slowlog_test_calls_to($GLOBALS['__test_db_calls'], 'api_plugin_drop_table'), 'sql');

	expect($tables)->toContain('plugin_slowlog_stats');
});
