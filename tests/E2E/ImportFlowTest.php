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

$slowlog_e2e_root  = dirname(__DIR__, 4);
$slowlog_e2e_ready = getenv('SLOWLOG_E2E') === '1' && is_readable($slowlog_e2e_root . '/include/config.php');

/** @return array<string, mixed> */
function slowlog_e2e_import_observations(string $root): array {
	static $cache = null;

	if ($cache === null) {
		$output = [];
		$status = 0;
		$tmp    = sys_get_temp_dir() . '/slowlog-e2e-' . bin2hex(random_bytes(6));

		mkdir($tmp, 0700);
		putenv('SLOWLOG_E2E_TMP=' . $tmp);

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/slowlog/tests/E2E/import_flow.php') . ' 2>&1', $output, $status);

		putenv('SLOWLOG_E2E_TMP');
		array_map('unlink', glob($tmp . '/*') ?: []);
		@rmdir($tmp);

		$json  = json_decode((string) end($output), true);
		$cache = is_array($json) ? $json : ['driver_failed' => implode("\n", $output)];
	}

	return $cache;
}

describe('slow log import against a real Cacti', function () use ($slowlog_e2e_root, $slowlog_e2e_ready) {
	beforeEach(function () use ($slowlog_e2e_ready) {
		if (!$slowlog_e2e_ready) {
			$this->markTestSkipped('Set SLOWLOG_E2E=1 with an installed Cacti and database to run.');
		}
	});

	it('runs the driver to completion', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_import_observations($slowlog_e2e_root))->not->toHaveKey('driver_failed');
	});

	it('imports the sample log and marks it complete', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_import_observations($slowlog_e2e_root);

		expect($o['main_status'])->toBe(0);
		expect((int) $o['log']['import_status'])->toBe(2);
		expect((int) $o['log']['import_lines'])->toBe(3);
		expect($o['rows'])->toHaveCount(3);
	});

	it('stores a user name longer than 20 characters in full', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_import_observations($slowlog_e2e_root)['rows'][0]['user'])->toBe('cacti_poller_with_a_long_user_name');
	});

	it('stores the client address without the MySQL Id suffix, and keeps IPv6', function () use ($slowlog_e2e_root) {
		$rows = slowlog_e2e_import_observations($slowlog_e2e_root)['rows'];

		expect($rows[0]['ip_address'])->toBe('192.168.100.100');
		expect($rows[1]['ip_address'])->toBe('::1');
		expect($rows[2]['ip_address'])->toBe('10.0.0.9');
	});

	it('keeps the query that contains SET timestamp= as its own entry', function () use ($slowlog_e2e_root) {
		$rows = slowlog_e2e_import_observations($slowlog_e2e_root)['rows'];

		expect($rows[1]['query'])->toContain('UPDATE settings SET timestamp=NOW()');
	});

	it('stores zero for a non-numeric header field', function () use ($slowlog_e2e_root) {
		expect((int) slowlog_e2e_import_observations($slowlog_e2e_root)['rows'][2]['rows_sent'])->toBe(0);
	});

	it('finds the table after a trailing comment, and a long table name in full', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_import_observations($slowlog_e2e_root);

		expect($o['tables'])->toContain('graph_local');
		expect($o['tables'])->toContain('rrd_archive_snapshot_extended_history_table_for_capacity_report');
		expect($o['stat_keys'])->toContain('rrd_archive_snapshot_extended_history_table_for_capacity_report');
	});

	it('classifies the methods of the sample queries', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_import_observations($slowlog_e2e_root)['methods'])->toBe(['JOINS', 'SELECTS', 'UPDATES']);
	});

	it('marks an import failed when the detail rows cannot be inserted', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_import_observations($slowlog_e2e_root);

		expect((int) $o['bad_log']['import_status'])->toBe(3);
		expect($o['bad_log']['import_text_status'])->toBe('Import Failed - Row Insert Error');
		expect($o['bad_rows'])->toBe(0);
	});

	it('cuts a long query on a character boundary', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_import_observations($slowlog_e2e_root);

		expect((int) $o['multi_log']['import_status'])->toBe(2);
		expect(mb_check_encoding($o['multi_query'], 'UTF-8'))->toBeTrue();
		expect(strlen($o['multi_query']))->toBeLessThanOrEqual(21);
	});

	it('widens the old column widths and records version 2.7 on upgrade', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_import_observations($slowlog_e2e_root);

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
