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

/**
 * @return array<string, mixed> What the import driver recorded for one scenario.
 */
function slowlog_e2e_scenario(string $name): array {
	return slowlog_e2e_observe('import_flow.php')['scenarios'][$name];
}

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
		$o = slowlog_e2e_scenario('main');

		expect($o['exit'])->toBe(0);
		expect((int) $o['log']['import_status'])->toBe(2);
		expect((int) $o['log']['import_lines'])->toBe(3);
		expect($o['count'])->toBe(3);
		expect($o['methods'])->toBe(['JOINS', 'SELECTS', 'UPDATES']);
	});

	it('stores the user, address and query of each entry of the sample log', function () {
		$rows = slowlog_e2e_scenario('main')['rows'];

		expect($rows[0]['user'])->toBe('cacti_poller_with_a_long_user_name');
		expect(array_column($rows, 'ip'))->toBe(['192.168.100.100', '::1', '10.0.0.9']);
		expect($rows[1]['query'])->toContain('UPDATE settings SET timestamp=NOW()');
		expect((int) $rows[2]['rows_sent'])->toBe(0);
	});

	it('finds the table after a trailing comment, and a long table name in full', function () {
		$o = slowlog_e2e_scenario('main');

		expect($o['tables'])->toContain('graph_local');
		expect($o['tables'])->toContain('rrd_archive_snapshot_extended_history_table_for_capacity_report');
		expect($o['keys'])->toContain('rrd_archive_snapshot_extended_history_table_for_capacity_report');
	});

	it('imports MySQL 8 entries: ISO time, Id suffix, IPv6 client, empty host, use line', function () {
		$o = slowlog_e2e_scenario('mysql8');

		expect((int) $o['log']['import_status'])->toBe(2);
		expect(array_column($o['rows'], 'ts'))->toEqual([1705280523, 1705280524]);
		expect(array_column($o['rows'], 'ip'))->toBe(['127.0.0.1', '2001:db8::17']);
		expect(array_column($o['rows'], 'host'))->toBe(['localhost', '']);
		expect($o['rows'][1]['query'])->toBe('use appdb; DELETE FROM old_tbl WHERE id < 5; ');
		expect($o['tables'])->toBe(['mysql_tbl', 'old_tbl']);
		expect($o['methods'])->toBe(['DELETES', 'SELECTS']);
	});

	it('imports MariaDB entries: thread, schema, query cache, affected rows, administrator command', function () {
		$o = slowlog_e2e_scenario('mariadb');

		expect(array_column($o['rows'], 'db_schema'))->toBe(['shop', 'shop']);
		expect(array_map('intval', array_column($o['rows'], 'bytes_sent')))->toBe([2048, 10]);
		expect($o['tables'])->toBe(['items', 'orders']);
		expect($o['methods'])->toBe(['JOINS', 'SELECTS', 'UPDATES']);
		expect(json_encode($o['rows']))->not->toContain('administrator');
	});

	it('keeps values that fill the new columns, and truncates what is longer', function () {
		$o    = slowlog_e2e_scenario('limits');
		$rows = $o['rows'];

		expect((int) $o['log']['import_status'])->toBe(2);
		expect([strlen($rows[0]['user']), strlen($rows[0]['ip']), strlen($rows[0]['db_schema'])])->toBe([128, 45, 64]);
		expect([strlen($rows[1]['user']), strlen($rows[1]['ip']), strlen($rows[1]['db_schema'])])->toBe([128, 45, 64]);
		expect(array_map('strlen', $o['tables']))->toBe([64, 64]);
		expect(array_map('strlen', $o['keys']))->toBe([64, 64]);
	});

	it('finds tables after -- and # comments and ignores text inside string literals', function () {
		$o = slowlog_e2e_scenario('comments');

		expect($o['tables'])->toBe(['a', 'b', 'c', 'd']);
		expect($o['rows'][0]['query'])->toContain('-- first comment JOIN b');
	});

	it('imports an entry that has no Time line of its own', function () {
		$o = slowlog_e2e_scenario('sametime');

		expect(array_column($o['rows'], 'user'))->toBe(['s', 's2']);
		expect(array_column($o['rows'], 'query'))->toBe(['SELECT 1 FROM t1; ', 'SELECT 2 FROM t2; ']);
	});

	it('reports a file with no entries without creating a record', function () {
		$o = slowlog_e2e_scenario('empty');

		expect($o['logid'])->toBe(0);
		expect($o['output'])->toContain('Bad File Format - No Slow Query Log Entries Found');
	});

	it('imports the entries before a cut-off last entry and a last query without a newline', function () {
		$o = slowlog_e2e_scenario('truncated');

		expect($o['count'])->toBe(2);
		expect(array_column($o['rows'], 'query'))->toBe(['SELECT 1 FROM t1; ', 'SELECT 2 FROM t2 ']);
	});

	it('marks a pre-created record bad or complete and deletes the staged file', function () {
		$o = slowlog_e2e_observe('import_flow.php')['upfront'];

		expect((int) $o['bad']['import_status'])->toBe(3);
		expect($o['bad']['import_text_status'])->toStartWith('Bad File Format');
		expect((int) $o['good']['import_status'])->toBe(2);
		expect((int) $o['good']['import_lines'])->toBe(1);
		expect($o['bad_gone'] && $o['good_gone'])->toBeTrue();
	});

	it('groups tables outside the list as OTHER TABLES in cacti and reference modes', function ($scenario) {
		expect(slowlog_e2e_scenario($scenario)['methods'])->toContain('OTHER TABLES');
	})->with(['mode_cacti', 'mode_usecacti', 'mode_reference_some']);

	it('does not group anything in the default and list modes, or when the reference list holds every table', function ($scenario) {
		expect(slowlog_e2e_scenario($scenario)['methods'])->not->toContain('OTHER TABLES');
	})->with(['main', 'mode_list', 'mode_reference_all']);

	it('scans only the tables of a whitespace separated list in list mode', function () {
		expect(slowlog_e2e_scenario('mode_list')['tables'])->toBe(['graph_local', 'host', 'settings']);
	});

	it('marks the import failed when the detail rows cannot be inserted', function ($scenario) {
		$o = slowlog_e2e_scenario($scenario);

		expect((int) $o['log']['import_status'])->toBe(3);
		expect($o['log']['import_text_status'])->toBe('Import Failed - Row Insert Error');
		expect($o['count'])->toBe(0);
	})->with(['fail_final', 'fail_batch']);

	it('post-processes a log larger than one chunk and one insert batch', function () {
		$o = slowlog_e2e_scenario('large');

		expect((int) $o['log']['import_status'])->toBe(2);
		expect($o['count'])->toBe(2100);
		expect($o['assoc'])->toBe(2800);
		expect($o['tables'])->toBe(['graph_local', 'host', 'settings']);
		expect(slowlog_e2e_observe('import_flow.php')['large_log_line'])->toBe(2800);
	});

	it('cuts a long query on a character boundary', function () {
		$o = slowlog_e2e_scenario('multibyte');

		expect((int) $o['log']['import_status'])->toBe(2);
		expect(mb_check_encoding($o['rows'][0]['query'], 'UTF-8'))->toBeTrue();
		expect(strlen($o['rows'][0]['query']))->toBeLessThanOrEqual(21);
	});

	it('reprocesses an imported log against a reference list', function () {
		$o = slowlog_e2e_observe('import_flow.php')['reprocess'];

		expect($o['exit'])->toBe(0);
		expect((int) $o['log']['import_status'])->toBe(2);
		expect($o['methods'])->toContain('OTHER TABLES');
	});
});
