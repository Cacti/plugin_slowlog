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
 * get_cacti_tables() lists the tables of every database on the server. Database names that
 * are not plain identifiers (a hyphen, for example) have to be quoted in SHOW TABLES.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('quotes each database name in SHOW TABLES', function () {
	slowlog_test_mock_db('db_fetch_assoc', 'SHOW DATABASES', array(
		array('Database' => 'cacti'),
		array('Database' => 'my-db'),
		array('Database' => 'odd`name'),
	));

	get_cacti_tables();

	$shows = array();

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_fetch_assoc' && strpos($call['sql'], 'SHOW TABLES FROM') === 0) {
			$shows[] = $call['sql'];
		}
	}

	expect($shows)->toBe(array(
		'SHOW TABLES FROM `cacti`',
		'SHOW TABLES FROM `my-db`',
		'SHOW TABLES FROM `odd``name`',
	));
});

it('skips the information_schema and mysql databases', function () {
	slowlog_test_mock_db('db_fetch_assoc', 'SHOW DATABASES', array(
		array('Database' => 'information_schema'),
		array('Database' => 'mysql'),
	));

	expect(get_cacti_tables())->toBe('');
});
