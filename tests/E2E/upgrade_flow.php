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
 * Driver for UpgradeFlowTest. Takes a 2.6 install (old column widths, version 2.6) through the
 * version-change upgrade of an installed Cacti and its database. Prints one JSON object on the
 * last line of output.
 *
 * The upgrade runs in a scratch copy of the plugin directory: a successful upgrade prunes the
 * dev-only files (tests/ included) from the directory it runs in, and that must never be the real
 * tree. The failing-ALTER path is covered by unit tests: Cacti's table API re-syncs a damaged
 * table before the widening runs, so a failure cannot be induced the same way on every Cacti.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_common.php';

$root = slowlog_e2e_enter(__FILE__);
$tmp  = slowlog_e2e_tmp();

require_once $root . '/include/cli_check.php';

$saved_log = slowlog_e2e_redirect_log($tmp);
$plugin    = $root . '/plugins/slowlog';
$out       = [];

$old = [
	'ALTER TABLE plugin_slowlog_details MODIFY COLUMN user varchar(20) NOT NULL',
	'ALTER TABLE plugin_slowlog_details MODIFY COLUMN ip_address varchar(15) NOT NULL',
	"ALTER TABLE plugin_slowlog_details MODIFY COLUMN `schema` varchar(20) NOT NULL DEFAULT ''",
	"ALTER TABLE plugin_slowlog MODIFY COLUMN import_text_status varchar(40) NOT NULL DEFAULT ''",
	'ALTER TABLE plugin_slowlog_details_tables MODIFY COLUMN table_name varchar(45) NOT NULL',
	"ALTER TABLE plugin_slowlog_table_names MODIFY COLUMN table_name varchar(45) NOT NULL DEFAULT ''",
	'ALTER TABLE plugin_slowlog_stats MODIFY COLUMN scope_key varchar(45) NOT NULL',
];
$columns = [
	['plugin_slowlog_details', 'user'], ['plugin_slowlog_details', 'ip_address'], ['plugin_slowlog_details', 'schema'],
	['plugin_slowlog', 'import_text_status'], ['plugin_slowlog_details_tables', 'table_name'],
	['plugin_slowlog_table_names', 'table_name'], ['plugin_slowlog_stats', 'scope_key'],
];

/**
 * @return array<string, int> The current width of each column the upgrade widens.
 */
function e2e_widths(array $columns): array {
	$widths = [];

	foreach ($columns as $column) {
		$widths[$column[0] . '.' . $column[1]] = (int) db_fetch_cell_prepared('SELECT CHARACTER_MAXIMUM_LENGTH
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$column[0], $column[1]]);
	}

	return $widths;
}

foreach ($old as $statement) {
	db_execute($statement);
}

$scratch = $tmp . '/upgrade-tree';

slowlog_e2e_copy_tree($plugin, $scratch . '/plugins/slowlog');

// the real file is already loaded, so the copy's is an empty stub
file_put_contents($scratch . '/plugins/slowlog/includes/database.php', "<?php\n");

$real_base    = $config['base_path'];
$real_version = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']);

db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', ['2.6', 'slowlog']);

$_SERVER['PHP_SELF'] = '/cacti/plugins.php';
$config['base_path'] = $scratch;

try {
	slowlog_check_upgrade();

	$out['version']      = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']);
	$out['widths']       = e2e_widths($columns);
	$out['tests_pruned'] = !is_dir($scratch . '/plugins/slowlog/tests');
	$out['real_intact']  = is_file($plugin . '/tests/bin/patch-coverage.php') && is_file($plugin . '/tests/E2E/fixtures/sample-slow.log');
} finally {
	$config['base_path'] = $real_base;

	// a run that stopped half way must not leave the installed version behind the code
	db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$real_version, 'slowlog']);
}

slowlog_e2e_restore_log_setting($saved_log);
slowlog_e2e_remove_tree($scratch);

print json_encode($out) . PHP_EOL;
