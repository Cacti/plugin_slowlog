<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Integration coverage for plugin_slowlog_install(): verifies every hook
 * and the realm the plugin depends on at runtime are actually registered,
 * together with its full table set, in a single end-to-end pass.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	slowlog_test_reset_db_mocks();
	$GLOBALS['__test_registered_hooks']  = array();
	$GLOBALS['__test_registered_realms'] = array();
});

it('registers every hook slowlog depends on, its realm, and provisions its tables', function () {
	plugin_slowlog_install();

	$hooks = array();
	foreach ($GLOBALS['__test_registered_hooks'] as $registered) {
		$hooks[$registered['hook']] = $registered;
	}

	foreach (array(
		'config_arrays',
		'draw_navigation_text',
		'config_settings',
		'top_header_tabs',
		'top_graph_header_tabs',
	) as $expected) {
		expect($hooks)->toHaveKey($expected);
		expect($hooks[$expected]['plugin'])->toBe('slowlog');
		expect($hooks[$expected]['file'])->toBe('setup.php');
	}

	expect($GLOBALS['__test_registered_realms'])->toHaveCount(1);
	expect($GLOBALS['__test_registered_realms'][0]['file'])->toBe('slowlog.php');

	$createdTables = array_values(array_unique(array_map(function ($call) {
		return $call['sql'];
	}, array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'api_plugin_db_table_create';
	}))));

	expect($createdTables)->toContain('plugin_slowlog_details');
	expect($createdTables)->toContain('plugin_slowlog_stats');
});
