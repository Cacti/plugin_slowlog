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
 * End-to-end coverage for import_logfile() against a real (temp file) slow
 * query log, run with $batch = false so post-processing happens inline
 * instead of shelling out to import_log.php. The bulk INSERT into
 * plugin_slowlog_details is built as raw SQL (see PreparedStatementUsageTest
 * for why that's accepted), so this also verifies db_qstr()-based escaping
 * of attacker-controlled user/host/query content is safe.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_details_insert_sql')) {
	function slowlog_test_details_insert_sql(array $calls): string {
		foreach ($calls as $call) {
			if ($call['fn'] === 'db_execute' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details (') !== false) {
				return $call['sql'];
			}
		}

		return '';
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	// Avoids the log-with-zero-rows $start warning path; not what this test covers.
	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 2);

	$this->logfile = tempnam(sys_get_temp_dir(), 'slowlog_test_');

	file_put_contents($this->logfile, implode("\n", array(
		'# Time: 240115  1:02:03',
		'# User@Host: root[root] @ dbhost-new [10.0.0.5]',
		'# Query_time: 1.234567  Lock_time: 0.000456  Rows_sent: 3  Rows_examined: 120',
		'SET timestamp=1705280523;',
		'SELECT * FROM users WHERE id = 1;',
		"# Time: 240115  1:03:10",
		"# User@Host: o'brien[o'brien] @ webhost [10.0.0.6]",
		'# Thread_id: 42  Schema: cacti  QC_hit: No',
		'# Query_time: 2.000000  Lock_time: 0.000100  Rows_sent: 1  Rows_examined: 5',
		'# Rows_affected: 1  Bytes_sent: 512',
		'# administrator command: Prepare;',
		'SET timestamp=1705280590;',
		'UPDATE accounts',
		"SET name = 'o''brien'",
		'WHERE id = 2;',
		'',
	)));
});

afterEach(function () {
	@unlink($this->logfile);
});

it('parses date, user, host, and ip for each entry', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_details_insert_sql($GLOBALS['__test_db_calls']);

	expect($sql)->toContain("'" . date('Y-m-d H:i:s', 1705280523) . "', 'root', 'dbhost', '10.0.0.5'");
	expect($sql)->toContain("'" . date('Y-m-d H:i:s', 1705280590) . "', 'o\\'brien', 'webhost', '10.0.0.6'");
});

it('parses query_time, lock_time, rows_sent, and rows_examined', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_details_insert_sql($GLOBALS['__test_db_calls']);

	expect($sql)->toContain('1.234567, 0.000456');
	expect($sql)->toContain('0, 3, 120');
});

it('parses thread_id, schema, qc_hit, rows_affected, and bytes_sent when present', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_details_insert_sql($GLOBALS['__test_db_calls']);

	expect($sql)->toContain("42, 'cacti', 0, 1, 5, 1, 512");
});

it('joins a multi-line query onto one line while preserving the original in oquery', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_details_insert_sql($GLOBALS['__test_db_calls']);

	expect($sql)->toContain("UPDATE accounts SET name = \\'o\\'\\'brien\\' WHERE id = 2;");
});

it('drops administrator command lines from the captured query text', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_details_insert_sql($GLOBALS['__test_db_calls']);

	expect($sql)->not->toContain('administrator command');
});

it('escapes a single quote in the username so it cannot break out of the SQL statement', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_details_insert_sql($GLOBALS['__test_db_calls']);

	// A raw, unescaped quote here would end the 'user' literal early and corrupt the statement.
	expect($sql)->toContain("'o\\'brien'");
	expect($sql)->not->toContain("'o'brien'");
});
