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

$slowlog_e2e_root  = dirname(__DIR__, 4);
$slowlog_e2e_ready = getenv('SLOWLOG_E2E') === '1' && is_readable($slowlog_e2e_root . '/include/config.php');

/** @return array<string, mixed> */
function slowlog_e2e_delete_observations(string $root): array {
	static $cache = null;

	if ($cache === null) {
		$output = [];
		$status = 0;
		$tmp    = sys_get_temp_dir() . '/slowlog-e2e-' . bin2hex(random_bytes(6));

		mkdir($tmp, 0700);
		putenv('SLOWLOG_E2E_TMP=' . $tmp);

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/slowlog/tests/E2E/delete_flow.php') . ' 2>&1', $output, $status);

		putenv('SLOWLOG_E2E_TMP');
		array_map('unlink', glob($tmp . '/*') ?: []);
		@rmdir($tmp);

		$json  = json_decode((string) end($output), true);
		$cache = is_array($json) ? $json : ['driver_failed' => implode("\n", $output)];
	}

	return $cache;
}

describe('delete confirmation against a real Cacti', function () use ($slowlog_e2e_root, $slowlog_e2e_ready) {
	beforeEach(function () use ($slowlog_e2e_ready) {
		if (!$slowlog_e2e_ready) {
			$this->markTestSkipped('Set SLOWLOG_E2E=1 with an installed Cacti and database to run.');
		}
	});

	it('runs the driver to completion', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_delete_observations($slowlog_e2e_root);

		expect($o)->not->toHaveKey('driver_failed');
		expect($o['server_up'])->toBeTrue();
	});

	it('lists the imported logs for a user who holds the Slowlog realm', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_delete_observations($slowlog_e2e_root)['list_shows_logs'])->toBeTrue();
	});

	it('shows the selected logs on the confirmation page', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_delete_observations($slowlog_e2e_root)['confirm_lists_logs'])->toBeTrue();
	});

	it('offers Yes as the only submit control and No as a plain button', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_delete_observations($slowlog_e2e_root);

		expect($o['confirm_yes_is_submit'])->toBeTrue();
		expect($o['confirm_no_is_button'])->toBeTrue();
		expect($o['confirm_cancel_submit'])->toBeFalse();
		expect($o['confirm_submit_controls'])->toBe(1);
	});

	it('deletes nothing until Yes is submitted', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_delete_observations($slowlog_e2e_root)['kept_after_confirm_page'])->toBeTrue();
	});

	it('deletes the selected logs after Yes', function () use ($slowlog_e2e_root) {
		expect(slowlog_e2e_delete_observations($slowlog_e2e_root)['deleted_after_yes'])->toBeTrue();
	});

	it('does not serve the page once the realm is revoked', function () use ($slowlog_e2e_root) {
		$o = slowlog_e2e_delete_observations($slowlog_e2e_root);

		expect($o['denied_without_realm'])->toBeTrue();
		expect($o['denied_text'])->toContain('Permission Denied');
	});
});
