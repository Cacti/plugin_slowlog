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
 * End-to-end import of a sample slow log through import_log.php into the plugin tables of an
 * installed Cacti, plus the version upgrade of an old schema. The driver runs in its own
 * process because it needs the real Cacti and database, not the stubs the unit tests use.
 * Needs SLOWLOG_E2E=1 and an installed Cacti with the plugin enabled; the CI integration job
 * provides both. Without them these tests are skipped.
 */

require_once __DIR__ . '/e2e_common.php';

describe('slow log import against a real Cacti', function () {
	beforeEach(function () {
		if (($reason = slowlog_e2e_skip_reason()) !== null) {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		// Comparing the text, not the key, puts the driver's output in the failure.
		expect(slowlog_e2e_observe('import_flow.php')['driver_failed'] ?? '')->toBe('');
	});

	it('imports the sample log and marks it complete', function () {
		$o = slowlog_e2e_observe('import_flow.php');

		expect($o['main_status'])->toBe(0);
		expect((int) $o['log']['import_status'])->toBe(2);
		expect((int) $o['log']['import_lines'])->toBe(3);
		expect($o['rows'])->toHaveCount(3);
	});

	it('stores a user name longer than 20 characters in full', function () {
		expect(slowlog_e2e_observe('import_flow.php')['rows'][0]['user'])->toBe('cacti_poller_with_a_long_user_name');
	});

	it('stores the client address without the MySQL Id suffix, and keeps IPv6', function () {
		$rows = slowlog_e2e_observe('import_flow.php')['rows'];

		expect($rows[0]['ip_address'])->toBe('192.168.100.100');
		expect($rows[1]['ip_address'])->toBe('::1');
		expect($rows[2]['ip_address'])->toBe('10.0.0.9');
	});

	it('keeps the query that contains SET timestamp= as its own entry', function () {
		$rows = slowlog_e2e_observe('import_flow.php')['rows'];

		expect($rows[1]['query'])->toContain('UPDATE settings SET timestamp=NOW()');
	});

	it('stores zero for a non-numeric header field', function () {
		expect((int) slowlog_e2e_observe('import_flow.php')['rows'][2]['rows_sent'])->toBe(0);
	});

	it('finds the table after a trailing comment, and a long table name in full', function () {
		$o = slowlog_e2e_observe('import_flow.php');

		expect($o['tables'])->toContain('graph_local');
		expect($o['tables'])->toContain('rrd_archive_snapshot_extended_history_table_for_capacity_report');
		expect($o['stat_keys'])->toContain('rrd_archive_snapshot_extended_history_table_for_capacity_report');
	});

	it('classifies the methods of the sample queries', function () {
		expect(slowlog_e2e_observe('import_flow.php')['methods'])->toBe(['JOINS', 'SELECTS', 'UPDATES']);
	});

	it('marks an import failed when the detail rows cannot be inserted', function () {
		$o = slowlog_e2e_observe('import_flow.php');

		expect((int) $o['bad_log']['import_status'])->toBe(3);
		expect($o['bad_log']['import_text_status'])->toBe('Import Failed - Row Insert Error');
		expect($o['bad_rows'])->toBe(0);
	});

	it('cuts a long query on a character boundary', function () {
		$o = slowlog_e2e_observe('import_flow.php');

		expect((int) $o['multi_log']['import_status'])->toBe(2);
		expect(mb_check_encoding($o['multi_query'], 'UTF-8'))->toBeTrue();
		expect(strlen($o['multi_query']))->toBeLessThanOrEqual(21);
	});

	it('widens the old column widths and records version 2.7 on upgrade', function () {
		$o = slowlog_e2e_observe('import_flow.php');

		expect($o['upgrade_version'])->toBe('2.7');
		expect($o['upgrade_widths'])->toBe([
			'plugin_slowlog_details.user'            => 128,
			'plugin_slowlog_details.ip_address'      => 45,
			'plugin_slowlog.import_text_status'      => 128,
			'plugin_slowlog_details_tables.table_name' => 64,
			'plugin_slowlog_table_names.table_name'  => 64,
			'plugin_slowlog_stats.scope_key'         => 64,
		]);
	});
});
