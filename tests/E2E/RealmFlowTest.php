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
 * The upgrade to 2.8 registers the management realm and grants it to every user and group that holds the
 * viewing realm, once. Needs SLOWLOG_E2E=1 and an installed Cacti with the plugin enabled; the CI integration job
 * provides both. Without them these tests are skipped.
 */

require_once __DIR__ . '/e2e_common.php';

describe('management realm upgrade against a real Cacti', function () {
	beforeEach(function () {
		if (($reason = slowlog_e2e_skip_reason()) !== null) {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		expect(slowlog_e2e_observe('realm_flow.php')['driver_failed'] ?? '')->toBe('');
	});

	it('starts from an install with no management realm and no grants', function () {
		$o = slowlog_e2e_observe('realm_flow.php')['before'];

		expect($o['registered'])->toBeFalse();
		expect($o['grants'])->toBe(['viewer' => false, 'grouped' => false, 'other' => false]);
	});

	it('registers the realm and grants it to the users and groups that hold the viewing realm, and only to them', function () {
		$o = slowlog_e2e_observe('realm_flow.php');

		expect($o['after_upgrade']['registered'])->toBeTrue();
		expect($o['after_upgrade']['grants'])->toBe(['viewer' => true, 'grouped' => true, 'other' => false]);
		expect($o['after_upgrade']['version'])->toBe('2.8');
		expect($o['admin_has_it'])->toBeTrue();
	});

	it('does not undo a narrowed grant at the current version', function () {
		expect(slowlog_e2e_observe('realm_flow.php')['after_narrowing'])->toBe(['viewer' => false, 'grouped' => true, 'other' => false]);
	});

	it('grants again only on a run from an older version', function () {
		expect(slowlog_e2e_observe('realm_flow.php')['rerun_from_27'])->toBe(['viewer' => true, 'grouped' => true, 'other' => false]);
	});
});
