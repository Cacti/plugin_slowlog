<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2024 The Cacti Group                                 |
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
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog`');
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog_details`');
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog_details_methods`');
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog_details_tables`');
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog_methods`');
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog_tables`');
	db_execute('DROP TABLE IF EXISTS `plugin_slowlog_reserved_words`');
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

	$current = slowlog_version();
	$current = $current['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='slowlog'");

	if ($current != $old) {
		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='slowlog'");
		db_execute("UPDATE plugin_config SET
			version='" . $info['version']  . "',
			name='"    . $info['longname'] . "',
			author='"  . $info['author']   . "',
			webpage='" . $info['homepage'] . "'
			WHERE directory='" . $info['name'] . "' ");

		db_execute('DELETE FROM plugin_hooks WHERE function="slowlog_page_head"');

	}
}

function slowlog_check_dependencies() {
	global $plugins, $config;
	return true;
}

function slowlog_setup_table_new() {
	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog` (
		`logid` int(10) unsigned NOT NULL auto_increment COMMENT 'The unique id for this log entry',
		`description` varchar(128) NOT NULL default '' COMMENT 'The description for the slow log',
		`import_date` timestamp NOT NULL default CURRENT_TIMESTAMP COMMENT 'The date the log was uploaded',
		`import_lines` int(10) unsigned NOT NULL default '0' COMMENT 'The number of lines in the log',
		`import_status` int(10) unsigned NOT NULL default '0' COMMENT 'The status of the import process',
		`import_text_status` varchar(40) NOT NULL default '' COMMENT 'The text status of the import process',
		`import_tables` text NOT NULL default '',
		`start_time` timestamp NOT NULL default '0000-00-00 00:00:00' COMMENT 'The start time for the log',
		`end_time` timestamp NOT NULL default '0000-00-00 00:00:00' COMMENT 'The end time for the log',
		PRIMARY KEY (`logid`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic
		COMMENT='Each Slow Log Can be Tracked';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog_details` (
		`logentry` bigint(20) unsigned NOT NULL auto_increment,
		`logid` int(10) unsigned NOT NULL,
		`date` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		`user` varchar(20) NOT NULL,
		`host` varchar(255) NOT NULL,
		`ip_address` varchar(15) NOT NULL,
		`query_time` int(10) unsigned NOT NULL,
		`lock_time` int(10) unsigned NOT NULL,
		`thread_id` bigint(20) unsigned NOT NULL DEFAULT 0,
		`schema` varchar(20) NOT NULL DEFAULT '',
		`qc_hit` int(10) unsigned NOT NULL DEFAULT 0,
		`rows_sent` int(10) unsigned NOT NULL,
		`rows_examined` int(10) unsigned NOT NULL,
		`rows_affected` int(10) unsigned NOT NULL DEFAULT 0,
		`bytes_sent` bigint unsigned NOT NULL DEFAULT 0,
		`oquery` text NOT NULL,
		`query` text NOT NULL,
		PRIMARY KEY  (`logentry`),
		KEY `user` (`user`),
		KEY `host` (`host`))
		ENGINE = InnoDB
		ROW_FORMAT=Dynamic
		COMMENT='Provides statistics on your slow query log';");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog_details_methods` (
		`id` int(10) unsigned NOT NULL auto_increment,
		`logid` int(10) unsigned NOT NULL,
		`logentry` int(10) unsigned NOT NULL,
		`methodid` int(10) unsigned NOT NULL,
		PRIMARY KEY  USING BTREE (`logid`,`logentry`,`methodid`),
		KEY `id` (`id`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog_details_tables` (
		`tableid` int(10) unsigned NOT NULL auto_increment,
		`logid` int(10) unsigned NOT NULL,
		`logentry` int(10) unsigned NOT NULL,
		`table_name` varchar(45) NOT NULL,
		PRIMARY KEY  (`logid`,`logentry`,`table_name`),
		KEY `tableid` (`tableid`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog_methods` (
		`method` varchar(45) NOT NULL,
		`query` varchar(45) NOT NULL,
		`methodid` int(10) unsigned NOT NULL auto_increment,
		PRIMARY KEY  (`method`,`query`),
		KEY `methodid` (`methodid`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog_tables` (
		`logid` int(10) unsigned NOT NULL,
		`table_name` varchar(45) NOT NULL,
		PRIMARY KEY  (`logid`,`table_name`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	db_execute("INSERT INTO `plugin_slowlog_methods` VALUES
		('INSERTS','INSERT INTO,INSERT IGNORE INTO',1),
		('REPLACES','REPLACE INTO,REPLACE IGNORE INTO',2),
		('DELETES','DELETE ',3),
		('SELECTS','SELECT ',4),
		('DISTINCTS','SELECT DISTINCT',5),
		('UNIONS','UNION',6),
		('JOINS','JOIN ',7),
		('OTHERS','OTHERS',8),
		('UPDATES','UPDATE ',9),
		('RENAMES', 'RENAME TABLE', 10),
		('FLUSHES', 'FLUSH TABLE', 11),
		('TRUNCATES', 'TRUNCATE ', 12),
		('LOAD DATA', 'LOAD DATA INFILE ', 13),
		('OUTFILES', 'INTO OUTFILE ', 14)");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_slowlog_reserved_words` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`word` varchar(30) NOT NULL,
		PRIMARY KEY  (`id`, `word`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	if (file_exists(__DIR__ . '/keywords.txt')) {
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
		$perms = db_fetch_cell("SELECT id FROM plugin_realms WHERE file LIKE '%slowlog.php%'") + 100;

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

