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
 * Covers import_logfile()'s read-error handling. Streaming the log with
 * fgets() can't tell EOF from an I/O error - both return false - so a
 * mid-file read failure must not be mistaken for a clean end-of-log and
 * finalized as a successful (but truncated) import.
 *
 * SlowlogReadErrorStream simulates exactly that: fopen() succeeds, the first
 * fgets() returns false, and feof() stays false (as it would on a real read
 * error), so import_logfile() must take its failure path.
 */

uses(TestCase::class);

if (!class_exists('SlowlogReadErrorStream')) {
	class SlowlogReadErrorStream {
		/** @var resource|null */
		public $context;

		public function stream_open($path, $mode, $options, &$opened_path) {
			return true;
		}

		public function stream_read($count) {
			// A failed read, not EOF: false (not '') keeps feof() false.
			return false;
		}

		public function stream_eof() {
			return false;
		}

		public function stream_stat() {
			return array('size' => 1);
		}

		public function url_stat($path, $flags) {
			return array('size' => 1);
		}

		public function stream_close() {
		}
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('includes/slowlog_functions.php');

	if (in_array('slowlogfail', stream_get_wrappers(), true)) {
		stream_wrapper_unregister('slowlogfail');
	}

	stream_wrapper_register('slowlogfail', 'SlowlogReadErrorStream');
});

afterEach(function () {
	if (in_array('slowlogfail', stream_get_wrappers(), true)) {
		stream_wrapper_unregister('slowlogfail');
	}
});

it('marks the parent record as failed (status 3) when the log read errors mid-stream', function () {
	// 4242 is a pre-created parent logid, as the web upload handler supplies.
	import_logfile('slowlogfail://log', 'test', -1, '', false, false, null, 4242);

	$failed = null;

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		if ($call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'import_status = 3') !== false) {
			$failed = $call;
		}
	}

	expect($failed)->not->toBeNull();
	expect($failed['params'])->toContain(4242);
});

it('does not finalize the import as successful (status 1) when the log read errors mid-stream', function () {
	import_logfile('slowlogfail://log', 'test', -1, '', false, false, null, 4242);

	foreach ($GLOBALS['__test_db_calls'] as $call) {
		expect($call['sql'])->not->toContain('import_status = 1');
	}
});

it('prints a FATAL read error for a CLI import with no pre-created parent record', function () {
	ob_start();
	import_logfile('slowlogfail://log', 'test', -1, '', false, false, null, null);
	$output = ob_get_clean();

	expect($output)->toContain('FATAL: Read error');

	// With no parent logid there is nothing to mark as failed - it must not
	// have recorded a status-3 update either.
	foreach ($GLOBALS['__test_db_calls'] as $call) {
		expect($call['sql'])->not->toContain('import_status = 3');
	}
});
