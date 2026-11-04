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
 * Helpers shared by the end-to-end tests and their driver scripts. This file only defines
 * functions, and it ends the request with a 404 when a web server is asked to run it.
 * tests/ ships inside the plugin directory, so nothing here may be reachable over the web.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

/**
 * @return string The Cacti root, two directories above the plugin.
 */
function slowlog_e2e_root(): string {
	return dirname(__DIR__, 4);
}

/**
 * Prepares a driver process: moves to the Cacti root and gives the Cacti CLI bootstrap an argv.
 *
 * @return string The Cacti root.
 */
function slowlog_e2e_enter(string $driver): string {
	$root = slowlog_e2e_root();

	chdir($root);

	$_SERVER['argv'] = [basename($driver)];

	return $root;
}

/**
 * @return string The private temp directory the test process gave the driver.
 */
function slowlog_e2e_tmp(): string {
	return rtrim((string) getenv('SLOWLOG_E2E_TMP'), '/');
}

/**
 * Whether the test user can write Cacti's log; Cacti's CLI bootstrap exits when it cannot, so
 * a driver started by this user would print nothing useful.
 */
function slowlog_e2e_log_writable(string $root): bool {
	$log = $root . '/log/cacti.log';

	return is_file($log) ? is_writable($log) : is_writable(dirname($log));
}

/**
 * Cuts cacti.log back to the length it had before a driver ran. The job's final step fails on any
 * ERROR or WARNING line, and the drivers provoke some on purpose.
 */
function slowlog_e2e_restore_log(string $root, int $length): void {
	$log = $root . '/log/cacti.log';

	if (is_file($log) && filesize($log) > $length) {
		$handle = fopen($log, 'r+');

		if ($handle !== false) {
			ftruncate($handle, $length);
			fclose($handle);
		}
	}
}

/**
 * @return string|null The reason the end-to-end tests cannot run here, or null when they can.
 */
function slowlog_e2e_skip_reason(): ?string {
	$root = slowlog_e2e_root();

	if (getenv('SLOWLOG_E2E') !== '1' || !is_readable($root . '/include/config.php')) {
		return 'Set SLOWLOG_E2E=1 with an installed Cacti and database to run.';
	}

	if (!slowlog_e2e_log_writable($root)) {
		return 'Cacti log/cacti.log is not writable by the test user; Cacti would refuse to start.';
	}

	return null;
}

/**
 * Runs a driver script in its own process, with a private temp directory, and returns what it
 * printed as JSON on its last line. The driver needs the real Cacti and database, which the unit
 * test process does not load.
 *
 * @return array<string, mixed> The driver's observations, or a 'driver_failed' entry with its output.
 */
function slowlog_e2e_observe(string $driver): array {
	static $cache = [];

	if (isset($cache[$driver])) {
		return $cache[$driver];
	}

	$root   = slowlog_e2e_root();
	$output = [];
	$status = 0;
	$tmp    = sys_get_temp_dir() . '/slowlog-e2e-' . bin2hex(random_bytes(6));
	$before = is_file($root . '/log/cacti.log') ? (int) filesize($root . '/log/cacti.log') : 0;

	mkdir($tmp, 0700);
	putenv('SLOWLOG_E2E_TMP=' . $tmp);
	putenv('SLOWLOG_COV_TAG=' . basename($driver, '.php'));

	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/slowlog/tests/E2E/' . $driver) . ' 2>&1', $output, $status);

	putenv('SLOWLOG_E2E_TMP');
	putenv('SLOWLOG_COV_TAG');
	slowlog_e2e_restore_log($root, $before);
	slowlog_e2e_remove_tree($tmp);

	$json = json_decode((string) end($output), true);

	$cache[$driver] = is_array($json) ? $json : ['driver_failed' => 'exit status ' . $status . "\n" . implode("\n", $output)];

	return $cache[$driver];
}

/**
 * Sends the log the drivers' Cacti processes write to a temp file for the length of a run, so errors a
 * test provokes on purpose stay out of the CI job's cacti.log.
 *
 * @return mixed The previous setting, to pass to slowlog_e2e_restore_log_setting().
 */
function slowlog_e2e_redirect_log(string $tmp) {
	$saved = db_fetch_cell_prepared("SELECT value FROM settings WHERE name = 'path_cactilog'");

	set_config_option('path_cactilog', $tmp . '/cacti.log');

	return $saved;
}

/**
 * Puts the log setting back as slowlog_e2e_redirect_log() found it.
 *
 * @param mixed $saved The value slowlog_e2e_redirect_log() returned.
 */
function slowlog_e2e_restore_log_setting($saved): void {
	if ($saved === false || $saved === null) {
		db_execute_prepared("DELETE FROM settings WHERE name = 'path_cactilog'");
	} else {
		set_config_option('path_cactilog', $saved);
	}
}

/**
 * Copies a directory tree, skipping version control data.
 */
function slowlog_e2e_copy_tree(string $from, string $to): void {
	mkdir($to, 0700, true);

	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

	foreach ($items as $item) {
		$relative = substr($item->getPathname(), strlen($from) + 1);

		if (strncmp($relative, '.git', 4) === 0) {
			continue;
		}

		if ($item->isDir()) {
			@mkdir($to . '/' . $relative, 0700, true);
		} else {
			copy($item->getPathname(), $to . '/' . $relative);
		}
	}
}

/**
 * Deletes a directory tree that a test created. Symlinks are removed, never followed.
 */
function slowlog_e2e_remove_tree(string $dir): void {
	if (!is_dir($dir) || is_link($dir)) {
		return;
	}

	foreach (scandir($dir) ?: [] as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;

		if (is_dir($path) && !is_link($path)) {
			slowlog_e2e_remove_tree($path);
		} else {
			@unlink($path);
		}
	}

	@rmdir($dir);
}

/**
 * Starts PHP's built-in web server on the installed Cacti, served under /cacti/ because Cacti builds
 * its links from url_path.
 *
 * @return array<string, mixed> The handle the other web helpers take.
 */
function slowlog_e2e_web_start(string $tmp): array {
	$port = 18000 + (getmypid() % 1000);
	$web  = ['port' => $port, 'base' => '127.0.0.1:' . $port, 'jar' => $tmp . '/jar-' . getmypid(), 'docroot' => $tmp . '/docroot-' . getmypid()];

	mkdir($web['docroot']);
	symlink(slowlog_e2e_root(), $web['docroot'] . '/cacti');

	$web['proc'] = proc_open([PHP_BINARY, '-S', $web['base'], '-t', $web['docroot']], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

	for ($i = 0; $i < 100; $i++) {
		$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

		if ($sock) {
			fclose($sock);

			break;
		}

		usleep(100000);
	}

	return $web;
}

/**
 * Stops the server and removes what slowlog_e2e_web_start() created.
 *
 * @param array<string, mixed> $web The handle from slowlog_e2e_web_start().
 */
function slowlog_e2e_web_stop(array $web): void {
	proc_terminate($web['proc']);
	proc_close($web['proc']);

	@unlink($web['jar']);
	@unlink($web['docroot'] . '/cacti');
	@rmdir($web['docroot']);
}

/**
 * Sends one request to the server, keeping the session cookie between calls.
 *
 * @param array<string, mixed> $web    The handle from slowlog_e2e_web_start().
 * @param string               $method GET or POST.
 * @param string               $path   The path and query, below /cacti/.
 * @param array<string, mixed> $fields Form fields for a POST; a CURLFile makes it a multipart upload.
 *
 * @return array{code: int, body: string}
 */
function slowlog_e2e_request(array $web, string $method, string $path, array $fields = []): array {
	$ch = curl_init($web['base'] . '/cacti/' . ltrim($path, '/'));

	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_COOKIEJAR      => $web['jar'],
		CURLOPT_COOKIEFILE     => $web['jar'],
		CURLOPT_TIMEOUT        => 120,
	]);

	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, array_filter($fields, function ($v) { return $v instanceof CURLFile; }) ? $fields : http_build_query($fields));
	}

	$body = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

	curl_close($ch);

	return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

/**
 * @return string The CSRF token of the first form on a page, or ''.
 */
function slowlog_e2e_token(string $html): string {
	return preg_match('/name=[\'"]__csrf_magic[\'"]\s+value=[\'"]([^\'"]+)[\'"]/', $html, $m)
		|| preg_match('/value=[\'"]([^\'"]+)[\'"][^>]*name=[\'"]__csrf_magic[\'"]/', $html, $m) ? $m[1] : '';
}

/**
 * Signs in over HTTP.
 *
 * @param array<string, mixed> $web The handle from slowlog_e2e_web_start().
 */
function slowlog_e2e_login(array $web, string $user, string $password): void {
	$page = slowlog_e2e_request($web, 'GET', 'index.php');

	slowlog_e2e_request($web, 'POST', 'index.php', [
		'action'         => 'login',
		'login_username' => $user,
		'login_password' => $password,
		'realm'          => 'local',
		'__csrf_magic'   => slowlog_e2e_token($page['body']),
	]);
}

/**
 * Creates a local test user, optionally in a group, with the given realms granted to the user
 * directly and to the group.
 *
 * @param array<int, int> $user_realms  Realm ids granted to the user.
 * @param array<int, int> $group_realms Realm ids granted to a new group the user joins.
 *
 * @return array{id: int, name: string, password: string}
 */
function slowlog_e2e_user(string $name, array $user_realms, array $group_realms = []): array {
	$password = 'E2e-' . bin2hex(random_bytes(6)) . '!';

	// a run that was stopped early can leave its user behind, and a second user of the same name would be the one that signs in
	slowlog_e2e_drop_users(db_fetch_assoc_prepared('SELECT id, username AS name FROM user_auth WHERE username = ?', [$name]));

	db_execute_prepared('INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, enabled, lastchange) VALUES (?, ?, 0, ?, ?, ?, ?, ?)',
		[$name, password_hash($password, PASSWORD_DEFAULT), $name, '', '', 'on', time()]);

	$id = (int) db_fetch_insert_id();

	foreach ($user_realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)', [$id, $realm]);
	}

	if ($group_realms) {
		db_execute_prepared('INSERT INTO user_auth_group (name, description, enabled) VALUES (?, ?, ?)', [$name, $name, 'on']);

		$group = (int) db_fetch_insert_id();

		db_execute_prepared('INSERT INTO user_auth_group_members (group_id, user_id) VALUES (?, ?)', [$group, $id]);

		foreach ($group_realms as $realm) {
			db_execute_prepared('INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES (?, ?)', [$group, $realm]);
		}
	}

	return ['id' => $id, 'name' => $name, 'password' => $password];
}

/**
 * Removes the users slowlog_e2e_user() created, with their realms and groups.
 *
 * @param array<int, array{id: int, name: string}> $users The users to remove.
 */
function slowlog_e2e_drop_users(array $users): void {
	foreach ($users as $user) {
		$group = db_fetch_cell_prepared('SELECT id FROM user_auth_group WHERE name = ?', [$user['name']]);

		if ($group) {
			db_execute_prepared('DELETE FROM user_auth_group_realm WHERE group_id = ?', [$group]);
			db_execute_prepared('DELETE FROM user_auth_group_members WHERE group_id = ?', [$group]);
			db_execute_prepared('DELETE FROM user_auth_group WHERE id = ?', [$group]);
		}

		db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', [$user['id']]);
		db_execute_prepared('DELETE FROM user_auth WHERE id = ?', [$user['id']]);
	}
}

/**
 * Writes a slow log with one entry per item: user, query and timing vary so sorting and filtering
 * have something to work on.
 *
 * @param array<int, string> $queries The query for entry N is $queries[N % count].
 */
function slowlog_e2e_synthetic_log(string $path, int $entries, array $queries): void {
	$users = ['cacti', 'poller', 'web', 'report'];
	$lines = ['Time                 Id Command    Argument'];

	for ($i = 0; $i < $entries; $i++) {
		$user    = $users[$i % 4];
		$lines[] = '# Time: 240115  1:' . sprintf('%02d:%02d', intdiv($i, 60) % 60, $i % 60);
		$lines[] = '# User@Host: ' . $user . '[' . $user . '] @ host' . ($i % 7) . '.example.com [10.0.' . ($i % 250) . '.' . ($i % 200 + 1) . ']  Id: ' . $i;
		$lines[] = '# Query_time: ' . sprintf('%.6f', ($i % 97) / 10) . '  Lock_time: 0.000' . sprintf('%03d', $i % 1000) . '  Rows_sent: ' . ($i % 50) . '  Rows_examined: ' . ($i % 5000);
		$lines[] = 'SET timestamp=' . (1705280000 + $i) . ';';
		$lines[] = str_replace('{i}', (string) $i, $queries[$i % count($queries)]) . ';';
	}

	$lines[] = '# Time: 991231 23:59:59';

	file_put_contents($path, implode("\n", $lines) . "\n");
}

/**
 * @return int The realm id that grants slowlog.php (the plugin realm number plus 100).
 */
function slowlog_e2e_realm(): int {
	return (int) db_fetch_cell_prepared('SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?', ['slowlog', 'slowlog.php']) + 100;
}
