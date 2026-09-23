<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for slowlog_check_upgrade() in setup.php: the page-guard,
 * the malformed-INFO bail-out, the no-drift branch, and the version-drift
 * branch (both plugin_config UPDATEs, the stale-hook cleanup DELETE, and
 * re-running the table/column setup - including db_update_table() for a
 * pre-existing plugin_slowlog_details table).
 *
 * It include_once()s $config['library_path'] . '/database.php' and
 * '/functions.php', so that is pointed at a throwaway directory containing
 * empty stub files for the duration of these tests: library_path (unlike
 * base_path/lib) is fully test-controlled.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';

	$stubLibraryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slowlog-test-lib-stub';

	if (!is_dir($stubLibraryPath)) {
		mkdir($stubLibraryPath, 0777, true);
	}

	file_put_contents($stubLibraryPath . '/database.php', "<?php\n");
	file_put_contents($stubLibraryPath . '/functions.php', "<?php\n");

	$GLOBALS['config']['library_path'] = $stubLibraryPath;
});

beforeEach(function () {
	slowlog_test_reset_db_mocks();
	unset($_SERVER['PHP_SELF']);
});

it('does nothing on a page that does not need the version check', function () {
	$_SERVER['PHP_SELF'] = '/cacti/graphs.php';

	slowlog_check_upgrade();

	expect($GLOBALS['__test_db_calls'])->toBeEmpty();
});

it('bails out without touching the database when the INFO file is malformed', function () {
        $stubBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slowlog-test-bad-info';

        if (!is_dir($stubBasePath . '/plugins/slowlog')) {
                mkdir($stubBasePath . '/plugins/slowlog', 0777, true);
        }

        // Missing required keys (longname/author/homepage/name) so
        // slowlog_version() returns an incomplete array.
        file_put_contents($stubBasePath . '/plugins/slowlog/INFO', "[info]\nversion = 1.0\n");

        $originalBasePath = $GLOBALS['config']['base_path'];
        $GLOBALS['config']['base_path'] = $stubBasePath;

        $_SERVER['PHP_SELF'] = '/cacti/plugins.php';

        try {
                slowlog_check_upgrade();
        } finally {
                $GLOBALS['config']['base_path'] = $originalBasePath;
        }

        expect($GLOBALS['__test_db_calls'])->toBeEmpty();
});

it('does nothing further when the stored version already matches', function () {
	$info = slowlog_version();

	$_SERVER['PHP_SELF'] = '/cacti/plugins.php';
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_config', $info['version']);

	slowlog_check_upgrade();

	$writes = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return in_array($call['fn'], array('db_execute', 'db_execute_prepared'), true);
	});

	expect($writes)->toBeEmpty();
});

it('updates plugin_config twice, cleans up the stale hook, and re-runs table setup on drift', function () {
	$info = slowlog_version();

	$_SERVER['PHP_SELF'] = '/cacti/plugins.php';
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_config', '0.0.0');
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details', false);

	slowlog_check_upgrade();

	$updates = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute_prepared' && stripos($call['sql'], 'UPDATE plugin_config') !== false;
	});

	expect($updates)->toHaveCount(2);

	$hookCleanup = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_execute' && stripos($call['sql'], 'DELETE FROM plugin_hooks') !== false;
	});

	expect($hookCleanup)->toHaveCount(1);

	$tableCreates = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'api_plugin_db_table_create';
	});

	expect($tableCreates)->not->toBeEmpty();

	// plugin_slowlog_details did NOT already exist, so the ALTER-diff path
	// (db_update_table()) must not run - the CREATE path already applied
	// the current schema.
	$alters = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_update_table';
	});

	expect($alters)->toBeEmpty();
});

it('re-applies the schema diff via db_update_table() when plugin_slowlog_details already existed', function () {
	$_SERVER['PHP_SELF'] = '/cacti/plugins.php';
	slowlog_test_mock_db('db_fetch_cell_prepared', 'plugin_config', '0.0.0');
	slowlog_test_mock_db('db_table_exists', 'plugin_slowlog_details', true);

	slowlog_check_upgrade();

	$alters = array_filter($GLOBALS['__test_db_calls'], function ($call) {
		return $call['fn'] === 'db_update_table' && $call['sql'] === 'plugin_slowlog_details';
	});

	expect($alters)->toHaveCount(1);
});
