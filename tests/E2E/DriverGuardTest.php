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
 * tests/ ships inside the plugin directory, so the E2E drivers must do nothing when a web server
 * runs them or when they are started without the test environment. These tests run without Cacti
 * or a database and are never skipped.
 */

/**
 * Runs a driver with the command line binary and the given environment, returning its output.
 *
 * @param string               $driver File name in tests/E2E.
 * @param array<string,string> $env    Environment for the child process.
 */
function slowlog_e2e_run_driver(string $driver, array $env): string {
	$process = proc_open(
		[PHP_BINARY, __DIR__ . '/' . $driver],
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		null,
		$env + ['PATH' => (string) getenv('PATH')]
	);

	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	return $output;
}

foreach (array('import_flow.php', 'delete_flow.php') as $driver) {
	it("$driver prints nothing without the test environment", function () use ($driver) {
		expect(slowlog_e2e_run_driver($driver, array()))->toBe('');
	});

	it("$driver prints nothing when the temp directory is not provided", function () use ($driver) {
		expect(slowlog_e2e_run_driver($driver, array('SLOWLOG_E2E' => '1')))->toBe('');
	});

	it("$driver prints nothing when the temp directory does not exist", function () use ($driver) {
		expect(slowlog_e2e_run_driver($driver, array('SLOWLOG_E2E' => '1', 'SLOWLOG_E2E_TMP' => '/nonexistent/slowlog-e2e')))->toBe('');
	});

	it("$driver refuses to run for a web server", function () use ($driver) {
		$source = file_get_contents(__DIR__ . '/' . $driver);

		expect($source)->toContain("if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1'");
		expect($source)->toContain('http_response_code(404);');
	});
}

it('shared helper file defines functions only and ends a web request with a 404', function () {
	$source = file_get_contents(__DIR__ . '/e2e_common.php');

	expect($source)->toContain("if (PHP_SAPI !== 'cli') {");
	expect($source)->toContain('http_response_code(404);');
	expect(slowlog_e2e_run_driver('e2e_common.php', array()))->toBe('');
});

it('keeps the E2E fixtures free of PHP code', function () {
	foreach (glob(__DIR__ . '/fixtures/*') as $fixture) {
		expect(pathinfo($fixture, PATHINFO_EXTENSION))->not->toBe('php');
	}
});
