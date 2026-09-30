<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for slowlog_get_storage_engine() in includes/database.php:
 * a real MySQL server selects InnoDB, while MariaDB (and an unknown server
 * version) fall back to the MariaDB-only Aria engine.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/database.php');
	slowlog_test_reset_db_mocks();
});

it('uses InnoDB when connected to a real MySQL server', function () {
	slowlog_test_mock_db('db_get_global_variable', 'version', '8.0.36');

	expect(slowlog_get_storage_engine())->toBe('InnoDB');
});

it('falls back to Aria on MariaDB', function () {
	slowlog_test_mock_db('db_get_global_variable', 'version', '11.4.2-MariaDB-log');

	expect(slowlog_get_storage_engine())->toBe('Aria');
});
