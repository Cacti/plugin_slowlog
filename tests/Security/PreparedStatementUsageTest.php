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

describe('prepared statement usage in slowlog', function () {
	$setup    = file_get_contents(realpath(__DIR__ . '/../../setup.php'));
	$slowlog  = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));
	$helpers  = file_get_contents(realpath(__DIR__ . '/../../slowlog_functions.php'));

	it('reads setup.php, slowlog.php, and slowlog_functions.php', function () use ($setup, $slowlog, $helpers) {
		expect($setup)->not->toBeFalse();
		expect($slowlog)->not->toBeFalse();
		expect($helpers)->not->toBeFalse();
	});

	it('uses a prepared version lookup in setup.php', function () use ($setup) {
		expect(preg_match('/db_fetch_cell_prepared\s*\(\s*\'SELECT version/s', $setup))->toBe(1);
	});

	it('uses prepared plugin_config updates in setup.php', function () use ($setup) {
		expect(preg_match('/db_execute_prepared\s*\(\s*\'UPDATE plugin_config/s', $setup))->toBeGreaterThanOrEqual(1);
	});

	it('uses a prepared realm lookup in setup.php', function () use ($setup) {
		expect(preg_match('/db_fetch_cell_prepared\s*\(\s*\'SELECT id\s+FROM plugin_realms/s', $setup))->toBe(1);
	});

	it('has no raw db_fetch_assoc calls in slowlog.php', function () use ($slowlog) {
		expect(preg_match('/\bdb_fetch_assoc\s*\(/', $slowlog))->toBe(0);
	});

	it('uses prepared db_fetch_assoc calls in slowlog.php', function () use ($slowlog) {
		expect(preg_match_all('/\bdb_fetch_assoc_prepared\s*\(/', $slowlog))->toBeGreaterThanOrEqual(5);
	});

	it('removed raw table insert interpolation in slowlog_functions.php', function () use ($helpers) {
		expect($helpers)->not->toContain("VALUES (\$logid, '\$t')");
	});

	it('removed raw details table interpolation in slowlog_functions.php', function () use ($helpers) {
		expect($helpers)->not->toContain('WHERE logid=$logid');
	});

	it('parameterizes methodid insert values in slowlog_functions.php', function () use ($helpers) {
		expect($helpers)->not->toContain("SELECT '\$logid' AS logid, logentry");
	});

	it('uses a prepared insert for plugin_slowlog_tables', function () use ($helpers) {
		expect(preg_match('/db_execute_prepared\s*\(\s*\'INSERT INTO plugin_slowlog_tables/s', $helpers))->toBe(1);
	});

	it('uses a prepared insert for plugin_slowlog_details_tables', function () use ($helpers) {
		expect(preg_match('/db_execute_prepared\s*\(\s*\'INSERT INTO plugin_slowlog_details_tables/s', $helpers))->toBe(1);
	});

	it('limits raw db_execute calls to parser replay paths', function () use ($helpers) {
		expect(preg_match_all('/\bdb_execute\s*\(/', $helpers))->toBe(3);
		expect(preg_match_all('/db_execute\s*\(\s*\$sql_prefix\s*\./', $helpers))->toBe(3);
	});

	it('limits raw db_fetch_assoc calls to schema discovery helpers', function () use ($helpers) {
		expect(preg_match_all('/\bdb_fetch_assoc\s*\(/', $helpers))->toBe(2);
	});
});
