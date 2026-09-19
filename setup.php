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

function plugin_slowlog_install() {
	api_plugin_register_hook('slowlog', 'config_arrays',         'slowlog_config_arrays',        'setup.php');
	api_plugin_register_hook('slowlog', 'draw_navigation_text',  'slowlog_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('slowlog', 'config_settings',       'slowlog_config_settings',      'setup.php');
	api_plugin_register_hook('slowlog', 'top_header_tabs',       'slowlog_show_tab',             'setup.php');
	api_plugin_register_hook('slowlog', 'top_graph_header_tabs', 'slowlog_show_tab',             'setup.php');

	api_plugin_register_realm('slowlog', 'slowlog.php', 'Plugin -> MySQL Slow Log Viewer', 1);

	slowlog_setup_table_new();
}

function slowlog_version() {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/slowlog/INFO', true);

	return $info['info'];
}

function plugin_slowlog_uninstall() {
	api_plugin_drop_table('plugin_slowlog');
	api_plugin_drop_table('plugin_slowlog_details');
	api_plugin_drop_table('plugin_slowlog_details_methods');
	api_plugin_drop_table('plugin_slowlog_details_tables');
	api_plugin_drop_table('plugin_slowlog_methods');
	api_plugin_drop_table('plugin_slowlog_tables');
	api_plugin_drop_table('plugin_slowlog_table_names');
	api_plugin_drop_table('plugin_slowlog_reserved_words');
}

function plugin_slowlog_check_config() {
	/* Here we will check to ensure everything is configured */
	slowlog_check_upgrade();
	return true;
}

function plugin_slowlog_upgrade() {
	/* Here we will upgrade to the newest version */
	slowlog_check_upgrade();
	return false;
}

function plugin_slowlog_version() {
	return slowlog_version();
}

function slowlog_check_upgrade() {
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');
	include_once($config['library_path'] . '/functions.php');

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'slowlog.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$info    = slowlog_version();
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

		// Re-running the table/column API calls is safe - they're no-ops when already applied.
		slowlog_setup_table_new();
	}
}

function slowlog_check_dependencies() {
	global $plugins, $config;
	return true;
}

function slowlog_setup_table_new() {
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
	$data['columns'][] = array('name' => 'oquery', 'type' => 'text', 'NULL' => false);
	$data['columns'][] = array('name' => 'query', 'type' => 'text', 'NULL' => false);
	$data['columns'][] = array('name' => 'timeout', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'The timeout value detected in the query, if any');
	$data['primary']    = 'logentry';
	$data['keys'][]     = array('name' => 'logid', 'columns' => array('logid'));
	$data['keys'][]     = array('name' => 'user', 'columns' => array('user'));
	$data['keys'][]     = array('name' => 'host', 'columns' => array('host'));
	$data['type']       = 'Aria';
	$data['row_format'] = 'Page';
	$data['comment']    = 'Provides statistics on your slow query log';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details', $data);

	// New column since 2.1 - re-issuing this on every upgrade is safe, it's a no-op once applied.
	api_plugin_db_add_column('slowlog', 'plugin_slowlog_details', array('name' => 'timeout', 'type' => 'double', 'NULL' => false, 'default' => 0, 'comment' => 'The timeout value detected in the query, if any', 'after' => 'query'));

	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'logentry', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'methodid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['primary']    = array('logid', 'logentry', 'methodid');
	$data['keys'][]     = array('name' => 'id', 'columns' => array('id'));
	$data['type']       = 'Aria';
	$data['row_format'] = 'Page';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details_methods', $data);

	$data = array();
	$data['columns'][] = array('name' => 'tableid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'logentry', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false);
	$data['primary']    = array('logid', 'logentry', 'table_name');
	$data['keys'][]     = array('name' => 'tableid', 'columns' => array('tableid'));
	$data['type']       = 'Aria';
	$data['row_format'] = 'Page';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_details_tables', $data);

	$data = array();
	$data['columns'][] = array('name' => 'method', 'type' => 'varchar(45)', 'NULL' => false);
	$data['columns'][] = array('name' => 'query', 'type' => 'varchar(45)', 'NULL' => false);
	$data['columns'][] = array('name' => 'methodid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true);
	$data['primary']    = array('method', 'query');
	$data['keys'][]     = array('name' => 'methodid', 'columns' => array('methodid'));
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_methods', $data);

	$data = array();
	$data['columns'][] = array('name' => 'logid', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false);
	$data['columns'][] = array('name' => 'table_name', 'type' => 'varchar(45)', 'NULL' => false);
	$data['primary']    = array('logid', 'table_name');
	$data['type']       = 'InnoDB';
	$data['row_format'] = 'Dynamic';

	api_plugin_db_table_create('slowlog', 'plugin_slowlog_tables', $data);

	// Dictionary of every distinct table name seen across all imports, so table_name text
	// doesn't need to be duplicated per logentry, and whether it's a known Cacti table is
	// recorded once instead of being re-derived every time.
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
		(\'OTHER TABLES\', \'OTHER TABLES\', 22)');

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
		$words = file(__DIR__ . '/keywords.txt');

		if (cacti_sizeof($words)) {
			foreach($words as $word) {
				db_execute_prepared('INSERT INTO plugin_slowlog_reserved_words
					(word)
					VALUES (?)',
					array(trim($word)));
			}
		}
	}
}

function slowlog_config_arrays() {
	slowlog_check_upgrade();
}

function slowlog_config_settings() {
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

function slowlog_draw_navigation_text ($nav) {
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

function slowlog_show_tab() {
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

