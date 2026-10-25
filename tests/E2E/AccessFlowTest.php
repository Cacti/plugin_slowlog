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
 * The Slowlog page, a delete request and the Slowlog tab for a user granted the realm directly, a user granted
 * it through a group and a user without it, and after the grants are revoked. Needs SLOWLOG_E2E=1 and an
 * installed Cacti with the plugin enabled; the CI integration job provides both. Without them these tests are
 * skipped.
 */

require_once __DIR__ . '/e2e_common.php';

describe('access to Slowlog against a real Cacti', function () {
	beforeEach(function () {
		if (($reason = slowlog_e2e_skip_reason()) !== null) {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		expect(slowlog_e2e_observe('access_flow.php')['driver_failed'] ?? '')->toBe('');
	});

	it('serves the page to users who hold the realm directly or through a group', function ($user) {
		$o = slowlog_e2e_observe('access_flow.php')['page'][$user];

		expect($o['shows_page'])->toBeTrue();
		expect($o['denied'])->toBeFalse();
	})->with(['direct', 'group']);

	it('denies the page to a user without the realm, and a delete request from that user does nothing', function () {
		$o = slowlog_e2e_observe('access_flow.php');

		expect($o['page']['none']['shows_page'])->toBeFalse();
		expect($o['page']['none']['denied'])->toBeTrue();
		expect($o['kept_after_post'])->toBeTrue();
	});

	it('shows the tab to users who hold the realm directly or through a group, from a fresh or a cached session', function ($user) {
		$o = slowlog_e2e_observe('access_flow.php')['tab'][$user];

		expect($o['first'])->toBeTrue();
		expect($o['cached'])->toBeTrue();
	})->with(['direct', 'group']);

	it('hides the tab from a user without the realm', function () {
		$o = slowlog_e2e_observe('access_flow.php')['tab']['none'];

		expect($o['first'] || $o['cached'])->toBeFalse();
	});

	it('uses the pressed tab image on a Slowlog page, and shows no tab when the realm is not registered', function () {
		$o = slowlog_e2e_observe('access_flow.php');

		expect($o['tab_down'])->toBeTrue();
		expect($o['tab_unregistered'])->toBe('');
	});

	it('denies the page and hides the tab once a direct or group grant is revoked', function ($user) {
		$o = slowlog_e2e_observe('access_flow.php');

		expect($o['revoked_page'][$user]['shows_page'])->toBeFalse();
		expect($o['revoked_page'][$user]['denied'])->toBeTrue();
		expect($o['revoked_tab'][$user])->toBeTrue();
	})->with(['direct', 'group']);
});
