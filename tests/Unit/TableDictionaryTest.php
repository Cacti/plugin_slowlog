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
*/

/*
 * slowlog_sync_table_dictionary() keeps plugin_slowlog_table_names (the deduplicated
 * table_name dictionary, with an is_cacti_table flag) up to date with whatever tables
 * were just associated with a log.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_dictionary_calls')) {
	function slowlog_test_dictionary_calls(): array {
		return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'plugin_slowlog_table_names') !== false;
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_tables', array(
		array('table_name' => 'host'),
		array('table_name' => 'mystery_table'),
	));
});

it('adds a dictionary row without touching is_cacti_table when there is no reference list', function () {
	slowlog_sync_table_dictionary(1, null);

	$calls = slowlog_test_dictionary_calls();

	expect($calls)->toHaveCount(2);

	foreach ($calls as $call) {
		expect($call['sql'])->toContain('INSERT IGNORE INTO plugin_slowlog_table_names');
		expect($call['sql'])->not->toContain('is_cacti_table');
	}

	expect(array_column($calls, 'params'))->toBe(array(array('host'), array('mystery_table')));
});

it('flags tables found in the supplied reference list and marks the rest as not-Cacti', function () {
	slowlog_sync_table_dictionary(1, array('host', 'graph_local'));

	$calls = slowlog_test_dictionary_calls();

	expect($calls)->toHaveCount(2);
	expect($calls[0]['sql'])->toContain('ON DUPLICATE KEY UPDATE is_cacti_table');
	expect($calls[0]['params'])->toBe(array('host', 1));
	expect($calls[1]['params'])->toBe(array('mystery_table', 0));
});

it('does nothing when the log has no associated tables', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details_tables', array());

	slowlog_sync_table_dictionary(1, array('host'));

	expect(slowlog_test_dictionary_calls())->toBe(array());
});

it('is invoked as part of the normal post-processing pipeline', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog_functions.php'));

	expect($source)->toContain('slowlog_sync_table_dictionary($logid, $known_tables);');
});
