<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for slowlog_version()/plugin_slowlog_version() and the
 * plugin lifecycle contract wrappers (plugin_slowlog_uninstall,
 * plugin_slowlog_check_config, plugin_slowlog_upgrade,
 * slowlog_check_dependencies) in setup.php.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	slowlog_test_reset_db_mocks();
	unset($_SERVER['PHP_SELF']);
});

it('parses the plugin INFO file into an info array', function () {
	$info = slowlog_version();

	expect($info)->toBeArray();
	expect($info)->toHaveKey('name');
	expect($info)->toHaveKey('version');
	expect($info['name'])->toBe('slowlog');
});

it('exposes the same info via plugin_slowlog_version()', function () {
	expect(plugin_slowlog_version())->toBe(slowlog_version());
});

it('drops every slowlog table on uninstall', function () {
	plugin_slowlog_uninstall();

	$drops = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'api_plugin_drop_table';
	});

	expect($drops)->toHaveCount(9);
});

it('reports the config as always valid', function () {
	$_SERVER['PHP_SELF'] = '/cacti/graphs.php';

	expect(plugin_slowlog_check_config())->toBeTrue();
});

it('reports the upgrade as always false', function () {
	$_SERVER['PHP_SELF'] = '/cacti/graphs.php';

	expect(plugin_slowlog_upgrade())->toBeFalse();
});

it('reports its dependencies as always satisfied', function () {
	expect(slowlog_check_dependencies())->toBeTrue();
});
