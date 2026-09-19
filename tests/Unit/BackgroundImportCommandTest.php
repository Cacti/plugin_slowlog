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
 * import_logfile()'s $batch = true path hands post-processing off to a background
 * `import_log.php --logid=N` worker instead of calling import_post_process() inline.
 * $table_names/$usecacti/$table_mode only exist in the parent process, so they must be
 * forwarded on that command line - otherwise the worker infers a mode from an empty table
 * list and legacy list-mode detection (or a supplied reference list) is silently lost.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	$this->logfile = tempnam(sys_get_temp_dir(), 'slowlog_test_');

	file_put_contents($this->logfile, implode("\n", array(
		'# Time: 240115  1:02:03',
		'# User@Host: root[root] @ dbhost [10.0.0.5]',
		'# Query_time: 1.000000  Lock_time: 0.000000  Rows_sent: 1  Rows_examined: 1',
		'SET timestamp=1705280523;',
		'SELECT * FROM users WHERE id = 1;',
		'',
	)));
});

afterEach(function () {
	@unlink($this->logfile);
});

if (!function_exists('slowlog_test_background_command')) {
	function slowlog_test_background_command(): string {
		foreach ($GLOBALS['__test_db_calls'] as $call) {
			if ($call['fn'] === 'exec_background') {
				return $call['sql'];
			}
		}

		return '';
	}
}

it('forwards an explicit table list to the background worker', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, true);

	expect(slowlog_test_background_command())->toMatch('/--table-names=([\'"])accounts users\1/');
});

it('forwards a reference-mode table list to the background worker', function () {
	import_logfile($this->logfile, 'test', -1, 'accounts users', false, true, 'reference');

	expect(slowlog_test_background_command())->toMatch('/--table-mode=([\'"])reference\1/');
	expect(slowlog_test_background_command())->toMatch('/--table-names=([\'"])accounts users\1/');
});

it('omits --table-names entirely when no table list is supplied', function () {
	import_logfile($this->logfile, 'test', -1, '', false, true);

	expect(slowlog_test_background_command())->not->toContain('--table-names=');
});
