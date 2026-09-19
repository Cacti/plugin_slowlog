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
 * get_table_associations() is the query tokenizer. These tests drive it
 * through the mock database (tests/bootstrap-unit.php): a fixture stands in
 * for `plugin_slowlog_reserved_words` (seeded from the real keywords.txt) and
 * for a single `plugin_slowlog_details` row per case, then the resulting
 * `db_execute()` INSERT ... plugin_slowlog_details_tables call is captured
 * and its table names extracted.
 *
 * Several cases below are marked "known defect" - they capture the
 * tokenizer's *current* (incorrect) output so a future rewrite has a
 * regression baseline, not because the behavior is desired.
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_extract_table_names')) {
	function slowlog_test_extract_table_names(array $calls) {
		$tables = array();

		foreach ($calls as $call) {
			if ($call['fn'] === 'db_execute' && strpos($call['sql'], 'plugin_slowlog_details_tables') !== false) {
				if (preg_match_all("/\\(\\d+,\\s*\\d+,\\s*'([^']*)'\\)/", $call['sql'], $matches)) {
					foreach ($matches[1] as $table) {
						$tables[] = $table;
					}
				}
			}
		}

		return $tables;
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	$keywords = file(realpath(__DIR__ . '/../../keywords.txt'));
	$rows     = array();

	foreach ($keywords as $word) {
		$word = trim($word);

		if ($word !== '') {
			$rows[] = array('word' => $word);
		}
	}

	slowlog_test_mock_db('db_fetch_assoc_prepared', 'plugin_slowlog_reserved_words', $rows);
});

if (!function_exists('slowlog_test_run_tokenizer')) {
	function slowlog_test_run_tokenizer(string $query): array {
		slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details', array(
			array('logentry' => 1, 'query' => $query),
		));

		get_table_associations(1);

		return slowlog_test_extract_table_names($GLOBALS['__test_db_calls']);
	}
}

it('finds the table in a simple SELECT ... FROM', function () {
	expect(slowlog_test_run_tokenizer('select id, name from users where id = 1'))->toBe(array('users'));
});

it('finds the table behind a backtick-quoted identifier', function () {
	expect(slowlog_test_run_tokenizer('select * from `users` where id = 1'))->toBe(array('users'));
});

it('finds the table inside an aliased derived subquery', function () {
	expect(slowlog_test_run_tokenizer('select x.id from (select id from users where active = 1) x'))->toBe(array('users'));
});

it('prunes the schema qualifier from a schema.table reference', function () {
	expect(slowlog_test_run_tokenizer('select id from cacti.users where id = 1'))->toBe(array('users'));
});

it('finds the table in an INSERT INTO', function () {
	expect(slowlog_test_run_tokenizer("insert into users (id, name) values (1, 'bob')"))->toBe(array('users'));
});

it('finds the table in an UPDATE ... SET', function () {
	expect(slowlog_test_run_tokenizer("update users set name = 'bob' where id = 1"))->toBe(array('users'));
});

it('finds the table in a DELETE FROM', function () {
	expect(slowlog_test_run_tokenizer('delete from users where id = 1'))->toBe(array('users'));
});

it('finds the table in a TRUNCATE TABLE', function () {
	expect(slowlog_test_run_tokenizer('truncate table users'))->toBe(array('users'));
});

it('finds the table in a SELECT ... GROUP BY', function () {
	expect(slowlog_test_run_tokenizer('select count(*) from users group by status'))->toBe(array('users'));
});

it('finds both tables of a UNION', function () {
	expect(slowlog_test_run_tokenizer('select id from users union select id from admins'))->toBe(array('users', 'admins'));
});

it('finds the source table of a SELECT ... INTO OUTFILE', function () {
	expect(slowlog_test_run_tokenizer("select * from users into outfile '/tmp/out.csv'"))->toBe(array('users'));
});

it('finds the destination table of a LOAD DATA INFILE', function () {
	expect(slowlog_test_run_tokenizer("load data infile '/tmp/in.csv' into table users"))->toBe(array('users'));
});

it('finds both tables of a RENAME TABLE', function () {
	expect(slowlog_test_run_tokenizer('rename table users to accounts'))->toBe(array('users', 'accounts'));
});

it('finds the table in a FLUSH TABLE', function () {
	expect(slowlog_test_run_tokenizer('flush table users'))->toBe(array('users'));
});

it('finds the schema in a SHOW TABLES FROM', function () {
	expect(slowlog_test_run_tokenizer('show tables from cacti'))->toBe(array('cacti'));
});

it('KNOWN DEFECT: drops the joined table from a two-table JOIN', function () {
	$tables = slowlog_test_run_tokenizer('select u.id, o.total from users u join orders o on u.id = o.user_id where o.total > 100');

	// "orders" should be present too; the tokenizer currently only records "users".
	expect($tables)->toBe(array('users'));
});

it('KNOWN DEFECT: drops the second table from a comma-separated FROM list', function () {
	$tables = slowlog_test_run_tokenizer('select u.id, o.id from users u, orders o where u.id = o.user_id');

	// "orders" should be present too; the tokenizer currently only records "users".
	expect($tables)->toBe(array('users'));
});

it('KNOWN DEFECT: drops the first joined table across a chain of JOINs', function () {
	$tables = slowlog_test_run_tokenizer('select a.id from users a join orders b on a.id=b.user_id join items c on b.id=c.order_id');

	// "orders" should be present too; the tokenizer currently drops the first JOIN target.
	expect($tables)->toBe(array('users', 'items'));
});
