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
 * When a bulk INSERT of detail rows fails (for example a value that does not fit its column
 * under strict SQL mode), the import has to stop and say so. It used to carry on and finish
 * as a complete import with the rows from the failed batch missing.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');

	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 1);
	slowlog_test_mock_db('db_execute', 'INSERT INTO plugin_slowlog_details (', false);

	$this->logfile = tempnam(sys_get_temp_dir(), 'slowlog_test_');
});

afterEach(function () {
	@unlink($this->logfile);
});

function slowlog_test_write_entries(string $path, int $count): void {
	$lines = array();

	for ($i = 0; $i < $count; $i++) {
		$lines[] = '# Time: 240116  9:00:00';
		$lines[] = '# User@Host: root[root] @ webhost [10.0.0.9]';
		$lines[] = '# Query_time: 0.5  Lock_time: 0.01  Rows_sent: 1  Rows_examined: 1';
		$lines[] = 'SET timestamp=1705395600;';
		$lines[] = 'SELECT ' . $i . ';';
	}

	file_put_contents($path, implode("\n", $lines) . "\n");
}

function slowlog_test_status_calls(): array {
	return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'UPDATE plugin_slowlog') === 0;
	}));
}

it('marks the import failed when the final batch cannot be inserted', function () {
	slowlog_test_write_entries($this->logfile, 3);

	import_logfile($this->logfile, 'test', -1, '', false, false, null, 4242);

	$failed = array_values(array_filter(slowlog_test_status_calls(), function ($call) {
		return strpos($call['sql'], 'import_status = 3') !== false;
	}));

	expect($failed)->toHaveCount(1);
	expect($failed[0]['params'])->toBe(array('Import Failed - Row Insert Error', 4242));

	foreach (slowlog_test_status_calls() as $call) {
		expect($call['sql'])->not->toContain('import_status = 1');
	}
});

it('stops at the first failed full batch instead of reading on', function () {
	slowlog_test_write_entries($this->logfile, SLOWLOG_IMPORT_BATCH_SIZE + 5);

	import_logfile($this->logfile, 'test', -1, '', false, false, null, 4242);

	$inserts = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details (') !== false;
	});

	expect($inserts)->toHaveCount(1);

	$failed = array_filter(slowlog_test_status_calls(), function ($call) {
		return strpos($call['sql'], 'import_status = 3') !== false;
	});

	expect($failed)->toHaveCount(1);
});

it('still completes the import when every insert succeeds', function () {
	slowlog_test_mock_db('db_execute', 'INSERT INTO plugin_slowlog_details (', true);
	slowlog_test_write_entries($this->logfile, 3);

	import_logfile($this->logfile, 'test', -1, '', false, false, null, 4242);

	$failed = array_filter(slowlog_test_status_calls(), function ($call) {
		return strpos($call['sql'], 'import_status = 3') !== false;
	});

	expect($failed)->toBeEmpty();
});
