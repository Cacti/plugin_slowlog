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

function plugin_slowlog_install(): void {
	api_plugin_register_hook('slowlog', 'config_arrays',         'slowlog_config_arrays',        'setup.php');
	api_plugin_register_hook('slowlog', 'draw_navigation_text',  'slowlog_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('slowlog', 'config_settings',       'slowlog_config_settings',      'setup.php');
	api_plugin_register_hook('slowlog', 'top_header_tabs',       'slowlog_show_tab',             'setup.php');
	api_plugin_register_hook('slowlog', 'top_graph_header_tabs', 'slowlog_show_tab',             'setup.php');

	api_plugin_register_realm('slowlog', 'slowlog.php', 'Plugin -> MySQL Slow Log Viewer', 1);

	slowlog_setup_table_new();
}

/**
 * @return array<string, mixed>
 */
function slowlog_version(): array {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/slowlog/INFO', true);

	if ($info === false || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	return $info['info'];
}

function plugin_slowlog_uninstall(): void {
	api_plugin_drop_table('plugin_slowlog');
	api_plugin_drop_table('plugin_slowlog_details');
	api_plugin_drop_table('plugin_slowlog_details_methods');
	api_plugin_drop_table('plugin_slowlog_details_tables');
	api_plugin_drop_table('plugin_slowlog_methods');
	api_plugin_drop_table('plugin_slowlog_tables');
	api_plugin_drop_table('plugin_slowlog_table_names');
	api_plugin_drop_table('plugin_slowlog_reserved_words');
	api_plugin_drop_table('plugin_slowlog_stats');
}

function plugin_slowlog_check_config(): bool {
	/* Here we will check to ensure everything is configured */
	slowlog_check_upgrade();
	return true;
}

function plugin_slowlog_upgrade(): bool {
	/* Here we will upgrade to the newest version */
	slowlog_check_upgrade();
	return false;
}

/**
 * @return array<string, mixed>
 */
function plugin_slowlog_version(): array {
	return slowlog_version();
}

function slowlog_check_upgrade(): void {
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');
	include_once($config['library_path'] . '/functions.php');

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'slowlog.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$info = slowlog_version();

	// The rest of this function indexes 'version'/'longname'/etc. directly - bail out rather
	// than risk undefined-key warnings and writing null metadata into plugin_config if the
	// INFO file is ever unreadable/malformed or missing any of these keys.
	if (!isset($info['version'], $info['longname'], $info['author'], $info['homepage'], $info['name'])) {
		return;
	}

	$current = $info['version'];
	$old     = db_fetch_cell_prepared('SELECT version
		FROM plugin_config
		WHERE directory = ?',
		array('slowlog'));

	if ($current != $old) {
		db_execute_prepared('UPDATE plugin_config
			SET version = ?
			WHERE directory = ?',
			array($current, 'slowlog'));
		db_execute_prepared('UPDATE plugin_config
			SET version = ?,
			name = ?,
			author = ?,
			webpage = ?
			WHERE directory = ?',
			array($info['version'], $info['longname'], $info['author'], $info['homepage'], $info['name']));

		db_execute('DELETE FROM plugin_hooks WHERE function="slowlog_page_head"');

		// api_plugin_db_table_create() (used by slowlog_setup_table_new()) only creates a
		// table when it doesn't already exist - it never retrofits schema changes (like these
		// new composite indexes) onto one that does. Detect that case before re-running it.
		$details_table_existed = db_table_exists('plugin_slowlog_details');

		// Re-running the table/column API calls is safe - they're no-ops when already applied.
		slowlog_setup_table_new();

		// For a pre-existing plugin_slowlog_details table, db_update_table() diffs the current
		// schema against this definition and issues the exact ALTER TABLE needed (columns and
		// keys alike) in one statement - a fresh install already got the current schema,
		// including these keys, from the create path above.
		if ($details_table_existed) {
			db_update_table('plugin_slowlog_details', slowlog_details_table_data());
		}
	}
}

function slowlog_check_dependencies(): bool {
	global $plugins, $config;
	return true;
}

/**
 * Aria isn't available on plain MySQL (it's a MariaDB-only storage engine), so
 * default to Aria everywhere and only fall back to InnoDB when the connected
 * server is detected as real MySQL rather than MariaDB.
 */
function slowlog_get_storage_engine(): string {
	$version = db_get_global_variable('version');

	if ($version !== false && stripos($version, 'MariaDB') === false) {
		return 'InnoDB';
	}

	return 'Aria';
}

/**
 * The plugin_slowlog_details schema, shared by the create path
 * (api_plugin_db_table_create() in slowlog_setup_table_new()) and the
 * upgrade path (db_update_table() in slowlog_check_upgrade()), so both stay
 * in sync from a single definition.
 *
 * @return array<string, mixed>
 */
function slowlog_details_table_data(): array {
	$data = array();
	$data['columns'][] = array('name' => 'logentry', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'date', 'type' => 'timestamp', 'NULL' => false, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP');
	$data['columns'][] = array('name' => 'user', 'type' => 'varchar(20)', 'NULL' => false);
	$data['columns'][] = array('name' => 'host', 'type' => 'varchar(255)', 'NULL' => false);
	$data['columns'][] = array('name' => 'ip_address', 'type' => 'varchar(15)', 'NULL' => false);
	$data['columns'][] = array('name' => 'query_time', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'lock_time', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'thread_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'schema', 'type' => 'varchar(20)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'qc_hit', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'rows_sent', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'rows_examined', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'rows_affected', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'bytes_sent', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'oquery', 'type' => 'mediumtext', 'NULL' => false);
	$data['columns'][] = array('name' => 'query', 'type' => 'mediumtext', 'NULL' => false);
	$data['columns'][] = array('name' => 'timeout', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'The timeout value detected in the query, if any');
	// db_update_table()'s existing-primary-key diff path (lib/database.php) calls
	// array_diff($data['primary'], ...) directly without normalizing a string to an array
	// first, unlike db_table_create() - so this must be an array even for a single column.
	$data['primary']    = array('logentry');
	$data['keys'][]     = array('name' => 'logid', 'columns' => array('logid'));
	$data['keys'][]     = array('name' => 'user', 'columns' => array('user'));
	$data['keys'][]     = array('name' => 'host', 'columns' => array('host'));
	// Sorting the details list by one of these metrics always also filters on logid
	// (a specific imported log), so a standalone index on the metric alone wouldn't be
	// usable alongside that filter - prefix each with logid so the sort can be satisfied
	// by the index instead of a filesort.
	$data['keys'][]     = array('name' => 'logid_query_time', 'columns' => array('logid', 'query_time'));
	$data['keys'][]     = array('name' => 'logid_lock_time', 'columns' => array('logid', 'lock_time'));
	$data['keys'][]     = array('name' => 'logid_rows_sent', 'columns' => array('logid', 'rows_sent'));
	$data['keys'][]     = array('name' => 'logid_rows_examined', 'columns' => array('logid', 'rows_examined'));
	$data['keys'][]     = array('name' => 'logid_rows_affected', 'columns' => array('logid', 'rows_affected'));
	$data['keys'][]     = array('name' => 'logid_bytes_sent', 'columns' => array('logid', 'bytes_sent'));
	$engine             = slowlog_get_storage_engine();
	$data['type']       = $engine;
	$data['row_format'] = ($engine === 'Aria') ? 'Page' : 'Dynamic';
	$data['comment']    = 'Provides statistics on your slow query log';

	return $data;
}

function slowlog_setup_table_new(): void {
	$data = array();
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true, 'comment' => 'The unique id for this log entry');
	$data['columns'][] = array('name' => 'description', 'type' => 'varchar(128)', 'NULL' => false, 'default' => '', 'comment' => 'The description for the slow log');
	$data['columns'][] = array('name' => 'import_date', 'type' => 'timestamp', 'NULL' => false, 'default' => 'CURRENT_TIMESTAMP', 'comment' => 'The date the log was uploaded');
	$data['columns'][] = array('name' => 'import_lines', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0, 'comment' => 'The number of lines in the log');
	$data['columns'][] = array('name' => 'import_status', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0, 'comment' => 'The status of the import process');
	$data['columns'][] = array('name' => 'import_text_status', 'type' => 'varchar(40)', 'NULL' => false, 'default' => '', 'comment' => 'The text status of the import process');
	$data['columns'][] = array('name' => 'import_tables', 'type' => 'text', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'start_time', 'type' => 'timestamp', 'NULL' => false, 'default' => '0000-00-00 00:00:00', 'comment' => 'The start time for the log');
	$data['columns'][] = array('name' => 'end_time', 'type' => 'timestamp', 'NULL' => false, 'default' => '0000-00-00 00:00:00', 'comment' => 'The end time for the log');
	$data['primary']    = 'logid';
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';
	$data['comment']    = 'Each Slow Log Can be Tracked';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog', $data);

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details', slowlog_details_table_data());

	// New column since 2.1 - re-issuing this on every upgrade is safe, it's a no-op once applied.
	api_plugin_db_add_column('slowlog', 'plugin_slowlog_details', array('name' => 'timeout', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'The timeout value detected in the query, if any', 'after' => 'query'));

	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'logentry', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'methodid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['primary']    = array('logid', 'logentry', 'methodid');
	$data['keys'][]     = array('name' => 'id', 'columns' => array('id'));
	$engine             = slowlog_get_storage_engine();
	$data['type']       = $engine;
	$data['row_format'] = ($engine === 'Aria') ? 'Page' : 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details_methods', $data);

	$data = array();
	$data['columns'][] = array('name' => 'tableid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'logentry', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false);
	$data['primary']    = array('logid', 'logentry', 'table_name');
	$data['keys'][]     = array('name' => 'tableid', 'columns' => array('tableid'));
	$engine             = slowlog_get_storage_engine();
	$data['type']       = $engine;
	$data['row_format'] = ($engine === 'Aria') ? 'Page' : 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details_tables', $data);

	$data = array();
	$data['columns'][] = array('name' => 'method', 'type' => 'varchar(45)', 'NULL' => false);
	// wide enough for the longest comma-separated seed fragment list below (ANALYZES/OPTIMIZES)
	$data['columns'][] = array('name' => 'query', 'type' => 'varchar(96)', 'NULL' => false);
	$data['columns'][] = array('name' => 'methodid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['primary']    = array('method', 'query');
	$data['keys'][]     = array('name' => 'methodid', 'columns' => array('methodid'));
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_methods', $data);

	// Existing installs may still have the pre-2.4 varchar(45) width; widen it before
	// inserting the longer ANALYZES/OPTIMIZES/CREATES seed fragments.
	db_execute('ALTER TABLE plugin_slowlog_methods MODIFY COLUMN `query` varchar(96) NOT NULL');

	$data = array();
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false);
	$data['primary']    = array('logid', 'table_name');
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_tables', $data);

	// Schema groundwork: dictionary of every distinct table name seen across imports, plus
	// whether it's a known Cacti table. Per-logentry table associations are still written
	// directly to plugin_slowlog_tables/plugin_slowlog_details_tables by import_post_process();
	// this table only caches the is_cacti_table lookup so OTHER TABLES classification doesn't
	// re-derive it per logentry (see slowlog_sync_table_dictionary()/slowlog_classify_other_tables()).
	$data = array();
	$data['columns'][] = array('name' => 'tableid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'is_cacti_table', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => 0);
	$data['primary']     = 'tableid';
	$data['unique_keys'][] = array('name' => 'table_name', 'columns' => array('table_name'));
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';
	$data['comment']    = 'Dictionary of table names seen in imported slow query logs';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_table_names', $data);

	db_execute('INSERT IGNORE INTO `plugin_slowlog_methods` VALUES
		(\'INSERTS\',\'INSERT INTO,INSERT IGNORE INTO\',1),
		(\'REPLACES\',\'REPLACE INTO,REPLACE IGNORE INTO\',2),
		(\'DELETES\',\'DELETE \',3),
		(\'SELECTS\',\'SELECT \',4),
		(\'DISTINCTS\',\'SELECT DISTINCT\',5),
		(\'UNIONS\',\'UNION\',6),
		(\'JOINS\',\'JOIN \',7),
		(\'OTHERS\',\'OTHERS\',8),
		(\'UPDATES\',\'UPDATE \',9),
		(\'RENAMES\', \'RENAME TABLE\', 10),
		(\'FLUSHES\', \'FLUSH TABLE\', 11),
		(\'TRUNCATES\', \'TRUNCATE \', 12),
		(\'LOAD DATA\', \'LOAD DATA INFILE \', 13),
		(\'OUTFILES\', \'INTO OUTFILE \', 14),
		(\'INFILES\', \'INFILE \', 15),
		(\'GROUP BY\', \'GROUP BY \', 16),
		(\'COUNTS\', \'COUNT(\', 17),
		(\'SHOWS\', \'SHOW \', 18),
		(\'UNION ALLS\', \'UNION ALL\', 19),
		(\'MAX_EXECUTION_TIME\', \'MAX_EXECUTION_TIME(\', 20),
		(\'MAX_STATEMENT_TIME\', \'MAX_STATEMENT_TIME\', 21),
		(\'OTHER TABLES\', \'OTHER TABLES\', 22),
		(\'FORCE INDEX\', \'FORCE INDEX\', 23),
		(\'ALTERS\', \'ALTER TABLE\', 24),
		(\'DROPS\', \'DROP TABLE,DROP TEMPORARY TABLE\', 25),
		(\'ANALYZES\', \'ANALYZE TABLE,ANALYZE NO_WRITE_TO_BINLOG TABLE,ANALYZE LOCAL TABLE\', 26),
		(\'OPTIMIZES\', \'OPTIMIZE TABLE,OPTIMIZE NO_WRITE_TO_BINLOG TABLE,OPTIMIZE LOCAL TABLE\', 27),
		(\'CREATES\', \'create table\', 28),
		(\'CREATE TEMPS\', \'create temporary table\', 29)');

	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'word', 'type' => 'varchar(30)', 'NULL' => false);
	$data['primary']    = array('id', 'word');
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_reserved_words', $data);

	// The (id, word) primary key doesn't prevent duplicate words on a re-run, since id is
	// auto-incrementing - only load once, when the table is still empty.
	if (file_exists(__DIR__ . '/keywords.txt') && !db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_reserved_words')) {
		$words = file(__DIR__ . '/keywords.txt') ?: array();

		if (cacti_sizeof($words)) {
			foreach($words as $word) {
				db_execute_prepared('INSERT INTO plugin_slowlog_reserved_words
					(word)
					VALUES (?)',
					array(trim($word)));
			}
		}
	}

	$data = array();
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'comment' => 'The logid this cached summary belongs to');
	$data['columns'][] = array('name' => 'scope', 'type' => 'varchar(10)', 'NULL' => false, 'comment' => 'Whether scope_key is a method name (method) or a table name (table)');
	$data['columns'][] = array('name' => 'scope_key', 'type' => 'varchar(45)', 'NULL' => false, 'comment' => 'plugin_slowlog_methods.method value, or a table_name (or others)');
	$data['columns'][] = array('name' => 'metric', 'type' => 'varchar(20)', 'NULL' => false, 'comment' => 'query_time, rows_sent, rows_examined, rows_affected, or bytes_sent');
	$data['columns'][] = array('name' => 'sample_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0, 'comment' => 'Number of detail rows this summary was computed from');
	$data['columns'][] = array('name' => 'total_value', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'SUM across all matching rows');
	$data['columns'][] = array('name' => 'min_value', 'type' => 'double', 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'p25_value', 'type' => 'double', 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'median_value', 'type' => 'double', 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'p75_value', 'type' => 'double', 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'p95_value', 'type' => 'double', 'NULL' => false, 'default' => 0);
	$data['columns'][] = array('name' => 'max_value', 'type' => 'double', 'NULL' => false, 'default' => 0);
	$data['primary']    = array('logid', 'scope', 'scope_key', 'metric');
	$data['keys'][]     = array('name' => 'scope_metric', 'columns' => array('scope', 'metric'));
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';
	$data['comment']    = 'Cached box-whisker (min/p25/median/p75/p95/max) + totals per method/table, per imported log';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_stats', $data);
}

function slowlog_config_arrays(): void {
	slowlog_check_upgrade();
}

function slowlog_config_settings(): void {
	global $tabs, $settings;

	$tabs['misc'] = 'Misc';

	$temp = array(
	);

	if (isset($settings['misc'])) {
		$settings['misc'] = array_merge($settings['misc'], $temp);
	} else {
		$settings['misc'] = $temp;
	}
}

/**
 * @param array<string, array<string, mixed>> $nav
 *
 * @return array<string, array<string, mixed>>
 */
function slowlog_draw_navigation_text(array $nav): array {
	$nav['slowlog.php:']        = array('title' => 'MySQL Slowlog Viewer', 'mapping' => '', 'url' => 'slowlog.php', 'level' => '0');
	$nav['slowlog.php:edit']    = array('title' => 'MySQL Slowlog Import', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0');
	$nav['slowlog.php:actions'] = array('title' => 'MySQL Slowlog Delete', 'mapping' => '', 'url' => 'slowlog.php', 'level' => '0');
	$nav['slowlog.php:select']  = array('title' => 'MySQL Slowlog Viewer', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0');
	$nav['slowlog.php:methods'] = array('title' => 'MySQL Slowlog Methods', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0');
	$nav['slowlog.php:tables']  = array('title' => 'MySQL Slowlog Tables', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0');
	$nav['slowlog.php:details'] = array('title' => 'MySQL Slowlog Details', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0');
	$nav['slowlog.php:query']   = array('title' => 'MySQL Slowlog Query Details', 'mapping' => '', 'url' => 'slowlog.php:', 'level' => '0');

	return $nav;
}

function slowlog_show_tab(): void {
	global $config;

	if (!isset($_SESSION['sess_slowlog_level'])) {
		$perms = db_fetch_cell_prepared('SELECT id
			FROM plugin_realms
			WHERE file LIKE ?',
			array('%slowlog.php%')) + 100;

		$level = db_fetch_assoc_prepared('SELECT realm_id
            FROM user_auth_realm
            WHERE user_id = ?
            AND realm_id = ?',
			array($_SESSION['sess_user_id'], $perms));

		if (cacti_sizeof($level)) {
			$_SESSION['sess_slowlog_level'] = true;
		} else {
			$_SESSION['sess_slowlog_level'] = false;
		}
	}

	if ($_SESSION['sess_slowlog_level']) {
		if (substr_count($_SERVER['REQUEST_URI'], 'slowlog')) {
			print '<a href="' . $config['url_path'] . 'plugins/slowlog/slowlog.php"><img src="' . $config['url_path'] . 'plugins/slowlog/images/tab_slowlog_down.gif" alt="SlowLog"></a>';
		} else {
			print '<a href="' . $config['url_path'] . 'plugins/slowlog/slowlog.php"><img src="' . $config['url_path'] . 'plugins/slowlog/images/tab_slowlog.gif" alt="SlowLog"></a>';
		}
	}
}
