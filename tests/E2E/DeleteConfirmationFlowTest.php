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
 * The delete confirmation walked over HTTP against an installed Cacti: sign in, select two
 * imported logs, open the confirmation page, then confirm. The driver starts PHP's built-in
 * web server on the Cacti tree. Needs SLOWLOG_E2E=1 and an installed Cacti with the plugin
 * enabled and the default admin account; the CI integration job provides both. Without them
 * these tests are skipped.
 */

require_once __DIR__ . '/e2e_common.php';

describe('delete confirmation against a real Cacti', function () {
	beforeEach(function () {
		if (($reason = slowlog_e2e_skip_reason()) !== null) {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		// Comparing the text, not the key, puts the driver's output in the failure.
		expect(slowlog_e2e_observe('delete_flow.php')['driver_failed'] ?? '')->toBe('');
	});

	it('lists the imported logs and shows the selected ones on the confirmation page', function () {
		$o = slowlog_e2e_observe('delete_flow.php');

		expect($o['list_shows_logs'])->toBeTrue();
		expect($o['confirm_lists_logs'])->toBeTrue();
	});

	it('offers Yes as the only submit control and No as a plain button', function () {
		$o = slowlog_e2e_observe('delete_flow.php');

		expect($o['confirm_yes_is_submit'])->toBeTrue();
		expect($o['confirm_no_is_button'])->toBeTrue();
		expect($o['confirm_cancel_submit'])->toBeFalse();
		expect($o['confirm_submit_controls'])->toBe(1);
	});

	it('shows a Return button and no Yes when nothing is selected', function () {
		$o = slowlog_e2e_observe('delete_flow.php');

		expect($o['none_message'])->toBeTrue();
		expect($o['none_return_button'])->toBeTrue();
		expect($o['none_has_yes'])->toBeFalse();
	});

	it('deletes nothing until Yes is submitted', function () {
		expect(slowlog_e2e_observe('delete_flow.php')['kept_after_confirm_page'])->toBeTrue();
	});

	it('deletes nothing for an unknown action, a non-numeric selection, a plain GET, a missing token or an empty selection', function () {
		$o = slowlog_e2e_observe('delete_flow.php');

		expect($o['kept_after_rejects'])->toBeTrue();
		expect($o['kept_without_token'])->toBeTrue();
		expect($o['kept_after_empty_selection'])->toBeTrue();
	});

	it('deletes exactly the confirmed logs after Yes', function () {
		$o = slowlog_e2e_observe('delete_flow.php');

		expect($o['deleted_after_yes'])->toBeTrue();
		expect($o['others_kept'])->toBeTrue();
	});

	it('deletes on a direct form post that carries a valid token', function () {
		expect(slowlog_e2e_observe('delete_flow.php')['direct_post_with_token_deletes'])->toBeTrue();
	});

	it('does not serve the page, or delete, once the realm is revoked', function () {
		$o = slowlog_e2e_observe('delete_flow.php');

		expect($o['denied_without_realm'])->toBeTrue();
		expect($o['denied_text'])->toMatch('/Permission Denied|cactiRedirect|Access Denied/');
		expect($o['kept_when_denied'])->toBeTrue();
	});
});
