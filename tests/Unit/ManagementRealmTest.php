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
 * Importing and deleting logs need the management realm in addition to the realm that allows viewing.
 * The upgrade to 2.8 registers it and grants it to every user and group that already holds the viewing
 * realm, once, so nothing changes for anyone until an administrator narrows it.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
	require_once __DIR__ . '/../../setup.php';

	$GLOBALS['__test_realm_allowed'] = true;
	$GLOBALS['__test_realm_checks']  = array();
});

afterEach(function () {
	unset($GLOBALS['__test_realm_allowed']);
});

/**
 * Registers fixtures for the two realm id lookups.
 */
function slowlog_test_realm_ids(array $ids): void {
	foreach ($ids as $file => $id) {
		slowlog_test_mock_db('db_fetch_cell_prepared', function ($sql, $params) use ($file) {
			return strpos($sql, 'plugin_realms') !== false && ($params[1] ?? '') === $file;
		}, $id);
	}
}

it('numbers the management realm as Cacti does, and reports 0 when it is not registered', function () {
	slowlog_test_realm_ids(array('slowlog_manage.php' => 3));

	expect(slowlog_manage_realm())->toBe(103);

	slowlog_test_reset_db_mocks();
	slowlog_test_realm_ids(array('slowlog_manage.php' => false));

	expect(slowlog_manage_realm())->toBe(0);
});

it('lets a user manage only when the management realm is allowed', function ($allowed, $expected) {
	slowlog_test_realm_ids(array('slowlog_manage.php' => 3));
	$GLOBALS['__test_realm_allowed'] = $allowed;

	expect(slowlog_can_manage())->toBe($expected);
	expect($GLOBALS['__test_realm_checks'])->toBe(array(103));
})->with(array('allowed' => array(true, true), 'denied' => array(false, false)));

it('denies management without asking Cacti when the realm is not registered', function () {
	slowlog_test_realm_ids(array('slowlog_manage.php' => false));

	expect(slowlog_can_manage())->toBeFalse();
	expect($GLOBALS['__test_realm_checks'])->toBe(array());
});

it('registers both realms on install', function () {
	$GLOBALS['__test_registered_realms'] = array();

	plugin_slowlog_install();

	expect(array_column($GLOBALS['__test_registered_realms'], 'file'))->toBe(array('slowlog.php', 'slowlog_manage.php'));
});

it('grants the management realm to everyone who holds the viewing realm', function () {
	slowlog_test_realm_ids(array('slowlog.php' => 2, 'slowlog_manage.php' => 3));
	$GLOBALS['__test_registered_realms'] = array();

	expect(slowlog_upgrade_realms())->toBeTrue();
	expect(array_column($GLOBALS['__test_registered_realms'], 'file'))->toBe(array('slowlog_manage.php'));

	$grants = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT IGNORE INTO') === 0;
	}));

	expect($grants)->toHaveCount(2);
	expect($grants[0]['sql'])->toContain('user_auth_realm');
	expect($grants[1]['sql'])->toContain('user_auth_group_realm');
	expect($grants[0]['params'])->toBe(array(103, 102));
	expect($grants[1]['params'])->toBe(array(103, 102));
});

it('reports failure when a realm is missing or a grant fails', function ($ids, $failing) {
	slowlog_test_realm_ids($ids);

	if ($failing !== '') {
		slowlog_test_mock_db('db_execute_prepared', $failing, false);
	}

	expect(slowlog_upgrade_realms())->toBeFalse();
})->with(array(
	'no viewing realm'     => array(array('slowlog.php' => false, 'slowlog_manage.php' => 3), ''),
	'no management realm'  => array(array('slowlog.php' => 2, 'slowlog_manage.php' => false), ''),
	'user grant fails'     => array(array('slowlog.php' => 2, 'slowlog_manage.php' => 3), 'INSERT IGNORE INTO user_auth_realm'),
	'group grant fails'    => array(array('slowlog.php' => 2, 'slowlog_manage.php' => 3), 'INSERT IGNORE INTO user_auth_group_realm'),
));

it('requires the management realm for the import form, an upload and a delete, and hides the controls from viewers', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect(substr_count($source, 'slowlog_require_management();'))->toBe(3);
	expect($source)->toContain("\$can_manage ? 'slowlog.php?action=edit' : ''");
	expect($source)->toContain("if (\$can_manage) {\n\t\tdraw_actions_dropdown(\$actions);");
});
