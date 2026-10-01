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

/**
 * Aria isn't available on plain MySQL (it's a MariaDB-only storage engine), so
 * default to Aria everywhere and only fall back to InnoDB when the connected
 * server is detected as real MySQL rather than MariaDB.
 *
 * Determines the appropriate storage engine to use for this plugin's
 * high-write detail/summary tables. Called from
 * slowlog_details_table_data() and slowlog_setup_table_new() when
 * defining those tables.
 *
 * @return string 'InnoDB' when connected to real MySQL, otherwise
 *                'Aria'.
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
 * upgrade path (db_update_table() in slowlog_upgrade_tables()), so both stay
 * in sync from a single definition.
 *
 * Builds the plugin_slowlog_details table definition (one row per
 * imported slow-query log entry, with per-metric composite indexes for
 * efficient sorted/filtered listing). Called from
 * slowlog_setup_table_new() to create the table, and from
 * slowlog_upgrade_tables() to migrate an existing table to the current
 * schema.
 *
 * @return array<string, mixed> The table definition array consumed by
 *                              api_plugin_db_table_create()/
 *                              db_update_table().
 */
function slowlog_details_table_data(): array {
	$data              = [];
	$data['columns'][] = ['name' => 'logentry', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true];
	$data['columns'][] = ['name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][] = ['name' => 'date', 'type' => 'timestamp', 'NULL' => false, 'default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'];
	$data['columns'][] = ['name' => 'user', 'type' => 'varchar(20)', 'NULL' => false];
	$data['columns'][] = ['name' => 'host', 'type' => 'varchar(255)', 'NULL' => false];
	$data['columns'][] = ['name' => 'ip_address', 'type' => 'varchar(15)', 'NULL' => false];
	$data['columns'][] = ['name' => 'query_time', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][] = ['name' => 'lock_time', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][] = ['name' => 'thread_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => 0];
	$data['columns'][] = ['name' => 'schema', 'type' => 'varchar(20)', 'NULL' => false, 'default' => ''];
	$data['columns'][] = ['name' => 'qc_hit', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0];
	$data['columns'][] = ['name' => 'rows_sent', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][] = ['name' => 'rows_examined', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][] = ['name' => 'rows_affected', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0];
	$data['columns'][] = ['name' => 'bytes_sent', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => 0];
	$data['columns'][] = ['name' => 'oquery', 'type' => 'mediumtext', 'NULL' => false];
	$data['columns'][] = ['name' => 'query', 'type' => 'mediumtext', 'NULL' => false];
	$data['columns'][] = ['name' => 'timeout', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'The timeout value detected in the query, if any'];
	// db_update_table()'s existing-primary-key diff path (lib/database.php) calls
	// array_diff($data['primary'], ...) directly without normalizing a string to an array
	// first, unlike db_table_create() - so this must be an array even for a single column.
	$data['primary']    = ['logentry'];
	$data['keys'][]     = ['name' => 'logid', 'columns' => ['logid']];
	$data['keys'][]     = ['name' => 'user', 'columns' => ['user']];
	$data['keys'][]     = ['name' => 'host', 'columns' => ['host']];
	// Sorting the details list by one of these metrics always also filters on logid
	// (a specific imported log), so a standalone index on the metric alone wouldn't be
	// usable alongside that filter - prefix each with logid so the sort can be satisfied
	// by the index instead of a filesort.
	$data['keys'][]     = ['name' => 'logid_query_time', 'columns' => ['logid', 'query_time']];
	$data['keys'][]     = ['name' => 'logid_lock_time', 'columns' => ['logid', 'lock_time']];
	$data['keys'][]     = ['name' => 'logid_rows_sent', 'columns' => ['logid', 'rows_sent']];
	$data['keys'][]     = ['name' => 'logid_rows_examined', 'columns' => ['logid', 'rows_examined']];
	$data['keys'][]     = ['name' => 'logid_rows_affected', 'columns' => ['logid', 'rows_affected']];
	$data['keys'][]     = ['name' => 'logid_bytes_sent', 'columns' => ['logid', 'bytes_sent']];
	$engine             = slowlog_get_storage_engine();
	$data['type']       = $engine;
	$data['row_format'] = ($engine === 'Aria') ? 'Page' : 'Dynamic';
	$data['comment']    = 'Provides statistics on your slow query log';

	return $data;
}

/**
 * Creates all of this plugin's database tables (log imports, detail
 * rows and their per-method/per-table associations, the method/table-
 * name/reserved-word reference dictionaries, and cached summary
 * statistics), and seeds the method and reserved-word reference tables
 * with their built-in data. Called from plugin_slowlog_install() during
 * plugin installation, and re-run (safely, as a no-op for already-
 * applied changes) from slowlog_upgrade_tables() during upgrades.
 *
 * @return void
 */
function slowlog_setup_table_new(): void {
	$data               = [];
	$data['columns'][]  = ['name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true, 'comment' => 'The unique id for this log entry'];
	$data['columns'][]  = ['name' => 'description', 'type' => 'varchar(128)', 'NULL' => false, 'default' => '', 'comment' => 'The description for the slow log'];
	$data['columns'][]  = ['name' => 'import_date', 'type' => 'timestamp', 'NULL' => false, 'default' => 'CURRENT_TIMESTAMP', 'comment' => 'The date the log was uploaded'];
	$data['columns'][]  = ['name' => 'import_lines', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0, 'comment' => 'The number of lines in the log'];
	$data['columns'][]  = ['name' => 'import_status', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0, 'comment' => 'The status of the import process'];
	$data['columns'][]  = ['name' => 'import_text_status', 'type' => 'varchar(40)', 'NULL' => false, 'default' => '', 'comment' => 'The text status of the import process'];
	$data['columns'][]  = ['name' => 'import_tables', 'type' => 'text', 'NULL' => false, 'default' => ''];
	$data['columns'][]  = ['name' => 'start_time', 'type' => 'timestamp', 'NULL' => false, 'default' => '0000-00-00 00:00:00', 'comment' => 'The start time for the log'];
	$data['columns'][]  = ['name' => 'end_time', 'type' => 'timestamp', 'NULL' => false, 'default' => '0000-00-00 00:00:00', 'comment' => 'The end time for the log'];
	$data['primary']    = 'logid';
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';
	$data['comment']    = 'Each Slow Log Can be Tracked';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog', $data);

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details', slowlog_details_table_data());

	// New column since 2.1 - re-issuing this on every upgrade is safe, it's a no-op once applied.
	api_plugin_db_add_column('slowlog', 'plugin_slowlog_details', ['name' => 'timeout', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'The timeout value detected in the query, if any', 'after' => 'query']);

	$data               = [];
	$data['columns'][]  = ['name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true];
	$data['columns'][]  = ['name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][]  = ['name' => 'logentry', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][]  = ['name' => 'methodid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['primary']    = ['logid', 'logentry', 'methodid'];
	$data['keys'][]     = ['name' => 'id', 'columns' => ['id']];
	$engine             = slowlog_get_storage_engine();
	$data['type']       = $engine;
	$data['row_format'] = ($engine === 'Aria') ? 'Page' : 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details_methods', $data);

	$data               = [];
	$data['columns'][]  = ['name' => 'tableid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true];
	$data['columns'][]  = ['name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][]  = ['name' => 'logentry', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][]  = ['name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false];
	$data['primary']    = ['logid', 'logentry', 'table_name'];
	$data['keys'][]     = ['name' => 'tableid', 'columns' => ['tableid']];
	$engine             = slowlog_get_storage_engine();
	$data['type']       = $engine;
	$data['row_format'] = ($engine === 'Aria') ? 'Page' : 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details_tables', $data);

	$data              = [];
	$data['columns'][] = ['name' => 'method', 'type' => 'varchar(45)', 'NULL' => false];
	// wide enough for the longest comma-separated seed fragment list below (ANALYZES/OPTIMIZES)
	$data['columns'][]  = ['name' => 'query', 'type' => 'varchar(96)', 'NULL' => false];
	$data['columns'][]  = ['name' => 'methodid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true];
	$data['primary']    = ['method', 'query'];
	$data['keys'][]     = ['name' => 'methodid', 'columns' => ['methodid']];
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_methods', $data);

	// Existing installs may still have the pre-2.4 varchar(45) width; widen it before
	// inserting the longer ANALYZES/OPTIMIZES/CREATES seed fragments.
	db_execute('ALTER TABLE plugin_slowlog_methods MODIFY COLUMN `query` varchar(96) NOT NULL');

	$data               = [];
	$data['columns'][]  = ['name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
	$data['columns'][]  = ['name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false];
	$data['primary']    = ['logid', 'table_name'];
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_tables', $data);

	// Schema groundwork: dictionary of every distinct table name seen across imports, plus
	// whether it's a known Cacti table. Per-logentry table associations are still written
	// directly to plugin_slowlog_tables/plugin_slowlog_details_tables by import_post_process();
	// this table only caches the is_cacti_table lookup so OTHER TABLES classification doesn't
	// re-derive it per logentry (see slowlog_sync_table_dictionary()/slowlog_classify_other_tables()).
	$data                  = [];
	$data['columns'][]     = ['name' => 'tableid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true];
	$data['columns'][]     = ['name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false, 'default' => ''];
	$data['columns'][]     = ['name' => 'is_cacti_table', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => 0];
	$data['primary']       = 'tableid';
	$data['unique_keys'][] = ['name' => 'table_name', 'columns' => ['table_name']];
	$data['type']          = 'InnoDB';
	$data['row_format']    = 'Dynamic';
	$data['comment']       = 'Dictionary of table names seen in imported slow query logs';

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

	$data               = [];
	$data['columns'][]  = ['name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true];
	$data['columns'][]  = ['name' => 'word', 'type' => 'varchar(30)', 'NULL' => false];
	$data['primary']    = ['id', 'word'];
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_reserved_words', $data);

	// The (id, word) primary key doesn't prevent duplicate words on a re-run, since id is
	// auto-incrementing - only load once, when the table is still empty.
	if (file_exists(__DIR__ . '/../docs/keywords.txt') && !db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_reserved_words')) {
		$words = file(__DIR__ . '/../docs/keywords.txt') ?: [];

		if (cacti_sizeof($words)) {
			foreach ($words as $word) {
				db_execute_prepared('INSERT INTO plugin_slowlog_reserved_words
					(word)
					VALUES (?)',
					[trim($word)]);
			}
		}
	}

	$data               = [];
	$data['columns'][]  = ['name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'comment' => 'The logid this cached summary belongs to'];
	$data['columns'][]  = ['name' => 'scope', 'type' => 'varchar(10)', 'NULL' => false, 'comment' => 'Whether scope_key is a method name (method) or a table name (table)'];
	$data['columns'][]  = ['name' => 'scope_key', 'type' => 'varchar(45)', 'NULL' => false, 'comment' => 'plugin_slowlog_methods.method value, or a table_name (or others)'];
	$data['columns'][]  = ['name' => 'metric', 'type' => 'varchar(20)', 'NULL' => false, 'comment' => 'query_time, rows_sent, rows_examined, rows_affected, or bytes_sent'];
	$data['columns'][]  = ['name' => 'sample_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => 0, 'comment' => 'Number of detail rows this summary was computed from'];
	$data['columns'][]  = ['name' => 'total_value', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'SUM across all matching rows'];
	$data['columns'][]  = ['name' => 'min_value', 'type' => 'double', 'NULL' => false, 'default' => 0];
	$data['columns'][]  = ['name' => 'p25_value', 'type' => 'double', 'NULL' => false, 'default' => 0];
	$data['columns'][]  = ['name' => 'median_value', 'type' => 'double', 'NULL' => false, 'default' => 0];
	$data['columns'][]  = ['name' => 'p75_value', 'type' => 'double', 'NULL' => false, 'default' => 0];
	$data['columns'][]  = ['name' => 'p95_value', 'type' => 'double', 'NULL' => false, 'default' => 0];
	$data['columns'][]  = ['name' => 'max_value', 'type' => 'double', 'NULL' => false, 'default' => 0];
	$data['primary']    = ['logid', 'scope', 'scope_key', 'metric'];
	$data['keys'][]     = ['name' => 'scope_metric', 'columns' => ['scope', 'metric']];
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';
	$data['comment']    = 'Cached box-whisker (min/p25/median/p75/p95/max) + totals per method/table, per imported log';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_stats', $data);
}

/**
 * Applies any pending schema changes to this plugin's already-installed
 * tables during an upgrade. Re-runs table creation (safe/no-op for
 * existing tables/columns) and, for a pre-existing plugin_slowlog_details
 * table, diffs and applies any new columns/indexes via db_update_table().
 * A fresh install already receives the current schema from the create
 * path, so the db_update_table() refresh only matters for existing tables.
 * Called from slowlog_check_upgrade().
 *
 * @return void
 */
function slowlog_upgrade_tables(): void {
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

/**
 * Drops every table this plugin owns. Called from
 * plugin_slowlog_uninstall() when the plugin is removed.
 *
 * @return void
 */
function slowlog_drop_tables(): void {
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
