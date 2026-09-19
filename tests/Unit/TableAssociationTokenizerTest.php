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
 * get_table_associations() drives the regex/scanner based tokenizer
 * (slowlog_extract_tables_from_query() et al). These tests exercise it
 * through the mock database (tests/bootstrap-unit.php): a fixture stands in
 * for a single `plugin_slowlog_details` row per case, then the resulting
 * `db_execute()` INSERT ... plugin_slowlog_details_tables call is captured
 * and its table names extracted.
 *
 * This previously used a per-token state machine that dropped JOIN targets
 * and comma-separated FROM list members - the cases below that exercise
 * those forms document the *fixed* behavior.
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

if (!function_exists('slowlog_test_run_tokenizer')) {
	function slowlog_test_run_tokenizer(string $query): array {
		slowlog_test_mock_db('db_fetch_assoc_prepared', 'FROM plugin_slowlog_details', array(
			array('logentry' => 1, 'query' => $query),
		));

		get_table_associations(1);

		return slowlog_test_extract_table_names($GLOBALS['__test_db_calls']);
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
});

it('finds the table in a simple SELECT ... FROM', function () {
	expect(slowlog_test_run_tokenizer('select id, name from users where id = 1'))->toBe(array('users'));
});

it('finds the table behind a backtick-quoted identifier', function () {
	expect(slowlog_test_run_tokenizer('select * from `users` where id = 1'))->toBe(array('users'));
});

it('finds the table inside an aliased derived subquery', function () {
	expect(slowlog_test_run_tokenizer('select x.id from (select id from users where active = 1) x'))->toBe(array('users'));
});

it('finds tables through two levels of nested derived subqueries', function () {
	expect(slowlog_test_run_tokenizer('select id from (select id from (select id from users) a) b'))->toBe(array('users'));
});

it('prunes the schema qualifier from a schema.table reference', function () {
	expect(slowlog_test_run_tokenizer('select id from cacti.users where id = 1'))->toBe(array('users'));
});

it('finds the table in an INSERT INTO', function () {
	expect(slowlog_test_run_tokenizer("insert into users (id, name) values (1, 'bob')"))->toBe(array('users'));
});

it('finds both tables of an INSERT INTO ... SELECT ... FROM', function () {
	expect(slowlog_test_run_tokenizer('insert into archive select * from live where old = 1'))->toBe(array('archive', 'live'));
});

it('finds the table in an UPDATE ... SET', function () {
	expect(slowlog_test_run_tokenizer("update users set name = 'bob' where id = 1"))->toBe(array('users'));
});

it('finds a subquery referenced from an UPDATE ... SET value', function () {
	expect(slowlog_test_run_tokenizer('update accounts set balance = (select sum(amount) from transactions where accounts.id = transactions.account_id) where id=1'))
		->toBe(array('accounts', 'transactions'));
});

it('finds both tables of an UPDATE ... JOIN ... SET', function () {
	expect(slowlog_test_run_tokenizer('update users u join accounts a on u.id=a.user_id set a.balance=0 where u.id=1'))->toBe(array('users', 'accounts'));
});

it('finds the table in a DELETE FROM', function () {
	expect(slowlog_test_run_tokenizer('delete from users where id = 1'))->toBe(array('users'));
});

it('finds both tables of a multi-table DELETE ... JOIN', function () {
	expect(slowlog_test_run_tokenizer('delete t1, t2 from t1 join t2 on t1.id=t2.id where t1.x=1'))->toBe(array('t1', 't2'));
});

it('finds both tables of a DELETE ... USING JOIN', function () {
	expect(slowlog_test_run_tokenizer('delete from t1, t2 using t1 join t2 on t1.id=t2.id where t1.x=1'))->toBe(array('t1', 't2'));
});

it('finds a table referenced in a WHERE ... IN (subquery)', function () {
	expect(slowlog_test_run_tokenizer('select id from users where dept_id in (select id from departments where active=1)'))->toBe(array('users', 'departments'));
});

it('finds the table in a TRUNCATE TABLE', function () {
	expect(slowlog_test_run_tokenizer('truncate table users'))->toBe(array('users'));
});

it('finds the table in a SELECT ... GROUP BY', function () {
	expect(slowlog_test_run_tokenizer('select count(*) from users group by status'))->toBe(array('users'));
});

it('does not split a comma inside a function call as a table list', function () {
	expect(slowlog_test_run_tokenizer('select concat(first,last) as name from users'))->toBe(array('users'));
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

it('finds every pair of a multi-pair RENAME TABLE', function () {
	expect(slowlog_test_run_tokenizer('rename table a to b, c to d'))->toBe(array('a', 'b', 'c', 'd'));
});

it('finds the table in a FLUSH TABLE', function () {
	expect(slowlog_test_run_tokenizer('flush table users'))->toBe(array('users'));
});

it('finds every table of a multi-table FLUSH TABLES', function () {
	expect(slowlog_test_run_tokenizer('flush tables a, b with read lock'))->toBe(array('a', 'b'));
});

it('finds the schema in a SHOW TABLES FROM', function () {
	expect(slowlog_test_run_tokenizer('show tables from cacti'))->toBe(array('cacti'));
});

it('finds the table in a SHOW CREATE TABLE', function () {
	expect(slowlog_test_run_tokenizer('show create table users'))->toBe(array('users'));
});

it('finds both tables of a two-table JOIN', function () {
	expect(slowlog_test_run_tokenizer('select u.id, o.total from users u join orders o on u.id = o.user_id where o.total > 100'))->toBe(array('users', 'orders'));
});

it('finds every table of a comma-separated FROM list', function () {
	expect(slowlog_test_run_tokenizer('select u.id, o.id from users u, orders o where u.id = o.user_id'))->toBe(array('users', 'orders'));
});

it('finds every table across a chain of JOINs', function () {
	expect(slowlog_test_run_tokenizer('select a.id from users a join orders b on a.id=b.user_id join items c on b.id=c.order_id'))->toBe(array('users', 'orders', 'items'));
});

it('finds both tables of a LEFT OUTER JOIN', function () {
	expect(slowlog_test_run_tokenizer('select * from a left outer join b on a.id=b.id'))->toBe(array('a', 'b'));
});

it('finds both tables of a STRAIGHT_JOIN', function () {
	expect(slowlog_test_run_tokenizer('select * from a straight_join b on a.id=b.id'))->toBe(array('a', 'b'));
});

it('finds the outer table and the table inside a joined derived subquery', function () {
	expect(slowlog_test_run_tokenizer('select a.id from users a join (select user_id from orders where total > 10) b on a.id = b.user_id'))->toBe(array('users', 'orders'));
});

it('preserves the original case of table names', function () {
	expect(slowlog_test_run_tokenizer('SELECT * FROM Users U JOIN Orders O ON U.id=O.user_id'))->toBe(array('Users', 'Orders'));
});

it('finds nothing for a query with no table reference', function () {
	expect(slowlog_test_run_tokenizer('select 1'))->toBe(array());
});

it('does not mistake a table-like word inside a /* */ comment for a real reference', function () {
	expect(slowlog_test_run_tokenizer('select 1 /* FROM admins */'))->toBe(array());
});

it('does not mistake a table-like word inside a -- comment for a real reference', function () {
	expect(slowlog_test_run_tokenizer("select 1 -- FROM admins\n"))->toBe(array());
});

it('does not mistake a table-like word inside a # comment for a real reference', function () {
	expect(slowlog_test_run_tokenizer("select 1 # FROM admins\n"))->toBe(array());
});

it('does not mistake a table-like word inside a string literal for a real reference', function () {
	expect(slowlog_test_run_tokenizer("select 'FROM admins' from users"))->toBe(array('users'));
});

it('still finds a real table reference alongside an unrelated comment', function () {
	expect(slowlog_test_run_tokenizer('select * from users /* legacy FROM orders path */'))->toBe(array('users'));
});
