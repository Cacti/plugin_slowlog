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
 * Driver for RealmFlowTest. Takes a 2.7 install (no management realm, version 2.7) through the upgrade to 2.8
 * on an installed Cacti and its database, and checks who is granted the management realm. The upgrade runs
 * in a scratch copy of the plugin directory, as in upgrade_flow.php. Prints one JSON object on the last line of
 * output.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_boot.php';

$view    = slowlog_e2e_realm();
$users   = [
	'viewer'  => slowlog_e2e_user('e2e_realm_viewer', [$view]),
	'grouped' => slowlog_e2e_user('e2e_realm_grouped', [], [$view]),
	'other'   => slowlog_e2e_user('e2e_realm_other', [7]),
];
$scratch = $tmp . '/upgrade-tree';

/**
 * @return array<string, bool> Whether each user holds the management realm directly or through a group.
 */
function e2e_manage_grants(array $users): array {
	$manage = slowlog_e2e_manage_realm();
	$held   = [];

	foreach ($users as $key => $user) {
		$held[$key] = (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', [$user['id'], $manage])
			|| (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_group_realm gr INNER JOIN user_auth_group_members gm ON gm.group_id = gr.group_id WHERE gm.user_id = ? AND gr.realm_id = ?', [$user['id'], $manage]);
	}

	return $held;
}

/**
 * Runs the version-change check against the scratch copy of the plugin directory.
 */
function e2e_upgrade(string $scratch, string $version): void {
	global $config;

	$real_base = $config['base_path'];

	db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$version, 'slowlog']);

	$_SERVER['PHP_SELF'] = '/cacti/plugins.php';
	$config['base_path'] = $scratch;

	try {
		slowlog_check_upgrade();
	} finally {
		$config['base_path'] = $real_base;
	}
}

$real_version = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']);
$real_realm   = db_fetch_row_prepared('SELECT id, display FROM plugin_realms WHERE plugin = ? AND file = ?', ['slowlog', 'slowlog_manage.php']);

try {
	slowlog_e2e_copy_tree($plugin, $scratch . '/plugins/slowlog');
	file_put_contents($scratch . '/plugins/slowlog/includes/database.php', "<?php\n");

	// a 2.7 install: no management realm, and nobody holds it
	db_execute_prepared('DELETE FROM user_auth_realm WHERE realm_id = ?', [(int) $real_realm['id'] + 100]);
	db_execute_prepared('DELETE FROM user_auth_group_realm WHERE realm_id = ?', [(int) $real_realm['id'] + 100]);
	db_execute_prepared('DELETE FROM plugin_realms WHERE plugin = ? AND file = ?', ['slowlog', 'slowlog_manage.php']);

	$out['before'] = ['registered' => slowlog_e2e_manage_realm() > 0, 'grants' => e2e_manage_grants($users)];

	e2e_upgrade($scratch, '2.7');

	$out['after_upgrade'] = ['registered' => slowlog_e2e_manage_realm() > 0, 'grants' => e2e_manage_grants($users), 'version' => db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog'])];
	$out['admin_has_it']  = (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = 1 AND realm_id = ?', [slowlog_e2e_manage_realm()]);

	// an administrator narrows the grant; a later check at the current version must not undo that
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', [$users['viewer']['id'], slowlog_e2e_manage_realm()]);

	e2e_upgrade($scratch, db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', ['slowlog']));

	$out['after_narrowing'] = e2e_manage_grants($users);

	// running the upgrade from 2.7 again only adds back what the viewing realm implies
	e2e_upgrade($scratch, '2.7');

	$out['rerun_from_27'] = e2e_manage_grants($users);
} finally {
	db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$real_version, 'slowlog']);

	slowlog_e2e_drop_users($users);
	slowlog_e2e_restore_log_setting($saved_log);
	slowlog_e2e_remove_tree($scratch);
}

print json_encode($out) . PHP_EOL;
