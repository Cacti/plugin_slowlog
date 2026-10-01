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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Return the CSP nonce attribute for inline <script> tags, safely across
 * Cacti versions. Newer Cacti releases enforce a Content-Security-Policy that
 * requires a per-request nonce on parser-inserted scripts; older releases lack
 * the CactiSecureHeaders class, so this returns an empty string there.
 *
 * @return string The nonce attribute when supported, otherwise empty string.
 */
function plugin_slowlog_csp_nonce(): string {
	if (class_exists('CactiSecureHeaders')) {
		return CactiSecureHeaders::getNonceAttribute();
	}

	return '';
}

/**
 * Registers this plugin's Cacti hooks (config arrays, navigation
 * breadcrumbs, settings, tab display) and its slowlog.php realm, then
 * creates the plugin's database tables and seeds their reference data.
 * Invoked by the Cacti plugin framework when the plugin is
 * installed/enabled.
 *
 * @return void
 */
function plugin_slowlog_install(): void {
	global $config;

	api_plugin_register_hook('slowlog', 'config_arrays',         'slowlog_config_arrays',        'setup.php');
	api_plugin_register_hook('slowlog', 'draw_navigation_text',  'slowlog_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('slowlog', 'config_settings',       'slowlog_config_settings',      'setup.php');
	api_plugin_register_hook('slowlog', 'top_header_tabs',       'slowlog_show_tab',             'setup.php');
	api_plugin_register_hook('slowlog', 'top_graph_header_tabs', 'slowlog_show_tab',             'setup.php');

	api_plugin_register_realm('slowlog', 'slowlog.php', 'Plugin -> MySQL Slow Log Viewer', 1);

	require_once($config['base_path'] . '/plugins/slowlog/includes/database.php');

	slowlog_setup_table_new();
}

/**
 * Reads this plugin's version/author metadata from its INFO file,
 * tolerating a missing/malformed file. Called from
 * plugin_slowlog_version() and slowlog_check_upgrade().
 *
 * @return array<string, mixed> The plugin's INFO file 'info' section
 *                              (name, version, author, etc.), or an
 *                              empty array if the file could not be
 *                              read or parsed.
 *
 * @global array $config Cacti global configuration array; used to
 *                       locate the plugin's INFO file.
 */
function slowlog_version(): array {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/slowlog/INFO', true);

	if ($info === false || !isset($info['info']) || !is_array($info['info'])) {
		return [];
	}

	return $info['info'];
}

/**
 * Drops all of this plugin's database tables. Invoked by the Cacti
 * plugin framework when the plugin is uninstalled.
 *
 * @return void
 */
function plugin_slowlog_uninstall(): void {
	global $config;

	require_once($config['base_path'] . '/plugins/slowlog/includes/database.php');

	slowlog_drop_tables();
}

/**
 * Here we will check to ensure everything is configured
 *
 * Runs any pending database schema upgrade check for this plugin.
 * Invoked by the Cacti plugin framework on every page load to keep the
 * plugin's schema current.
 *
 * @return bool Always true.
 */
function plugin_slowlog_check_config(): bool {
	// Here we will check to ensure everything is configured
	slowlog_check_upgrade();

	return true;
}

/**
 * Here we will upgrade to the newest version
 *
 * Runs any pending database schema upgrade check for this plugin.
 * Invoked by the Cacti plugin framework when the plugin's installed
 * version differs from its current version.
 *
 * @return bool Always false.
 */
function plugin_slowlog_upgrade(): bool {
	// Here we will upgrade to the newest version
	slowlog_check_upgrade();

	return false;
}

/**
 * Reads this plugin's version/author metadata from its INFO file.
 * Invoked by the Cacti plugin framework to display plugin information.
 *
 * @return array<string, mixed> The plugin's INFO file 'info' section, as
 *                              returned by slowlog_version().
 */
function plugin_slowlog_version(): array {
	return slowlog_version();
}

/**
 * Applies any pending database schema migrations for this plugin, based
 * on comparing the installed version recorded in plugin_config against
 * the current INFO file version: re-runs table creation (safe/no-op for
 * existing tables/columns) and, for a pre-existing
 * plugin_slowlog_details table, diffs and applies any new
 * columns/indexes via db_update_table(). Only runs on plugins.php or
 * slowlog.php to avoid the version lookup on every page. Called from
 * plugin_slowlog_check_config() and plugin_slowlog_upgrade().
 *
 * @return void
 *
 * @global array  $config            Cacti global configuration array;
 *                                   used to locate database/functions
 *                                   libraries.
 * @global object $database_default  Reserved/declared for parity with
 *                                   the included library files; not used
 *                                   directly here.
 */
function slowlog_check_upgrade(): void {
	global $config, $database_default;
	require_once($config['library_path'] . '/database.php');
	require_once($config['library_path'] . '/functions.php');
	require_once($config['base_path'] . '/plugins/slowlog/includes/database.php');

	// Let's only run this check if we are on a page that actually needs the data
	$files = ['plugins.php', 'slowlog.php'];

	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files, true)) {
		return;
	}

	$info = slowlog_version();

	// The rest of this function indexes 'version'/'longname'/etc. directly - bail out rather
	// than risk undefined-key warnings and writing null metadata into plugin_config if the
	// INFO file is ever unreadable/malformed or missing any of these keys.
	if (!isset($info['version'], $info['longname'], $info['author'], $info['homepage'], $info['name'])) {
		return;
	}

	$current = $info['version'];
	$old     = db_fetch_cell_prepared('SELECT version
		FROM plugin_config
		WHERE directory = ?',
		['slowlog']);

	if ($current != $old) {
		db_execute_prepared('UPDATE plugin_config
			SET version = ?
			WHERE directory = ?',
			[$current, 'slowlog']);
		db_execute_prepared('UPDATE plugin_config
			SET version = ?,
			name = ?,
			author = ?,
			webpage = ?
			WHERE directory = ?',
			[$info['version'], $info['longname'], $info['author'], $info['homepage'], $info['name']]);

		db_execute('DELETE FROM plugin_hooks WHERE function="slowlog_page_head"');

		// The schema create/refresh lives in includes/database.php (the thold model).
		slowlog_upgrade_tables();

		slowlog_prune_files();
	}
}

/**
 * No-op dependency check. Invoked by the Cacti plugin framework to
 * verify this plugin's dependencies are satisfied before
 * installation/upgrade.
 *
 * @return bool Always true.
 *
 * @global array $plugins Reserved/declared for parity with other hook
 *                        implementations; not used directly here.
 * @global array $config  Cacti global configuration array (declared but
 *                        not directly used here).
 */
function slowlog_check_dependencies(): bool {
	global $plugins, $config;

	return true;
}

/**
 * Triggers a schema-upgrade check. Invoked by the Cacti plugin framework
 * via the 'config_arrays' hook.
 *
 * @return void
 */
function slowlog_config_arrays(): void {
	slowlog_check_upgrade();
}

/**
 * Registers this plugin's 'Misc' Settings tab (currently with no fields
 * of its own; merges into any existing 'misc' tab). Invoked by the Cacti
 * plugin framework via the 'config_settings' hook when rendering the
 * Settings page.
 *
 * @return void
 *
 * @global array $tabs     Cacti's registered settings tabs; a 'misc'
 *                         entry is added.
 * @global array $settings Cacti's registered settings fields; a 'misc'
 *                         entry is added/merged.
 */
function slowlog_config_settings(): void {
	global $tabs, $settings;

	$tabs['misc'] = 'Misc';

	$temp = [
	];

	if (isset($settings['misc'])) {
		$settings['misc'] = array_merge($settings['misc'], $temp);
	} else {
		$settings['misc'] = $temp;
	}
}

/**
 * Adds this plugin's page breadcrumb/navigation entries (viewer, import,
 * delete, methods, tables, details, and query-detail views). Invoked by
 * the Cacti plugin framework via the 'draw_navigation_text' hook.
 *
 * @param array<string, array<string, mixed>> $nav Cacti's registered
 *                                                 navigation text
 *                                                 entries.
 *
 * @return array<string, array<string, mixed>> The $nav array with this
 *                                             plugin's entries added.
 */
function slowlog_draw_navigation_text(array $nav): array {
	$nav['slowlog.php:']        = ['title' => 'MySQL Slowlog Viewer', 'mapping' => '', 'url' => 'slowlog.php', 'level' => '0'];
	$nav['slowlog.php:edit']    = ['title' => 'MySQL Slowlog Import', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0'];
	$nav['slowlog.php:actions'] = ['title' => 'MySQL Slowlog Delete', 'mapping' => '', 'url' => 'slowlog.php', 'level' => '0'];
	$nav['slowlog.php:select']  = ['title' => 'MySQL Slowlog Viewer', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0'];
	$nav['slowlog.php:methods'] = ['title' => 'MySQL Slowlog Methods', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0'];
	$nav['slowlog.php:tables']  = ['title' => 'MySQL Slowlog Tables', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0'];
	$nav['slowlog.php:details'] = ['title' => 'MySQL Slowlog Details', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0'];
	$nav['slowlog.php:query']   = ['title' => 'MySQL Slowlog Query Details', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0'];

	return $nav;
}

/**
 * Renders this plugin's tab icon/link on device and graph header pages,
 * when the current user is authorized for slowlog.php (checked once per
 * session and cached). Invoked by the Cacti plugin framework via the
 * 'top_header_tabs' and 'top_graph_header_tabs' hooks.
 *
 * @return void Outputs the tab link HTML directly (nothing if the user
 *              lacks the slowlog.php realm).
 *
 * @global array $config Cacti global configuration array; used to build
 *                       the tab link/image URLs.
 */
function slowlog_show_tab(): void {
	global $config;

	if (!isset($_SESSION['sess_slowlog_level'])) {
		$perms = db_fetch_cell_prepared('SELECT id
			FROM plugin_realms
			WHERE file LIKE ?',
			['%slowlog.php%']) + 100;

		$level = db_fetch_assoc_prepared('SELECT realm_id
            FROM user_auth_realm
            WHERE user_id = ?
            AND realm_id = ?',
			[$_SESSION['sess_user_id'], $perms]);

		if (cacti_sizeof($level)) {
			$_SESSION['sess_slowlog_level'] = true;
		} else {
			$_SESSION['sess_slowlog_level'] = false;
		}
	}

	if ($_SESSION['sess_slowlog_level']) {
		if (substr_count($_SERVER['REQUEST_URI'], 'slowlog')) {
			print '<a href="' . $config['url_path'] . 'plugins/slowlog/slowlog.php"><img src="' . $config['url_path'] . 'plugins/slowlog/images/tab_slowlog_down.gif" alt="SlowLog"></a>';
		} else {
			print '<a href="' . $config['url_path'] . 'plugins/slowlog/slowlog.php"><img src="' . $config['url_path'] . 'plugins/slowlog/images/tab_slowlog.gif" alt="SlowLog"></a>';
		}
	}
}

/**
 * Removes files and directories that a previous version of this plugin
 * shipped but that have since moved or been deleted, using the tombstone
 * and whitelist lists in manifest.json. Whitelisted (user-data) paths and
 * any VCS metadata (.git*) are never touched; the dev-only tests/ tree is
 * removed. Any path that resolves outside the plugin directory (a tampered
 * manifest.json) is refused, and any file/directory that cannot be removed
 * (e.g. read-only) is reported to the Cacti log. Any top-level entry that is
 * neither expected nor a tombstone nor whitelisted is logged to the Cacti
 * log and left in place. Called on a plugin version change.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to resolve
 *                       the plugin directory.
 */
function slowlog_prune_files(): void {
	global $config;

	$plugin_dir    = $config['base_path'] . '/plugins/slowlog';
	$manifest_path = $plugin_dir . '/manifest.json';

	if (!is_readable($manifest_path)) {
		return;
	}

	$manifest = json_decode((string) file_get_contents($manifest_path), true);

	if (!is_array($manifest)) {
		cacti_log('WARNING: slowlog manifest.json could not be parsed; skipping file prune', false, 'SLOWLOG');

		return;
	}

	$tombstones = isset($manifest['tombstones']) && is_array($manifest['tombstones']) ? $manifest['tombstones'] : [];
	$expected   = isset($manifest['expected'])   && is_array($manifest['expected'])   ? $manifest['expected']   : [];
	$whitelist  = isset($manifest['whitelist'])  && is_array($manifest['whitelist'])  ? $manifest['whitelist']  : [];

	$protected = function (string $rel) use ($whitelist): bool {
		if (strncmp($rel, '.git', 4) === 0 || strncmp($rel, '.md', 3) === 0) {
			return true;
		}

		foreach ($whitelist as $entry) {
			$entry = trim((string) $entry, '/');

			if ($entry !== '' && ($rel === $entry
				|| strncmp($rel, $entry . '/', strlen($entry) + 1) === 0
				|| strncmp($entry, $rel . '/', strlen($rel) + 1) === 0)) {
				return true;
			}
		}

		return false;
	};

	// Security: resolve the plugin directory so a tampered manifest.json
	// cannot steer the prune outside of it.
	$plugin_real = realpath($plugin_dir);

	// Remove tombstoned (moved/deleted) paths plus the dev-only tests/
	// tree and the phpunit.xml test configuration.
	$remove   = $tombstones;
	$remove[] = 'tests/';
	$remove[] = 'phpunit.xml';

	foreach ($remove as $rel) {
		$rel = trim((string) $rel, '/');

		if ($rel === '' || $protected($rel)) {
			continue;
		}

		// A tombstone must never contain '.'/'..' segments; a tampered manifest
		// could use them to escape the plugin directory or target its root.
		$segments = explode('/', $rel);

		if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
			cacti_log(sprintf('WARNING: slowlog prune refused to remove %s: path contains a traversal segment (tampered manifest.json?)', $rel), false, 'SLOWLOG');

			continue;
		}

		$path = $plugin_dir . '/' . $rel;

		if (!is_link($path) && !file_exists($path)) {
			continue;
		}

		// Refuse any path that, after resolving symlinks and ../ segments,
		// escapes the plugin directory (protects user data from a tampered
		// manifest.json).
		$anchor = is_link($path) ? dirname($path) : $path;
		$real   = realpath($anchor);

		if ($real === false || ($real !== $plugin_real && strncmp($real, $plugin_real . DIRECTORY_SEPARATOR, strlen((string) $plugin_real) + 1) !== 0)) {
			cacti_log(sprintf('WARNING: slowlog prune refused to remove %s: path resolves outside the plugin directory (tampered manifest.json?)', $rel), false, 'SLOWLOG');

			continue;
		}

		if (is_dir($path) && !is_link($path)) {
			$removed = slowlog_rmtree($path);
		} else {
			$removed = @unlink($path);
		}

		if (!$removed) {
			cacti_log(sprintf('WARNING: slowlog upgrade could not remove %s (check file/directory permissions)', $rel), false, 'SLOWLOG');
		}
	}

	// Surface any top-level entry the manifest does not account for.
	$known = [];

	foreach (array_merge($expected, $tombstones) as $entry) {
		$top = explode('/', trim((string) $entry, '/'))[0];

		if ($top !== '') {
			$known[$top] = true;
		}
	}

	$entries = scandir($plugin_dir);

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..' || $entry === 'tests' || $entry === 'phpunit.xml' || $protected($entry) || isset($known[$entry])) {
			continue;
		}

		cacti_log(sprintf('WARNING: slowlog upgrade found a file/directory not described in manifest.json: %s (left in place)', $entry), false, 'SLOWLOG');
	}
}

/**
 * Recursively deletes a directory and its contents. Symlinks are removed
 * without being followed. Helper for slowlog_prune_files().
 *
 * @param string $dir Absolute path to the directory to remove.
 *
 * @return bool True if the directory and everything under it was removed;
 *              false if any entry could not be deleted.
 */
function slowlog_rmtree(string $dir): bool {
	$entries = scandir($dir);
	$ok      = true;

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;

		if (is_dir($path) && !is_link($path)) {
			if (!slowlog_rmtree($path)) {
				$ok = false;
			}
		} elseif (!@unlink($path)) {
			$ok = false;
		}
	}

	if (!@rmdir($dir)) {
		$ok = false;
	}

	return $ok;
}
