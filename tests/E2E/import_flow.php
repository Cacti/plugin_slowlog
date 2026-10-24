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

require_once __DIR__ . '/e2e_boot.php';

$fixture = $plugin . '/tests/E2E/fixtures/sample-slow.log';
$out     = ['scenarios' => []];
$made    = [];

/**
 * Runs import_log.php for a log file and returns its exit status and output.
 *
 * @param string $logfile The log to import.
 * @param string $name    The description to import it under.
 * @param string $extra   Command line options after the defaults; the last of a repeated option wins.
 * @param bool   $mode    Whether to pass --table-mode=all first.
 *
 * @return array{0: int, 1: string}
 */
function e2e_import(string $logfile, string $name, string $extra = '', bool $mode = true): array {
	global $plugin;

	$lines  = [];
	$status = 0;

	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/import_log.php') . ' --logfile=' . escapeshellarg($logfile)
		. ' --description=' . escapeshellarg($name) . ($mode ? ' --table-mode=all ' : ' ') . $extra . ' 2>&1', $lines, $status);

	return [$status, implode("\n", $lines)];
}

/**
 * @return int The logid imported under a description, or 0.
 */
function e2e_logid(string $name): int {
	return (int) db_fetch_cell_prepared('SELECT logid FROM plugin_slowlog WHERE description = ?', [$name]);
}

/**
 * Imports a log through the CLI and records what reached the plugin tables.
 *
 * @param string             $name   The scenario name; also the import's description suffix.
 * @param array<int, string> $lines  The log, one element per line, or a path to an existing log.
 * @param string             $extra  Extra command line options.
 * @param bool               $detail Whether to keep every row (small logs only).
 * @param bool               $mode   Whether to pass --table-mode=all.
 */
function e2e_scenario(string $name, $lines, string $extra = '', bool $detail = true, bool $mode = true): void {
	global $tag, $tmp, $out, $made;

	$file = is_array($lines) ? $tmp . '/' . $name . '.log' : $lines;

	if (is_array($lines)) {
		file_put_contents($file, implode("\n", $lines));
	}

	list($status, $text) = e2e_import($file, $tag . $name, $extra, $mode);

	$logid = e2e_logid($tag . $name);
	$obs   = ['exit' => $status, 'output' => substr($text, 0, 300), 'logid' => $logid, 'file_kept' => is_file($file)];

	if ($logid) {
		$made[] = $logid;

		$obs['log']    = db_fetch_row_prepared('SELECT import_status, import_text_status, import_lines FROM plugin_slowlog WHERE logid = ?', [$logid]);
		$obs['count']  = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_details WHERE logid = ?', [$logid]);
		$obs['assoc']  = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_details_tables WHERE logid = ?', [$logid]);
		$obs['tables'] = array_column(db_fetch_assoc_prepared('SELECT DISTINCT table_name FROM plugin_slowlog_details_tables WHERE logid = ? ORDER BY table_name', [$logid]), 'table_name');
		$obs['keys']   = array_column(db_fetch_assoc_prepared("SELECT DISTINCT scope_key FROM plugin_slowlog_stats WHERE logid = ? AND scope = 'table' ORDER BY scope_key", [$logid]), 'scope_key');
		$obs['methods'] = array_column(db_fetch_assoc_prepared('SELECT DISTINCT method FROM plugin_slowlog_details_methods WHERE logid = ? ORDER BY method', [$logid]), 'method');

		if ($detail) {
			$obs['rows'] = db_fetch_assoc_prepared('SELECT user, host, ip_address AS ip, `schema` AS db_schema, rows_sent, rows_examined, bytes_sent, query, UNIX_TIMESTAMP(date) AS ts
				FROM plugin_slowlog_details WHERE logid = ? ORDER BY logentry', [$logid]);
		}
	}

	if (is_array($lines)) {
		@unlink($file);
	}

	$out['scenarios'][$name] = $obs;
}

/**
 * @param array<int, string> $head The header lines of one entry.
 */
function e2e_entry(string $time, string $userhost, array $head, string $query): array {
	return array_merge(['# Time: ' . $time, '# User@Host: ' . $userhost], $head, [$query]);
}

$timing = '# Query_time: 1.000000  Lock_time: 0.000100  Rows_sent: 1  Rows_examined: 1';

// 1. the sample log, as an operator imports it
e2e_scenario('main', $fixture);

// 2. MySQL 8 format: ISO time, Id suffix, IPv6 client, empty host, a "use" line
e2e_scenario('mysql8', array_merge(
	['/usr/sbin/mysqld, Version: 8.0.36 (MySQL Community Server - GPL). started with:'],
	e2e_entry('2024-01-15T01:02:03.123456Z', 'root[root] @ localhost [127.0.0.1]  Id:     8', ['# Query_time: 0.000123  Lock_time: 0.000000 Rows_sent: 1  Rows_examined: 1', 'SET timestamp=1705280523;'], 'SELECT * FROM mysql_tbl;'),
	e2e_entry('2024-01-15T01:02:04.000000Z', 'app[app] @  [2001:db8::17]  Id: 9', [$timing, 'use appdb;', 'SET timestamp=1705280524;'], 'DELETE FROM old_tbl WHERE id < 5;'),
	['# Time: 2024-01-15T01:02:05.000000Z', '']
));

// 3. MariaDB format: Thread_id, Schema, QC_hit, Rows_affected, administrator command
e2e_scenario('mariadb', array_merge(
	e2e_entry('240115  1:02:03', 'maria[maria] @ db1 [10.1.1.1]', ['# Thread_id: 11  Schema: shop  QC_hit: Yes', $timing, '# Rows_affected: 4  Bytes_sent: 2048', 'SET timestamp=1705280523;'], 'UPDATE orders SET paid = 1 WHERE id = 4;'),
	['# administrator command: Prepare;'],
	e2e_entry('240115  1:02:05', 'maria[maria] @ db1 [10.1.1.1]', ['# Thread_id: 12  Schema: shop  QC_hit: No', $timing, '# Rows_affected: 0  Bytes_sent: 10', 'SET timestamp=1705280525;'], 'SELECT id FROM orders JOIN items ON items.order_id = orders.id;'),
	['# Time: 240115  1:02:09', '']
));

// 4. values at the column limits and beyond (the server truncates what does not fit)
$ip45 = '0000:0000:0000:0000:0000:ffff:192.168.100.200';
$edge = array_merge(
	e2e_entry('240115  1:02:03', str_repeat('u', 128) . '[x] @ h1 [' . $ip45 . ']', ['# Thread_id: 1  Schema: ' . str_repeat('s', 64) . '  QC_hit: No', $timing, 'SET timestamp=1705280523;'], 'SELECT 1 FROM `' . str_repeat('t', 64) . '`;'),
	e2e_entry('240115  1:02:04', str_repeat('v', 140) . '[x] @ h2 [' . str_repeat('9', 50) . ']', ['# Thread_id: 2  Schema: ' . str_repeat('d', 70) . '  QC_hit: No', $timing, 'SET timestamp=1705280524;'], 'SELECT 1 FROM `' . str_repeat('w', 70) . '`;'),
	['# Time: 240115  1:02:09', '']
);

e2e_scenario('limits', $edge);

// 5. queries with line breaks and comments of every kind
e2e_scenario('comments', array_merge(
	e2e_entry('240115  1:02:03', 'c[c] @ h [10.0.0.1]', [$timing, 'SET timestamp=1705280523;'], "SELECT * FROM a -- first comment\nJOIN b ON a.id = b.id # second comment\nLEFT JOIN c ON c.id = a.id /* third */\nWHERE a.x = '-- not a comment';"),
	e2e_entry('240115  1:02:04', 'c[c] @ h [10.0.0.1]', [$timing, 'SET timestamp=1705280524;'], "SELECT 'FROM zzz' AS note FROM d;"),
	['# Time: 240115  1:02:09', '']
));

// the server writes no Time line for an entry in the same second as the one before it
e2e_scenario('sametime', array_merge(
	e2e_entry('240115  1:02:03', 's[s] @ h [10.0.0.8]', [$timing, 'SET timestamp=1705280523;'], 'SELECT 1 FROM t1;'),
	['# User@Host: s2[s2] @ h [10.0.0.9]', $timing, 'SET timestamp=1705280523;', 'SELECT 2 FROM t2;', '# Time: 240115  1:02:09', '']
), '--length=200');

// a log that simply stops after its last query (no closing Time line), with a header that cannot be
// read and a statement that names no table
e2e_scenario('noeof', array_merge(
	e2e_entry('240115  1:02:03', 'garbage', [$timing, 'SET timestamp=1705280523;'], 'SET @counter = 1;'),
	e2e_entry('240115  1:02:04', 'last[last] @ h [10.0.0.10]', [$timing, 'SET timestamp=1705280524;'], 'SELECT 9 FROM t9;')
), '--length=200');

// 6. a log with no entries, and one whose last entry is cut off
e2e_scenario('empty', ['not a slow log', 'just text', ''], '', false);
e2e_scenario('truncated', array_merge(
	e2e_entry('240115  1:02:03', 't[t] @ h [10.0.0.2]', [$timing, 'SET timestamp=1705280523;'], 'SELECT 1 FROM t1;'),
	e2e_entry('240115  1:02:04', 't[t] @ h [10.0.0.2]', [$timing, 'SET timestamp=1705280524;'], 'SELECT 2 FROM t2'),
	['# Time: 240115  1:02:05', '# User@Host: t[t] @ h [10.0.0.2]', '# Query_time: 1.0  Lock_time: 0.0  Rows_sent: 1']
));

// 7. a parent record created up front (as the upload page does), with a bad file and a good one
db_execute_prepared('INSERT INTO plugin_slowlog (description) VALUES (?)', [$tag . 'upfront-bad']);
$upfront_bad = (int) db_fetch_insert_id();
db_execute_prepared('INSERT INTO plugin_slowlog (description) VALUES (?)', [$tag . 'upfront-good']);
$upfront_good = (int) db_fetch_insert_id();
$made[] = $upfront_bad;
$made[] = $upfront_good;

file_put_contents($tmp . '/upfront-bad.log', "not a log\n");
file_put_contents($tmp . '/upfront-good.log', implode("\n", e2e_entry('240115  1:02:03', 'u[u] @ h [10.0.0.3]', [$timing, 'SET timestamp=1705280523;'], 'SELECT 1 FROM t1;')) . "\n# Time: 240115  1:02:09\n");

e2e_import($tmp . '/upfront-bad.log', $tag . 'upfront-bad', '--logid=' . $upfront_bad . ' --delete-after');
e2e_import($tmp . '/upfront-good.log', $tag . 'upfront-good', '--logid=' . $upfront_good . ' --delete-after');

$out['upfront'] = [
	'bad'       => db_fetch_row_prepared('SELECT import_status, import_text_status FROM plugin_slowlog WHERE logid = ?', [$upfront_bad]),
	'good'      => db_fetch_row_prepared('SELECT import_status, import_text_status, import_lines FROM plugin_slowlog WHERE logid = ?', [$upfront_good]),
	'bad_gone'  => !is_file($tmp . '/upfront-bad.log'),
	'good_gone' => !is_file($tmp . '/upfront-good.log'),
];

// 8. table modes
$reference = "host\ngraph_local\n\tsettings  rrd_archive_snapshot_extended_history_table_for_capacity_report\n";

e2e_scenario('mode_cacti', $fixture, '--table-mode=cacti', false);
e2e_scenario('mode_usecacti', $fixture, '--usecacti', false, false);
e2e_scenario('mode_reference_some', $fixture, '--table-mode=reference --table-names=' . escapeshellarg("host\ngraph_local\n"), false);
e2e_scenario('mode_reference_all', $fixture, '--table-mode=reference --table-names=' . escapeshellarg($reference), false);
e2e_scenario('mode_list', $fixture, '--table-names=' . escapeshellarg("host\t graph_local\nsettings"), false, false);

// 9. a failing INSERT stops the import: through the final flush, and through a full batch
$synthetic = ['SELECT * FROM host WHERE id = {i}', 'UPDATE graph_local SET x = {i} WHERE id = 1', "SELECT a.id FROM settings AS a -- c\nJOIN host ON host.id = a.id"];

slowlog_e2e_synthetic_log($tmp . '/fail-final.log', 3, $synthetic);
slowlog_e2e_synthetic_log($tmp . '/fail-batch.log', SLOWLOG_IMPORT_BATCH_SIZE + 5, $synthetic);

foreach (['fail-final', 'fail-batch'] as $name) {
	file_put_contents($tmp . '/' . $name . '.log', preg_replace('/# User@Host: cacti\[cacti\]/', '# User@Host: e2e_fail_user[x]', (string) file_get_contents($tmp . '/' . $name . '.log'), 1));
}

db_execute('DROP TRIGGER IF EXISTS slowlog_e2e_fail');
db_execute("CREATE TRIGGER slowlog_e2e_fail BEFORE INSERT ON plugin_slowlog_details FOR EACH ROW
	BEGIN
		IF NEW.user = 'e2e_fail_user' THEN
			SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure';
		END IF;
	END");

e2e_scenario('fail_final', $tmp . '/fail-final.log', '', false);
e2e_scenario('fail_batch', $tmp . '/fail-batch.log', '', false);

db_execute('DROP TRIGGER IF EXISTS slowlog_e2e_fail');
@unlink($tmp . '/fail-final.log');
@unlink($tmp . '/fail-batch.log');

// 10. a log larger than one chunk of detail rows (2000) and one INSERT batch (1000)
slowlog_e2e_synthetic_log($tmp . '/large.log', 2100, $synthetic);
file_put_contents($tmp . '/cacti.log', '');

e2e_scenario('large', $tmp . '/large.log', '', false);

$out['large_log_line'] = preg_match('/Post Processing Tables associated: (\d+)/', (string) file_get_contents($tmp . '/cacti.log'), $m) ? (int) $m[1] : 0;

// 11. the length option on multibyte text, and reprocessing an existing log
e2e_scenario('multibyte', array_merge(
	e2e_entry('240115  1:02:03', 'm[m] @ h [10.0.0.4]', [$timing, 'SET timestamp=1705280523;'], "SELECT '" . str_repeat("\u{00e9}", 40) . "' FROM host;"),
	['# Time: 240115  1:02:09', '']
), '--length=21');

$reprocess = e2e_logid($tag . 'main');

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/import_log.php') . ' --reprocess=' . $reprocess . ' --table-names=' . escapeshellarg("host\ngraph_local") . ' --table-mode=reference 2>&1', $ignore, $status);

$out['reprocess'] = [
	'exit'    => $status,
	'log'     => db_fetch_row_prepared('SELECT import_status, import_text_status FROM plugin_slowlog WHERE logid = ?', [$reprocess]),
	'methods' => array_column(db_fetch_assoc_prepared('SELECT DISTINCT method FROM plugin_slowlog_details_methods WHERE logid = ? ORDER BY method', [$reprocess]), 'method'),
];

foreach ($made as $id) {
	api_slowlog_remove($id);
}

slowlog_e2e_restore_log_setting($saved_log);

print json_encode($out) . PHP_EOL;
