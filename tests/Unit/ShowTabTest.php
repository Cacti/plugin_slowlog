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
 * slowlog_show_tab() decides whether the current user sees the Slowlog tab. It used to read
 * user_auth_realm directly, which misses realms granted through a user group, and cached the
 * answer in the session so a changed grant did not apply until the next login. It now asks
 * is_realm_allowed() every time and caches only the realm id.
 */

uses(TestCase::class);

beforeEach(function () {
	require_once __DIR__ . '/../../setup.php';

	$_SESSION = array();
	$_SERVER['REQUEST_URI'] = '/cacti/graph_view.php';

	$GLOBALS['__test_realm_allowed'] = true;
	$GLOBALS['__test_realm_checks']  = array();
});

afterEach(function () {
	unset($GLOBALS['__test_realm_allowed']);
});

function slowlog_test_tab_output(): string {
	ob_start();
	slowlog_show_tab();

	return (string) ob_get_clean();
}

it('shows the tab when Cacti reports the realm as allowed', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', 12);

	$output = slowlog_test_tab_output();

	expect($output)->toContain('plugins/slowlog/slowlog.php');
	expect($output)->toContain('tab_slowlog.gif');
	expect($GLOBALS['__test_realm_checks'])->toBe(array(112));
});

it('uses the pressed tab image on a slowlog page', function () {
	$_SERVER['REQUEST_URI'] = '/cacti/plugins/slowlog/slowlog.php';
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', 12);

	expect(slowlog_test_tab_output())->toContain('tab_slowlog_down.gif');
});

it('hides the tab when the realm is not allowed', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', 12);
	$GLOBALS['__test_realm_allowed'] = false;

	expect(slowlog_test_tab_output())->toBe('');
});

it('applies a changed grant without a new session', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', 12);

	expect(slowlog_test_tab_output())->not->toBe('');

	$GLOBALS['__test_realm_allowed'] = false;

	expect(slowlog_test_tab_output())->toBe('');
});

it('looks the realm up once per session, by plugin and file', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', 12);

	slowlog_test_tab_output();
	slowlog_test_tab_output();

	$lookups = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_fetch_cell_prepared' && strpos($call['sql'], 'plugin_realms') !== false;
	}));

	expect($lookups)->toHaveCount(1);
	expect($lookups[0]['params'])->toBe(array('slowlog', 'slowlog.php'));
});

it('does not read user_auth_realm, so a group grant is not missed', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', 12);

	slowlog_test_tab_output();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		expect($call['sql'])->not->toContain('user_auth_realm');
	}
});

it('shows nothing when the realm is not registered', function () {
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_realms', false);

	expect(slowlog_test_tab_output())->toBe('');
	expect($GLOBALS['__test_realm_checks'])->toBe(array());
	expect($_SESSION)->not->toHaveKey('sess_slowlog_realm');
});
