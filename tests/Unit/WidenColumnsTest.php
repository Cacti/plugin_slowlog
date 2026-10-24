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
 * Several columns were too narrow for real slow log data: user and schema at 20 characters,
 * ip_address at 15, table names at 45 and the import status text at 40. A value that does not
 * fit is truncated, or makes the INSERT fail in strict SQL mode. New installs get the wider definitions,
 * and slowlog_widen_columns() widens existing installs once, during the version upgrade.
 */

uses(TestCase::class);

beforeEach(function () {
	require_once __DIR__ . '/../../includes/database.php';
});

/**
 * Column definitions by name from a table definition array.
 */
function slowlog_test_column(array $data, string $name): array {
	foreach ($data['columns'] as $column) {
		if ($column['name'] === $name) {
			return $column;
		}
	}

	return array();
}

it('defines the details columns wide enough for MySQL and MariaDB names', function () {
	$data = slowlog_details_table_data();

	expect(slowlog_test_column($data, 'user')['type'])->toBe('varchar(128)');
	expect(slowlog_test_column($data, 'ip_address')['type'])->toBe('varchar(45)');
	expect(slowlog_test_column($data, 'schema')['type'])->toBe('varchar(64)');
});

it('creates the other widened columns with the new widths on a fresh install', function () {
	slowlog_setup_table_new();

	$created = array();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'api_plugin_db_table_create') {
			$created[$call['sql']] = $call['params'][2];
		}
	}

	expect(slowlog_test_column($created['plugin_slowlog'], 'import_text_status')['type'])->toBe('varchar(128)');
	expect(slowlog_test_column($created['plugin_slowlog_details_tables'], 'table_name')['type'])->toBe('varchar(64)');
	expect(slowlog_test_column($created['plugin_slowlog_table_names'], 'table_name')['type'])->toBe('varchar(64)');
	expect(slowlog_test_column($created['plugin_slowlog_stats'], 'scope_key')['type'])->toBe('varchar(64)');
});

function slowlog_test_alters(): array {
	return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && strpos($call['sql'], 'ALTER TABLE `plugin_slowlog') === 0;
	}));
}

it('widens each column that is still at the old width', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog', true);
	slowlog_test_mock_db('db_fetch_cell_prepared', 'information_schema', '45');

	expect(slowlog_widen_columns())->toBeTrue();

	$alters = slowlog_test_alters();

	expect($alters)->toHaveCount(4);
	expect($alters[0]['sql'])->toContain('`plugin_slowlog` MODIFY COLUMN `import_text_status` varchar(128)');
	expect($alters[0]['sql'])->toContain("COMMENT 'The text status of the import process'");
	expect($alters[1]['sql'])->toContain('`plugin_slowlog_details_tables` MODIFY COLUMN `table_name` varchar(64) NOT NULL');
	expect($alters[2]['sql'])->toContain('`plugin_slowlog_table_names` MODIFY COLUMN `table_name` varchar(64)');
	expect($alters[3]['sql'])->toContain('`plugin_slowlog_stats` MODIFY COLUMN `scope_key` varchar(64)');
});

it('does nothing when the columns are already wide enough', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog', true);
	slowlog_test_mock_db('db_fetch_cell_prepared', 'information_schema', '128');

	expect(slowlog_widen_columns())->toBeTrue();
	expect(slowlog_test_alters())->toBeEmpty();
});

it('skips a table that does not exist', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'information_schema', '45');

	expect(slowlog_widen_columns())->toBeTrue();
	expect(slowlog_test_alters())->toBeEmpty();
});

it('reports failure when an ALTER fails', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog', true);
	slowlog_test_mock_db('db_fetch_cell_prepared', 'information_schema', '45');
	slowlog_test_mock_db('db_execute', 'MODIFY COLUMN `scope_key`', false);

	expect(slowlog_widen_columns())->toBeFalse();
	expect(slowlog_test_alters())->toHaveCount(4);
});

it('reports failure from the upgrade when the details table diff fails', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details', true);
	slowlog_test_mock_db('db_update_table', 'plugin_slowlog_details', false);

	expect(slowlog_upgrade_tables())->toBeFalse();
});

it('reports success from the upgrade when every step succeeds', function () {
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details', true);

	expect(slowlog_upgrade_tables())->toBeTrue();
});
