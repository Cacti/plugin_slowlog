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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/


uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
	$this->file = tempnam(sys_get_temp_dir(), 'slowlog-outcome-');
	file_put_contents($this->file, "# Time: 240101  0:00:00\n# User@Host: app[app] @ host [127.0.0.1]\n# Query_time: 1.0 Lock_time: 0.0 Rows_sent: 1 Rows_examined: 1\nSET timestamp=1704067200;\nSELECT * FROM widgets;\n");
	slowlog_test_mock_db('db_fetch_cell_prepared', 'COUNT(*)', 1);
	slowlog_test_mock_db('db_fetch_row_prepared', 'SELECT COUNT(*) AS import_lines', ['import_lines' => 1, 'start_time' => '2024-01-01 00:00:00', 'end_time' => '2024-01-01 00:00:00']);
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', [['logentry' => 1, 'query' => 'SELECT * FROM widgets;', 'oquery' => 'SELECT * FROM widgets;']]);
});
afterEach(function () {
	if (is_file($this->file)) { unlink($this->file); }
	unset($GLOBALS['__test_sql_save_result']);
});

/** @return array The parent status writes made during a scenario. */
function slowlog_outcome_statuses(): array {
	return array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'UPDATE plugin_slowlog') === 0;
	}));
}

it('returns success only after inline post-processing completes', function () {
	expect(import_logfile($this->file, 'test', -1, '', false, false, null, 42))->toBeTrue();
	$statuses = slowlog_outcome_statuses();
	expect(end($statuses)['sql'])->toContain('import_status = 2');
});

it('returns failure and keeps input when a detail batch is rejected', function () {
	slowlog_test_mock_db('db_execute', 'INSERT INTO plugin_slowlog_details', false);
	$ok = import_logfile($this->file, 'test', -1, '', false, false, null, 42);
	expect($ok)->toBeFalse();
	expect(slowlog_finish_file_import($ok, $this->file, true))->toBe(1);
	expect(is_file($this->file))->toBeTrue();
	expect(json_encode(slowlog_outcome_statuses()))->not->toContain('import_status = 2');
});

it('handles a failed parent insert without exiting its caller', function () {
	$GLOBALS['__test_sql_save_result'] = 0;
	ob_start();
	$ok = import_logfile($this->file, 'test', -1, '', false, false);
	$message = ob_get_clean();
	expect($ok)->toBeFalse();
	expect($message)->toContain('Unable to Create Parent Record');
});

it('reports missing or empty files as failures', function ($kind) {
	if ($kind === 'missing') { unlink($this->file); } else { file_put_contents($this->file, 'not a slow log'); }
	set_error_handler(static fn () => true);
	ob_start();
	try { $ok = import_logfile($this->file, 'test', -1, '', false, false, null, 42); } finally { restore_error_handler(); }
	ob_end_clean();
	expect($ok)->toBeFalse();
	$statuses = slowlog_outcome_statuses();
	expect(end($statuses)['sql'])->toContain('import_status = 3');
})->with(['missing', 'empty']);

it('reports failed metadata reads or updates after ingestion', function ($fn, $match) {
	slowlog_test_mock_db($fn, $match, false);
	ob_start();
	$ok = import_logfile($this->file, 'test', -1, '', false, false, null, 42);
	ob_end_clean();
	expect($ok)->toBeFalse();
	expect(is_file($this->file))->toBeTrue();
})->with([
	['db_fetch_row_prepared', 'SELECT COUNT(*) AS import_lines'],
	['db_execute_prepared', 'SET import_lines = ?'],
]);

it('stops failed post-processing before declaring completion', function ($fn, $match, $mode, $names) {
	slowlog_test_mock_db($fn, $match, false);
	ob_start();
	$ok = import_post_process(42, $names, false, $mode);
	ob_end_clean();
	expect($ok)->toBeFalse();
	$statuses = slowlog_outcome_statuses();
	expect(end($statuses)['sql'])->toContain('import_status = 3');
})->with([
	['db_fetch_cell_prepared', 'COUNT(*)', 'all', ''],
	['db_fetch_assoc_prepared', 'SELECT logentry, query', 'all', ''],
	['db_execute_prepared', 'INSERT INTO plugin_slowlog_details_methods', 'all', ''],
	['db_execute_prepared', 'INSERT INTO plugin_slowlog_details_tables', 'all', ''],
	['db_fetch_assoc_prepared', 'SELECT DISTINCT table_name', 'all', ''],
	['db_fetch_assoc_prepared', 'SELECT sldm.id', 'all', ''],
	['db_fetch_assoc_prepared', 'SELECT sldt.tableid', 'all', ''],
	['db_fetch_assoc_prepared', 'SELECT d.logentry', 'all', ''],
	['db_execute_prepared', 'INSERT INTO plugin_slowlog_details_tables', 'list', 'widgets'],
	['db_execute_prepared', 'SET import_text_status = ?', 'list', 'widgets'],
	['db_fetch_cell_prepared', 'SELECT import_tables', 'reference', 'widgets'],
	['db_execute_prepared', 'import_status = 2', 'all', ''],
]);

it('reports exceptions even when recording the failure also throws', function () {
	slowlog_test_mock_db('db_execute', 'INSERT INTO plugin_slowlog_details', new RuntimeException('private SQL values'));
	slowlog_test_mock_db('db_execute_prepared', 'import_status = 3', new RuntimeException('offline'));
	ob_start();
	$ok = import_logfile($this->file, 'test', -1, '', false, false, null, 42);
	$text = ob_get_clean();
	expect($ok)->toBeFalse();
	expect($text)->not->toContain('private SQL values');
});

it('reports a reprocessing reset failure', function ($match) {
	slowlog_test_mock_db('db_execute_prepared', $match, false);
	ob_start();
	$ok = slowlog_reprocess(42);
	ob_end_clean();
	expect($ok)->toBeFalse();
})->with(['DELETE FROM plugin_slowlog_details_methods', 'DELETE FROM plugin_slowlog_details_tables', 'DELETE FROM plugin_slowlog_stats', 'SET timeout = 0', 'SET import_status = 1']);

it('aggregates failures while continuing reprocessing other imports', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logid FROM plugin_slowlog', [['logid' => 1], ['logid' => 2]]);
	slowlog_test_mock_db('db_execute_prepared', function ($sql, $params) {
		return strpos($sql, 'DELETE FROM plugin_slowlog_details_methods') === 0 && $params === [1];
	}, false);
	ob_start();
	$ok = slowlog_reprocess_all();
	ob_end_clean();
	expect($ok)->toBeFalse();
	$statuses = slowlog_outcome_statuses();
	expect(end($statuses)['params'])->toContain(2);
	expect(end($statuses)['sql'])->toContain('import_status = 2');
});

it('returns success for an empty reprocessing list and failure for a read error', function () {
	expect(slowlog_reprocess_all())->toBeTrue();
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logid FROM plugin_slowlog', false);
	ob_start();
	$ok = slowlog_reprocess_all();
	ob_end_clean();
	expect($ok)->toBeFalse();
});

it('deletes input only after successful processing with delete-after enabled', function ($success, $delete, $code, $kept) {
	expect(slowlog_finish_file_import($success, $this->file, $delete))->toBe($code);
	expect(is_file($this->file))->toBe($kept);
})->with([[true, true, 0, false], [true, false, 0, true], [false, true, 1, true], [false, false, 1, true]]);

it('accepts already-removed successful input and reports cleanup failure', function () {
	unlink($this->file);
	expect(slowlog_finish_file_import(true, $this->file, true))->toBe(0);
	mkdir($this->file);
	ob_start();
	set_error_handler(static fn () => true);
	try { $code = slowlog_finish_file_import(true, $this->file, true); } finally { restore_error_handler(); }
	$text = ob_get_clean();
	rmdir($this->file);
	expect($code)->toBe(1);
	expect($text)->toContain('Unable to remove');
});


it('fails imports when the server database or table listing is unavailable', function ($query) {
	slowlog_test_mock_db('db_fetch_assoc', 'SHOW DATABASES', [['Database' => 'app']]);
	slowlog_test_mock_db('db_fetch_assoc', $query, false);
	ob_start();
	$ok = import_logfile($this->file, 'test', -1, '', true, false, null, 42);
	ob_end_clean();
	expect($ok)->toBeFalse();
	expect(is_file($this->file))->toBeTrue();
})->with(['SHOW DATABASES', 'SHOW TABLES']);


it('bounds detail writes by both row count and serialized byte size', function ($count, $query_size, $expected_batches) {
	$entry = "# Time: 240101 00:00:00\nSET timestamp=1704067200;\nSELECT '" . str_repeat('x', $query_size) . "';\n";
	file_put_contents($this->file, str_repeat($entry, $count));
	expect(import_logfile($this->file, 'test', -1, '', false, false, null, 42))->toBeTrue();
	$calls = array_values(array_filter($GLOBALS['__test_db_calls'], fn ($call) => $call['fn'] === 'db_execute' && strpos($call['sql'], 'INSERT INTO plugin_slowlog_details') === 0));
	expect($calls)->toHaveCount($expected_batches);
})->with([[1001, 10, 2], [3, 300000, 3]]);


it('returns failure when recording a rejected detail batch also fails', function () {
	slowlog_test_mock_db('db_execute', 'INSERT INTO plugin_slowlog_details', false);
	slowlog_test_mock_db('db_execute_prepared', 'import_status = 3', new RuntimeException('offline'));
	ob_start();
	try { $ok = import_logfile($this->file, 'test', -1, '', false, false, null, 42); } finally { ob_end_clean(); }
	expect($ok)->toBeFalse();
	expect(is_file($this->file))->toBeTrue();
});


it('loads reserved words and reports a database lookup failure', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT word', [['word' => 'SELECT']]);
	load_reserved_words();
	expect($GLOBALS['reserved_words'])->toBe(['SELECT' => 'SELECT']);
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT word', false);
	expect(fn () => load_reserved_words())->toThrow(RuntimeException::class);
});


it('does not complete an import when caching computed statistics fails', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT sldm.id', [['id' => 1, 'scope_key' => 'SELECTS', 'query_time' => 2, 'rows_sent' => 1, 'rows_examined' => 3, 'rows_affected' => 0, 'bytes_sent' => 20]]);
	slowlog_test_mock_db('db_execute_prepared', 'INSERT INTO plugin_slowlog_stats', false);
	ob_start();
	try { $ok = import_post_process(42); } finally { ob_end_clean(); }
	expect($ok)->toBeFalse();
	$statuses = slowlog_outcome_statuses();
	expect(end($statuses)['sql'])->toContain('import_status = 3');
	expect(json_encode($statuses))->not->toContain('import_status = 2');
});

it('does not complete an import when writing a timeout hint fails', function () {
	slowlog_test_mock_db('db_fetch_assoc_prepared', 'SELECT logentry, query', [['logentry' => 1, 'query' => 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ * FROM widgets', 'oquery' => 'SELECT * FROM widgets']]);
	slowlog_test_mock_db('db_execute_prepared', 'SET timeout = ?', false);
	ob_start();
	try { $ok = import_post_process(42); } finally { ob_end_clean(); }
	expect($ok)->toBeFalse();
	$statuses = slowlog_outcome_statuses();
	expect(end($statuses)['sql'])->toContain('import_status = 3');
});
