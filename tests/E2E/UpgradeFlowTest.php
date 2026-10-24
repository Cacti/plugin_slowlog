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
 * The version-change upgrade of an old schema on an installed Cacti. The driver runs in its own
 * process because it needs the real Cacti and database, not the stubs the unit tests use.
 * Needs SLOWLOG_E2E=1 and an installed Cacti with the plugin enabled; the CI integration job
 * provides both. Without them these tests are skipped.
 */

require_once __DIR__ . '/e2e_common.php';

describe('version upgrade against a real Cacti', function () {
	beforeEach(function () {
		if (($reason = slowlog_e2e_skip_reason()) !== null) {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		expect(slowlog_e2e_observe('upgrade_flow.php')['driver_failed'] ?? '')->toBe('');
	});

	it('widens the old column widths and records version 2.7', function () {
		$o = slowlog_e2e_observe('upgrade_flow.php');

		expect($o['version'])->toBe('2.7');
		expect($o['widths'])->toBe([
			'plugin_slowlog_details.user'              => 128,
			'plugin_slowlog_details.ip_address'        => 45,
			'plugin_slowlog_details.schema'            => 64,
			'plugin_slowlog.import_text_status'        => 128,
			'plugin_slowlog_details_tables.table_name' => 64,
			'plugin_slowlog_table_names.table_name'    => 64,
			'plugin_slowlog_stats.scope_key'           => 64,
		]);
	});

	it('prunes the dev-only files from the scratch copy it upgrades, never from the real plugin directory', function () {
		$o = slowlog_e2e_observe('upgrade_flow.php');

		expect($o['tests_pruned'])->toBeTrue();
		expect($o['real_intact'])->toBeTrue();
	});
});
