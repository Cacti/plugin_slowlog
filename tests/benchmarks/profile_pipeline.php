<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

// Isolated CPU/memory benchmark: database adapters discard writes and generate
// deterministic rows. This does not measure database or whole-request latency.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$options = getopt('', ['root:', 'case:', 'entries:', 'groups:']);
$root = $options['root'] ?? dirname(__DIR__, 2);
$case = $options['case'] ?? 'stats';
$entries = max(1, (int) ($options['entries'] ?? 100000));
$groups = max(1, (int) ($options['groups'] ?? 64));
$config = ['base_path' => $root];
define('MESSAGE_LEVEL_INFO', 4);
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function __($text, ...$args) { return $text; }
function cacti_log(...$args) {}
function raise_message(...$args) {}
function read_config_option($name) { return PHP_BINARY; }
function cacti_escapeshellcmd($value) { return escapeshellcmd($value); }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function exec_background(...$args) {}
function sql_save(...$args) { return 1; }
function db_execute(...$args) { return true; }
function db_execute_prepared(...$args) { return true; }
function db_qstr($value) { return "'" . addslashes((string) $value) . "'"; }
function db_fetch_cell_prepared($sql, $params = []) { return 0; }
function db_fetch_row_prepared($sql, $params = []) {
	return ['import_lines' => $GLOBALS['entries'], 'start_time' => '2024-01-01 00:00:00', 'end_time' => '2024-01-01 00:00:00'];
}
function db_fetch_assoc_prepared($sql, $params = []) {
	if (strpos($sql, 'IS NULL') !== false || strpos($sql, 'SELECT 1') !== false) {
		return [];
	}
	$key = strpos($sql, 'sldm.id') !== false ? 'id' : 'tableid';
	$last = (int) ($params[1] ?? 0);
	$rows = [];
	for ($id = $last + 1; $id <= min($GLOBALS['entries'], $last + 5000); $id++) {
		$rows[] = [$key => $id, 'scope_key' => 'group_' . ($id % $GLOBALS['groups']),
			'query_time' => ($id % 1000) / 100, 'rows_sent' => $id % 10,
			'rows_examined' => $id % 100, 'rows_affected' => $id % 5, 'bytes_sent' => $id % 10000];
	}
	return $rows;
}

require $root . '/includes/functions.php';
mt_srand(12345);
$file = null;
if ($case === 'ingest') {
	$file = tempnam(sys_get_temp_dir(), 'slowlog-profile-');
	$handle = fopen($file, 'w');
	for ($id = 0; $id < $entries; $id++) {
		fwrite($handle, "# Time: 240101  0:00:00\n# User@Host: test[test] @ host [127.0.0.1]\n# Query_time: 1.25  Lock_time: 0.01  Rows_sent: 2  Rows_examined: 10\nSET timestamp=1704067200;\nSELECT * FROM profile_table WHERE id = $id;\n");
	}
	fclose($handle);
}
$start = hrtime(true);
if ($case === 'ingest') {
	import_logfile($file, 'profile', -1, '', false, true);
} elseif ($case === 'tables') {
	for ($id = 0; $id < $entries; $id++) {
		slowlog_extract_tables_from_query("SELECT a.id FROM accounts a LEFT JOIN orders o ON o.account_id = a.id WHERE a.note = 'FROM ignored' /* JOIN hidden */");
	}
} else {
	slowlog_compute_stats(1);
}
$elapsed = (hrtime(true) - $start) / 1e9;
if ($file !== null) {
	unlink($file);
}
print json_encode(['case' => $case, 'entries' => $entries, 'groups' => $groups,
	'seconds' => $elapsed, 'peak_bytes' => memory_get_peak_usage(true),
	'php' => PHP_VERSION, 'database' => 'stubbed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
