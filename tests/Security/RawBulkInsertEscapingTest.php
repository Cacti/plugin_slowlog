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
 * import_logfile() builds its bulk plugin_slowlog_details INSERT as raw SQL
 * text (see PreparedStatementUsageTest for why that pattern is accepted),
 * relying on db_qstr() to escape every value. This proves query content that
 * looks like an injection attempt (an embedded quote followed by a second
 * statement) stays fully contained inside the string literal instead of
 * breaking out of it.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 1);

	$this->logfile = tempnam(sys_get_temp_dir(), 'slowlog_test_');

	file_put_contents($this->logfile, implode("\n", array(
		'# Time: 240116  9:00:00',
		'# User@Host: root[root] @ webhost [10.0.0.9]',
		'# Query_time: 0.500000  Lock_time: 0.000010  Rows_sent: 1  Rows_examined: 1',
		'SET timestamp=1705395600;',
		"SELECT * FROM users WHERE name = 'x'); DELETE FROM plugin_slowlog; --';",
		'',
	)));
});

afterEach(function () {
	@unlink($this->logfile);
});

it('escapes an embedded quote-and-statement injection attempt inside the query literal', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, false);

	$sql = '';

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_execute' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details (') !== false) {
			$sql = $call['sql'];
		}
	}

	// The attacker-controlled quote must be escaped, not left free to close the literal early.
	expect($sql)->toContain("name = \\'x\\'); DELETE FROM plugin_slowlog; --\\'");
	expect($sql)->not->toContain("name = 'x');");
});
