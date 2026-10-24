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
 * Driver for DeleteConfirmationFlowTest. Starts PHP's built-in web server on the installed Cacti, signs in
 * over HTTP as an administrator, and walks the delete confirmation: select logs, open the confirmation
 * page, confirm, and the requests that must not delete anything. Prints one JSON object on the last line
 * of output.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_boot.php';

$admin = slowlog_e2e_user('e2e_del_admin', [slowlog_e2e_realm()]);
$web   = slowlog_e2e_web_start($tmp);
$ids   = [];

foreach ([1, 2, 3, 4, 5] as $n) {
	db_execute_prepared('INSERT INTO plugin_slowlog (description, import_status, import_text_status) VALUES (?, 2, ?)', [$tag . '-' . $n, 'All Tables Processed']);
	$ids[$n] = (int) db_fetch_insert_id();
}

/**
 * @return bool Whether the log still exists.
 */
function e2e_exists(int $id): bool {
	return (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog WHERE logid = ?', [$id]);
}

/**
 * Posts to the Slowlog page.
 *
 * @param array<string, mixed> $fields The form fields.
 *
 * @return string The response body.
 */
function e2e_post(array $fields): string {
	global $web;

	return slowlog_e2e_request($web, 'POST', 'plugins/slowlog/slowlog.php', $fields)['body'];
}

try {
	slowlog_e2e_login($web, $admin['name'], $admin['password']);

	$list  = slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php')['body'];
	$token = slowlog_e2e_token($list);

	$out['list_shows_logs'] = strpos($list, $tag . '-1') !== false && strpos($list, $tag . '-2') !== false;

	// select two logs and ask for the delete action
	$confirm = e2e_post(['action' => 'actions', 'drp_action' => '1', 'chk_' . $ids[1] => $ids[1], 'chk_' . $ids[2] => $ids[2], '__csrf_magic' => $token]);

	$out['confirm_lists_logs']      = strpos($confirm, $tag . '-1') !== false && strpos($confirm, $tag . '-2') !== false;
	$out['confirm_yes_is_submit']   = (bool) preg_match("/<input type='submit' name='save' value='Yes'/", $confirm);
	$out['confirm_no_is_button']    = (bool) preg_match("/<input type='button'[^>]*cactiReturnTo[^>]*value='No'/", $confirm);
	$out['confirm_submit_controls'] = preg_match_all("/<input type='submit'/", $confirm);
	$out['confirm_cancel_submit']   = (bool) preg_match("/<input type='submit' name='cancel'/", $confirm);
	$out['kept_after_confirm_page'] = e2e_exists($ids[1]) && e2e_exists($ids[2]);

	// nothing selected: a page with a Return button and no Yes
	$none = e2e_post(['action' => 'actions', 'drp_action' => '1', '__csrf_magic' => $token]);

	$out['none_message']       = strpos($none, 'You must select at least one Slowlog record.') !== false;
	$out['none_return_button'] = (bool) preg_match("/<input type='button'[^>]*cactiReturnTo[^>]*value='Return'/", $none);
	$out['none_has_yes']       = strpos($none, "value='Yes'") !== false;

	// an action that does not exist, a non-numeric selection, and a plain GET delete nothing
	e2e_post(['action' => 'actions', 'drp_action' => '99', 'chk_' . $ids[3] => $ids[3], '__csrf_magic' => $token]);
	e2e_post(['action' => 'actions', 'drp_action' => '1', 'chk_abc' => 'abc', '__csrf_magic' => $token]);
	slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php?action=actions&drp_action=1&chk_' . $ids[3] . '=' . $ids[3]);

	$out['kept_after_rejects'] = e2e_exists($ids[3]);

	// Yes deletes exactly the confirmed logs
	preg_match("/name='selected_items' value='([^']*)'/", $confirm, $selected);

	e2e_post(['action' => 'actions', 'drp_action' => '1', 'selected_items' => html_entity_decode($selected[1] ?? ''), 'save' => 'Yes', '__csrf_magic' => slowlog_e2e_token($confirm)]);

	$out['deleted_after_yes'] = !e2e_exists($ids[1]) && !e2e_exists($ids[2]);
	$out['others_kept']       = e2e_exists($ids[3]) && e2e_exists($ids[4]);

	// the form post that Yes sends, made directly with a valid token, also deletes (the confirmation is a page, not a server state)
	e2e_post(['action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize([$ids[3]]), '__csrf_magic' => $token]);

	$out['direct_post_with_token_deletes'] = !e2e_exists($ids[3]);

	// an empty selection posted by Return deletes nothing
	e2e_post(['action' => 'actions', 'drp_action' => '1', 'selected_items' => '', '__csrf_magic' => $token]);

	$out['kept_after_empty_selection'] = e2e_exists($ids[4]) && e2e_exists($ids[5]);

	// a delete request without the CSRF token is refused; this comes late because some Cacti versions
	// end the session when a request fails the CSRF check
	e2e_post(['action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize([$ids[5]])]);

	$out['kept_without_token'] = e2e_exists($ids[5]);

	// once the realm is revoked the page is denied and a delete request does nothing
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', [$admin['id'], slowlog_e2e_realm()]);
	db_execute_prepared('UPDATE user_auth SET reset_perms = ? WHERE id = ?', [random_int(1, 2000000000), $admin['id']]);

	$denied = slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php')['body'];

	e2e_post(['action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize([$ids[4]]), '__csrf_magic' => $token]);

	$out['denied_without_realm']  = strpos($denied, $tag) === false;
	$out['denied_text']           = (preg_match('/Permission Denied|cactiRedirect|Access Denied/', $denied, $m) ? $m[0] : substr(trim(preg_replace('/\s+/', ' ', strip_tags($denied))), 0, 120));
	$out['kept_when_denied']      = e2e_exists($ids[4]);
} finally {
	slowlog_e2e_web_stop($web);

	foreach ($ids as $id) {
		api_slowlog_remove($id);
	}

	slowlog_e2e_drop_users([$admin]);
	slowlog_e2e_restore_log_setting($saved_log);
}

print json_encode($out) . PHP_EOL;
