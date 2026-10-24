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
 * get_table_associations() used to read every detail row of a log (including both query
 * columns) in one SELECT. It now reads fixed-size chunks ordered by logentry, so memory
 * does not grow with the size of the log.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

function slowlog_test_chunk_rows(int $from, int $count): array {
	$rows = array();

	for ($i = 0; $i < $count; $i++) {
		$rows[] = array('logentry' => $from + $i, 'query' => 'SELECT 1 FROM t' . (($from + $i) % 3), 'oquery' => '');
	}

	return $rows;
}

it('reads a large log in chunks of 2000 ordered by logentry', function () {
	// First chunk is full, so a second read is made; the second is short, so the loop ends.
	slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'FROM plugin_slowlog_details') !== false && $params[1] === 0;
	}, slowlog_test_chunk_rows(1, 2000));

	slowlog_test_mock_db('db_fetch_assoc_prepared', function ($sql, $params) {
		return strpos($sql, 'FROM plugin_slowlog_details') !== false && $params[1] === 2000;
	}, slowlog_test_chunk_rows(2001, 5));

	get_table_associations(1);

	$selects = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_fetch_assoc_prepared' && strpos($call['sql'], 'FROM plugin_slowlog_details') !== false;
	}));

	expect($selects)->toHaveCount(2);
	expect($selects[0]['sql'])->toContain('ORDER BY logentry');
	expect($selects[0]['sql'])->toContain('LIMIT 2000');
	expect($selects[0]['sql'])->not->toContain('SELECT *');
	expect($selects[1]['params'])->toBe(array(1, 2000));

	$inserts = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details_tables') !== false;
	});

	// one bulk insert per chunk of up to 500 rows: 2000 rows -> 4, then 5 rows -> 1
	expect($inserts)->toHaveCount(5);
	expect($GLOBALS['__test_cacti_log'])->toContain('Post Processing Tables associated: 2005');
});

it('reads exactly one entry when a logentry is given', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'AND logentry = ?', slowlog_test_chunk_rows(7, 1));

	get_table_associations(1, 7);

	$selects = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_fetch_assoc_prepared' && strpos($call['sql'], 'FROM plugin_slowlog_details') !== false;
	});

	expect($selects)->toHaveCount(1);
});

it('does not log an association count when no tables were found', function () {
	$GLOBALS['__test_cacti_log'] = array();

	get_table_associations(1);

	expect($GLOBALS['__test_cacti_log'])->toBe(array());
});
