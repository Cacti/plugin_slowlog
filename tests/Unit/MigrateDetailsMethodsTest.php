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
 * Coverage for slowlog_migrate_details_methods_to_method(): the idempotent in-place
 * migration that upgrades an existing plugin_slowlog_details_methods table from the
 * pre-2.6 (logid, logentry, methodid) layout to the de-normalized method varchar
 * layout. api_plugin_db_table_create() never retrofits an existing table, so without
 * this migration existing installs would keep methodid (and no method column) and every
 * updated import/detail/stat/chart query would fail with an unknown-column error.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_migration_executes')) {
	function slowlog_test_migration_executes(): array {
		return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute';
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('includes/database.php');
});

it('does nothing when the association table does not exist', function () {
	// db_table_exists defaults to false, so the table is absent.
	slowlog_migrate_details_methods_to_method();

	expect(slowlog_test_migration_executes())->toBeEmpty();
});

it('does nothing when the methodid column is already gone', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details_methods', true);
	// db_column_exists defaults to false, so methodid is reported absent (already migrated).

	slowlog_migrate_details_methods_to_method();

	expect(slowlog_test_migration_executes())->toBeEmpty();
});

it('adds the method column, backfills from the dictionary, and swaps the primary key', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details_methods', true);
	slowlog_test_mock_db('db_column_exists', function ($sql, $params) {
		return $params[1] === 'methodid';
	}, true);

	slowlog_migrate_details_methods_to_method();

	$executes = slowlog_test_migration_executes();

	$addColumn = array_filter($executes, function ($call) {
		return stripos($call['sql'], 'ADD COLUMN `method`') !== false;
	});
	expect($addColumn)->toHaveCount(1);

	$backfill = array_filter($executes, function ($call) {
		return stripos($call['sql'], 'UPDATE plugin_slowlog_details_methods') !== false
			&& stripos($call['sql'], 'JOIN plugin_slowlog_methods') !== false;
	});
	expect($backfill)->toHaveCount(1);

	$swapKey = array_filter($executes, function ($call) {
		return stripos($call['sql'], 'DROP PRIMARY KEY') !== false
			&& stripos($call['sql'], 'DROP COLUMN `methodid`') !== false;
	});
	expect($swapKey)->toHaveCount(1);
});

it('skips adding the method column when it already exists', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details_methods', true);
	slowlog_test_mock_db('db_column_exists', function ($sql, $params) {
		return in_array($params[1], array('methodid', 'method'), true);
	}, true);

	slowlog_migrate_details_methods_to_method();

	$executes = slowlog_test_migration_executes();

	$addColumn = array_filter($executes, function ($call) {
		return stripos($call['sql'], 'ADD COLUMN `method`') !== false;
	});
	expect($addColumn)->toBeEmpty();

	$backfill = array_filter($executes, function ($call) {
		return stripos($call['sql'], 'UPDATE plugin_slowlog_details_methods') !== false;
	});
	expect($backfill)->toHaveCount(1);

	$swapKey = array_filter($executes, function ($call) {
		return stripos($call['sql'], 'DROP PRIMARY KEY') !== false;
	});
	expect($swapKey)->toHaveCount(1);
});
