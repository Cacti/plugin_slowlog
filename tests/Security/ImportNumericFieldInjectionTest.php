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
 * The numeric fields of a slow log header are concatenated into the bulk INSERT unquoted,
 * so a value such as a subquery must be reduced to a plain number before it reaches the
 * statement text.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 1);

	$this->logfile = tempnam(sys_get_temp_dir(), 'slowlog_test_');

	file_put_contents($this->logfile, implode("\n", array(
		'# Time: 240116  9:00:00',
		'# User@Host: root[root] @ webhost [10.0.0.9]',
		'# Thread_id: (SELECT/**/1)  Schema: cacti  QC_hit: No',
		'# Query_time: 0.5  Lock_time: 0.01  Rows_sent: (SELECT/**/1)  Rows_examined: 7',
		'# Rows_affected: 1  Bytes_sent: 1,(SELECT/**/1)',
		'SET timestamp=1705395600;',
		'SELECT 1;',
		'',
	)));
});

afterEach(function () {
	@unlink($this->logfile);
});

it('stores zero for a non-numeric header field instead of passing it into the INSERT', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = slowlog_test_last_details_insert();

	expect($sql)->not->toBe('');
	expect($sql)->not->toContain('SELECT/**/');
	// thread_id, schema, qc_hit, rows_sent, rows_examined, rows_affected, bytes_sent
	expect($sql)->toContain("0, 'cacti', 0, 0, 7, 1, 0, 'SELECT 1;");
});

it('keeps well-formed decimal and integer fields unchanged', function () {
	expect(slowlog_log_number('1.234567'))->toBe('1.234567');
	expect(slowlog_log_number('120'))->toBe('120');
	expect(slowlog_log_number('1e5'))->toBe('0');
	expect(slowlog_log_number('-1'))->toBe('0');
	expect(slowlog_log_number(''))->toBe('0');
});
