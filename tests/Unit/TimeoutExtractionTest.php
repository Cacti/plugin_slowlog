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
 * slowlog_extract_timeout_value() normalizes both timeout hint styles to seconds:
 * MySQL's MAX_EXECUTION_TIME(N) optimizer hint is milliseconds, MariaDB's
 * max_statement_time=N wrapper is already seconds.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
});

it('converts a MAX_EXECUTION_TIME hint from milliseconds to seconds', function () {
	expect(slowlog_extract_timeout_value('SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM users'))->toBe(5.0);
	expect(slowlog_extract_timeout_value('SELECT /*+ MAX_EXECUTION_TIME(250) */ * FROM users'))->toBe(0.25);
});

it('reads a max_statement_time wrapper as seconds already', function () {
	expect(slowlog_extract_timeout_value('SET STATEMENT max_statement_time=30 FOR SELECT * FROM users'))->toBe(30.0);
	expect(slowlog_extract_timeout_value('SET STATEMENT max_statement_time=2.5 FOR SELECT * FROM users'))->toBe(2.5);
});

it('returns null when no timeout hint is present', function () {
	expect(slowlog_extract_timeout_value('SELECT * FROM users'))->toBeNull();
});

it('populates the timeout column for rows using either timeout hint', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details', array(
		array('logentry' => 1, 'query' => 'SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM users'),
		array('logentry' => 2, 'query' => 'SET STATEMENT max_statement_time=30 FOR SELECT * FROM users'),
	));

	slowlog_set_timeouts(1);

	$calls = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'UPDATE plugin_slowlog_details') !== false && strpos($call['sql'], 'SET timeout') !== false;
	}));

	expect(array_column($calls, 'params'))->toBe(array(
		array(5.0, 1, 1),
		array(30.0, 1, 2),
	));
});

it('leaves rows without a timeout hint untouched', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details', array());

	slowlog_set_timeouts(1);

	$calls = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'SET timeout') !== false;
	}));

	expect($calls)->toBe(array());
});
