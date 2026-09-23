<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for slowlog_config_settings() and
 * slowlog_draw_navigation_text() in setup.php.
 *
 * slowlog_config_arrays() is exercised indirectly through
 * SlowlogCheckUpgradeTest.php (it is a thin wrapper around
 * slowlog_check_upgrade()), so it is not duplicated here.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

it('adds the misc tab and settings when no settings exist yet', function () {
	global $tabs, $settings;

	$tabs     = array();
	$settings = array();

	slowlog_config_settings();

	expect($tabs['misc'])->toBe('Misc');
	expect($settings)->toHaveKey('misc');
});

it('merges into an existing misc settings array without clobbering it', function () {
	global $tabs, $settings;

	$tabs     = array();
	$settings = array('misc' => array('other_setting' => array('friendly_name' => 'Other')));

	slowlog_config_settings();

	expect($settings['misc'])->toHaveKey('other_setting');
});

it('adds the slowlog breadcrumb entries without disturbing existing ones', function () {
	$nav = slowlog_draw_navigation_text(array('other.php:' => array('title' => 'Other')));

	expect($nav)->toHaveKey('other.php:');
	expect($nav)->toHaveKey('slowlog.php:');
	expect($nav['slowlog.php:']['url'])->toBe('slowlog.php');
});
