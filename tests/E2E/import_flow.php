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
 * Driver for ImportFlowTest. Runs in its own process against an installed Cacti and its
 * database: imports a sample slow log through the real import_log.php CLI, then checks what
 * reached the plugin tables. Prints one JSON object on the last line of output.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_common.php';

$root = slowlog_e2e_root();
$tmp  = rtrim((string) getenv('SLOWLOG_E2E_TMP'), '/');

chdir($root);

$_SERVER['argv'] = [basename(__FILE__)];

require_once $root . '/include/cli_check.php';
require_once $root . '/plugins/slowlog/includes/database.php';
require_once $root . '/plugins/slowlog/includes/functions.php';

$saved_log = slowlog_e2e_redirect_log($tmp);

$plugin  = $root . '/plugins/slowlog';
$fixture = $plugin . '/tests/E2E/fixtures/sample-slow.log';
$tag     = 'e2e-' . getmypid();
$out     = [];

/**
 * Runs import_log.php for a log file and returns its exit status and output.
 *
 * @param string $logfile The log to import.
 * @param string $name    The description to import it under.
 * @param string $extra   Extra command line options.
 *
 * @return array{0: int, 1: string}
 */
function e2e_import(string $logfile, string $name, string $extra = ''): array {
	global $plugin;

	$lines  = [];
	$status = 0;

	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/import_log.php') . ' --logfile=' . escapeshellarg($logfile)
		. ' --description=' . escapeshellarg($name) . ' --table-mode=all ' . $extra . ' 2>&1', $lines, $status);

	return [$status, implode("\n", $lines)];
}

/**
 * @return int The logid imported under a description, or 0.
 */
function e2e_logid(string $name): int {
	return (int) db_fetch_cell_prepared('SELECT logid FROM plugin_slowlog WHERE description = ?', [$name]);
}

// 1. the sample log, through the CLI the way an operator runs it
list($status, $text) = e2e_import($fixture, $tag . '-main');
$logid = e2e_logid($tag . '-main');

$out['main_status'] = $status;
$out['main_output'] = $text;

$out['log'] = db_fetch_row_prepared('SELECT import_status, import_text_status, import_lines FROM plugin_slowlog WHERE logid = ?', [$logid]);

$out['rows'] = db_fetch_assoc_prepared('SELECT user, host, ip_address, `schema`, rows_sent, rows_examined, bytes_sent, query
	FROM plugin_slowlog_details WHERE logid = ? ORDER BY logentry', [$logid]);

$out['tables'] = array_column(db_fetch_assoc_prepared('SELECT DISTINCT table_name FROM plugin_slowlog_details_tables WHERE logid = ? ORDER BY table_name', [$logid]), 'table_name');
$out['stat_keys'] = array_column(db_fetch_assoc_prepared("SELECT DISTINCT scope_key FROM plugin_slowlog_stats WHERE logid = ? AND scope = 'table' ORDER BY scope_key", [$logid]), 'scope_key');
$out['methods']   = array_column(db_fetch_assoc_prepared('SELECT DISTINCT method FROM plugin_slowlog_details_methods WHERE logid = ? ORDER BY method', [$logid]), 'method');

$out['widths'] = [
	'ip_address' => (int) db_fetch_cell("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_slowlog_details' AND COLUMN_NAME = 'ip_address'"),
	'user'       => (int) db_fetch_cell("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_slowlog_details' AND COLUMN_NAME = 'user'"),
];

// 2. a failed INSERT must fail the import instead of completing it with rows missing; the
// trigger stands in for any server side failure (a packet over max_allowed_packet, a lock
// timeout) and is removed again afterwards
$bad = $tmp . '/' . $tag . '-bad.log';

file_put_contents($bad, implode("\n", [
	'# Time: 240115  1:02:03',
	'# User@Host: e2e_fail_user[e2e_fail_user] @ localhost [127.0.0.1]  Id: 5',
	'# Query_time: 1.0  Lock_time: 0.0  Rows_sent: 1  Rows_examined: 1',
	'SET timestamp=1705280523;',
	'SELECT 1;',
	'# Time: 240115  1:05:00',
	'',
]));

db_execute('DROP TRIGGER IF EXISTS slowlog_e2e_fail');
db_execute("CREATE TRIGGER slowlog_e2e_fail BEFORE INSERT ON plugin_slowlog_details FOR EACH ROW
	BEGIN
		IF NEW.user = 'e2e_fail_user' THEN
			SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure';
		END IF;
	END");

list($status, $text) = e2e_import($bad, $tag . '-bad');
$bad_id = e2e_logid($tag . '-bad');

db_execute('DROP TRIGGER IF EXISTS slowlog_e2e_fail');

$out['bad_log']  = db_fetch_row_prepared('SELECT import_status, import_text_status FROM plugin_slowlog WHERE logid = ?', [$bad_id]);
$out['bad_rows'] = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_details WHERE logid = ?', [$bad_id]);
@unlink($bad);

// 3. a length cap must not cut a multibyte character in half
$multi = $tmp . '/' . $tag . '-multi.log';

file_put_contents($multi, implode("\n", [
	'# Time: 240115  1:02:03',
	'# User@Host: root[root] @ localhost [127.0.0.1]  Id: 5',
	'# Query_time: 1.0  Lock_time: 0.0  Rows_sent: 1  Rows_examined: 1',
	'SET timestamp=1705280523;',
	"SELECT '" . str_repeat("\u{00e9}", 40) . "' FROM host;",
	'# Time: 240115  1:05:00',
	'',
]));

list($status, $text) = e2e_import($multi, $tag . '-multi', '--length=21');
$multi_id = e2e_logid($tag . '-multi');

$out['multi_log']   = db_fetch_row_prepared('SELECT import_status FROM plugin_slowlog WHERE logid = ?', [$multi_id]);
$out['multi_query'] = (string) db_fetch_cell_prepared('SELECT query FROM plugin_slowlog_details WHERE logid = ?', [$multi_id]);
@unlink($multi);

// 4. the upgrade path: an install at the old schema and version is widened and re-versioned
$old = ['plugin_slowlog_details' => [['user', 'varchar(20) NOT NULL'], ['ip_address', 'varchar(15) NOT NULL']],
	'plugin_slowlog' => [['import_text_status', "varchar(40) NOT NULL DEFAULT ''"]],
	'plugin_slowlog_details_tables' => [['table_name', 'varchar(45) NOT NULL']],
	'plugin_slowlog_table_names' => [['table_name', "varchar(45) NOT NULL DEFAULT ''"]],
	'plugin_slowlog_stats' => [['scope_key', 'varchar(45) NOT NULL']]];

// rows from the sample import would not fit the old widths, so remove them first
foreach ([$logid, $bad_id, $multi_id] as $id) {
	api_slowlog_remove($id);
}

foreach ($old as $table => $columns) {
	foreach ($columns as $column) {
		db_execute("ALTER TABLE `$table` MODIFY COLUMN `{$column[0]}` {$column[1]}");
	}
}

// slowlog_check_upgrade() prunes the dev-only files, tests/ included, from base_path/plugins/slowlog
// once the upgrade succeeds. Run it against a scratch copy of the plugin directory so a test run can
// never delete the real tree. The copy's database.php is an empty stub because the real one is
// already loaded.
$scratch = $tmp . '/upgrade-tree';

slowlog_e2e_copy_tree($plugin, $scratch . '/plugins/slowlog');
file_put_contents($scratch . '/plugins/slowlog/includes/database.php', "<?php\n");

$real_base = $config['base_path'];
$real_version = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']);

db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', ['2.6', 'slowlog']);

$_SERVER['PHP_SELF'] = '/cacti/plugins.php';
$config['base_path'] = $scratch;

try {
	slowlog_check_upgrade();
} finally {
	$config['base_path'] = $real_base;

	// a run that stopped half way must not leave the installed version behind the code
	if (db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']) != $real_version) {
		db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$real_version, 'slowlog']);
	}
}

$out['upgrade_version']       = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']);
$out['scratch_tests_pruned']  = !is_dir($scratch . '/plugins/slowlog/tests');
$out['real_tests_intact']     = is_file($plugin . '/tests/bin/patch-coverage.php') && is_file($fixture);
$out['upgrade_widths']  = [];

foreach ($old as $table => $columns) {
	foreach ($columns as $column) {
		$out['upgrade_widths'][$table . '.' . $column[0]] = (int) db_fetch_cell_prepared('SELECT CHARACTER_MAXIMUM_LENGTH
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column[0]]);
	}
}

slowlog_e2e_restore_log_setting($saved_log);
slowlog_e2e_remove_tree($scratch);

print json_encode($out) . PHP_EOL;
