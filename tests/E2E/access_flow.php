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
 * Driver for AccessFlowTest. Signs in as three local users (the realm granted to the user, granted
 * through a group, and not granted) and checks the Slowlog page, a delete request and the Slowlog tab
 * for each, then revokes the grants. Prints one JSON object on the last line of output.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_common.php';

$root = slowlog_e2e_enter(__FILE__);
$tmp  = slowlog_e2e_tmp();

require_once $root . '/include/cli_check.php';
require_once $root . '/plugins/slowlog/includes/functions.php';

$saved_log = slowlog_e2e_redirect_log($tmp);
$plugin    = $root . '/plugins/slowlog';
$tag       = 'e2e' . getmypid();
$out       = [];

$realm = slowlog_e2e_realm();
$users = [
	'direct' => slowlog_e2e_user('e2e_acc_direct', [$realm]),
	'group'  => slowlog_e2e_user('e2e_acc_group', [], [$realm]),
	'none'   => slowlog_e2e_user('e2e_acc_none', [7]),
];

db_execute_prepared('INSERT INTO plugin_slowlog (description, import_status, import_text_status) VALUES (?, 2, ?)', [$tag . '-keep', 'All Tables Processed']);

$logid = (int) db_fetch_insert_id();
$web   = slowlog_e2e_web_start($tmp);

/**
 * Renders the tab as the given user and returns what it printed.
 *
 * @param array<string, mixed> $session Session values to start from.
 */
function e2e_tab(int $user_id, array $session = []): string {
	$_SESSION               = array_merge(['sess_user_id' => $user_id], $session);
	$_SERVER['REQUEST_URI'] = '/cacti/graph_view.php';

	ob_start();
	slowlog_show_tab();

	return (string) ob_get_clean();
}

/**
 * Gives the user's open sessions a new permissions stamp, as the user admin page does after a grant changes.
 */
function e2e_reset_perms(int $user_id): void {
	db_execute_prepared('UPDATE user_auth SET reset_perms = ? WHERE id = ?', [random_int(1, 2000000000), $user_id]);
}

/**
 * @return array{code: int, shows_page: bool, denied: bool}
 */
function e2e_visit(string $path): array {
	global $web;

	$page = slowlog_e2e_request($web, 'GET', $path);

	return ['code' => $page['code'], 'shows_page' => strpos($page['body'], 'SlowLog File Filters') !== false, 'denied' => (bool) preg_match('/Permission Denied|cactiRedirect|Access Denied/', $page['body'])];
}

try {
	foreach ($users as $key => $user) {
		$web['jar'] = $tmp . '/jar-' . $key;

		slowlog_e2e_login($web, $user['name'], $user['password']);

		$out['page'][$key] = e2e_visit('plugins/slowlog/slowlog.php');

		if ($key === 'none') {
			// a delete request from the user without the realm, with a token from a page the user can open
			$token = slowlog_e2e_token(slowlog_e2e_request($web, 'GET', 'index.php')['body']);

			slowlog_e2e_request($web, 'POST', 'plugins/slowlog/slowlog.php', ['action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize([$logid]), '__csrf_magic' => $token]);

			$out['kept_after_post'] = (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog WHERE logid = ?', [$logid]);
		}

		// the tab: looked up on the first call, taken from the session on the second
		$out['tab'][$key] = [
			'first'  => strpos(e2e_tab($user['id']), 'plugins/slowlog/slowlog.php') !== false,
			'cached' => strpos(e2e_tab($user['id'], ['sess_slowlog_realm' => $realm]), 'tab_slowlog.gif') !== false,
		];
	}

	// the page a slowlog URL is on uses the pressed image
	$_SERVER['REQUEST_URI'] = '/cacti/plugins/slowlog/slowlog.php';
	$_SESSION               = ['sess_user_id' => $users['direct']['id']];

	ob_start();
	slowlog_show_tab();

	$out['tab_down'] = strpos((string) ob_get_clean(), 'tab_slowlog_down.gif') !== false;

	// the realm is not registered: no tab, and nothing is cached
	db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE plugin = ? AND file = ?', ['slowlog.php.off', 'slowlog', 'slowlog.php']);

	$out['tab_unregistered'] = e2e_tab($users['direct']['id']);

	db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE plugin = ? AND file = ?', ['slowlog.php', 'slowlog', 'slowlog.php.off']);

	// revoke the direct grant and the group grant
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', [$users['direct']['id'], $realm]);
	db_execute_prepared('DELETE FROM user_auth_group_realm WHERE group_id = (SELECT id FROM user_auth_group WHERE name = ?)', [$users['group']['name']]);

	foreach (['direct', 'group'] as $key) {
		e2e_reset_perms($users[$key]['id']);

		$web['jar'] = $tmp . '/jar-' . $key;

		$out['revoked_page'][$key] = e2e_visit('plugins/slowlog/slowlog.php');
		$out['revoked_tab'][$key]  = e2e_tab($users[$key]['id']) === '';
	}
} finally {
	slowlog_e2e_web_stop($web);
	api_slowlog_remove($logid);
	slowlog_e2e_drop_users($users);
	slowlog_e2e_restore_log_setting($saved_log);
}

print json_encode($out) . PHP_EOL;
