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
 * Driver for DeleteConfirmationFlowTest. Starts PHP's built-in web server on the installed
 * Cacti, signs in over HTTP, and walks the delete confirmation: select logs, open the
 * confirmation page, confirm. Prints one JSON object on the last line of output.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_common.php';

$root = slowlog_e2e_root();
$tmp  = rtrim((string) getenv('SLOWLOG_E2E_TMP'), '/');

chdir($root);

$_SERVER['argv'] = [basename(__FILE__)];

require_once $root . '/include/cli_check.php';
require_once $root . '/plugins/slowlog/includes/database.php';
require_once $root . '/plugins/slowlog/includes/functions.php';

$saved_log = slowlog_e2e_redirect_log($tmp);

$port    = 18000 + (getmypid() % 1000);
$docroot = $tmp . '/slowlog-e2e-docroot-' . getmypid();
$base    = 'http://127.0.0.1:' . $port;
$jar     = $tmp . '/slowlog-e2e-jar-' . getmypid();
$tag     = 'e2e-del-' . getmypid();
$password = 'E2e-' . bin2hex(random_bytes(6)) . '!';
$out     = [];
$ids     = [];

$saved = db_fetch_row_prepared('SELECT password, must_change_password, password_change, lastchange FROM user_auth WHERE id = 1');

/**
 * Sends one request to the built-in server, keeping the session cookie between calls.
 *
 * @param string               $method GET or POST.
 * @param string               $path   The request path and query.
 * @param array<string,string> $fields Form fields for a POST.
 *
 * @return string The response body.
 */
function e2e_request(string $method, string $path, array $fields = []): string {
	global $base, $jar;

	$ch = curl_init($base . $path);

	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_COOKIEJAR      => $jar,
		CURLOPT_COOKIEFILE     => $jar,
		CURLOPT_TIMEOUT        => 30,
	]);

	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
	}

	$body = curl_exec($ch);

	curl_close($ch);

	return is_string($body) ? $body : '';
}

/** @return string The CSRF token of the first form on a page. */
function e2e_token(string $html): string {
	return preg_match('/name=[\'"]__csrf_magic[\'"]\s+value=[\'"]([^\'"]+)[\'"]/', $html, $m)
		|| preg_match('/value=[\'"]([^\'"]+)[\'"][^>]*name=[\'"]__csrf_magic[\'"]/', $html, $m) ? $m[1] : '';
}

function e2e_exists(int $id): bool {
	return (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog WHERE logid = ?', [$id]);
}

foreach ([1, 2] as $n) {
	db_execute_prepared('INSERT INTO plugin_slowlog (description, import_status, import_text_status) VALUES (?, 2, ?)', [$tag . '-' . $n, 'All Tables Processed']);
	$ids[$n] = (int) db_fetch_insert_id();
}

$realm = (int) db_fetch_cell_prepared("SELECT id FROM plugin_realms WHERE plugin = 'slowlog' AND file = 'slowlog.php'") + 100;

db_execute_prepared('INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES (1, ?)', [$realm]);
db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', lastchange = ? WHERE id = 1", [password_hash($password, PASSWORD_DEFAULT), time()]);

// Cacti builds its links from url_path (/cacti/ by default), so serve the tree under that path
mkdir($docroot);
symlink($root, $docroot . '/cacti');

$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

try {
	$up = false;

	for ($i = 0; $i < 50 && !$up; $i++) {
		$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

		if ($sock) {
			fclose($sock);
			$up = true;
		} else {
			usleep(100000);
		}
	}

	$out['server_up'] = $up;

	$login = e2e_request('GET', '/cacti/index.php');

	e2e_request('POST', '/cacti/index.php', [
		'action'         => 'login',
		'login_username' => 'admin',
		'login_password' => $password,
		'realm'          => 'local',
		'__csrf_magic'   => e2e_token($login),
	]);

	$list = e2e_request('GET', '/cacti/plugins/slowlog/slowlog.php');

	$out['list_shows_logs']  = strpos($list, $tag . '-1') !== false && strpos($list, $tag . '-2') !== false;

	// select both logs and ask for the delete action
	$confirm = e2e_request('POST', '/cacti/plugins/slowlog/slowlog.php', [
		'action'               => 'actions',
		'drp_action'           => '1',
		'chk_' . $ids[1]       => $ids[1],
		'chk_' . $ids[2]       => $ids[2],
		'__csrf_magic'         => e2e_token($list),
	]);

	$out['confirm_lists_logs']       = strpos($confirm, $tag . '-1') !== false && strpos($confirm, $tag . '-2') !== false;
	$out['confirm_yes_is_submit']    = (bool) preg_match("/<input type='submit' name='save' value='Yes'/", $confirm);
	$out['confirm_no_is_button']     = (bool) preg_match("/<input type='button'[^>]*cactiReturnTo[^>]*value='No'/", $confirm);
	$out['confirm_submit_controls']  = preg_match_all("/<input type='submit'/", $confirm);
	$out['confirm_cancel_submit']    = (bool) preg_match("/<input type='submit' name='cancel'/", $confirm);

	// opening the confirmation page deletes nothing
	$out['kept_after_confirm_page'] = e2e_exists($ids[1]) && e2e_exists($ids[2]);

	preg_match("/name='selected_items' value='([^']*)'/", $confirm, $selected);

	e2e_request('POST', '/cacti/plugins/slowlog/slowlog.php', [
		'action'         => 'actions',
		'drp_action'     => '1',
		'selected_items' => html_entity_decode($selected[1] ?? ''),
		'save'           => 'Yes',
		'__csrf_magic'   => e2e_token($confirm),
	]);

	$out['deleted_after_yes'] = !e2e_exists($ids[1]) && !e2e_exists($ids[2]);

	// a denied user must not reach the page
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = 1 AND realm_id = ?', [$realm]);

	$denied = e2e_request('GET', '/cacti/plugins/slowlog/slowlog.php');

	$out['denied_without_realm'] = strpos($denied, $tag) === false;
	$out['denied_text']          = substr(trim(preg_replace('/\s+/', ' ', strip_tags($denied))), 0, 120);
} finally {
	proc_terminate($server);
	proc_close($server);

	slowlog_e2e_restore_log_setting($saved_log);

	foreach ($ids as $id) {
		api_slowlog_remove($id);
	}

	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = 1 AND realm_id = ?', [$realm]);
	db_execute_prepared('UPDATE user_auth SET password = ?, must_change_password = ?, password_change = ?, lastchange = ? WHERE id = 1', [$saved['password'], $saved['must_change_password'], $saved['password_change'], $saved['lastchange']]);
	@unlink($jar);
	@unlink($docroot . '/cacti');
	@rmdir($docroot);
}

slowlog_e2e_restore_log_setting($saved_log);

print json_encode($out) . PHP_EOL;
