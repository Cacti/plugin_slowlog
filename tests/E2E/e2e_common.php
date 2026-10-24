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

	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/slowlog/tests/E2E/' . $driver) . ' 2>&1', $output, $status);

	putenv('SLOWLOG_E2E_TMP');
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
