<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2025 The Cacti Group                                 |
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

function get_cacti_tables() {
	$databases = db_fetch_assoc('SHOW DATABASES');
	$tables    = '';

	if (cacti_sizeof($databases)) {
		foreach($databases as $db) {
			if ($db['Database'] == 'information_schema' || $db['Database'] == 'mysql') {
				// Skip
			} else {
				$tables .= (strlen($tables) ? ' ':'') . implode(' ', array_rekey(db_fetch_assoc('SHOW TABLES FROM ' . $db['Database']), 'Tables_in_' . $db['Database'], 'Tables_in_' . $db['Database']));
			}
		}
	}

	return $tables;
}

function import_logfile($logfile, $description = 'Imported using import_log utility', $length = 8192, $table_names = '', $usecacti = false, $batch = true) {
	global $config;

	ini_set('max_execution_time', 0);

	if ($table_names == '' && $usecacti) {
		$table_names = get_cacti_tables();
	}

	$logfile = trim($logfile);

	if (file_exists($logfile)) {
		// suck the log through a straw
		$entries    = file($logfile);

		// denotes that the log beginning has been found
		$start      = false;

		// sql related variables
		$records    = array();
		$sql_prefix = 'INSERT INTO plugin_slowlog_details (logid, date, user, host, ip_address, query_time, lock_time, thread_id, `schema`, qc_hit, rows_sent, rows_examined, rows_affected, bytes_sent, oquery, query) VALUES ';

		if (cacti_sizeof($entries)) {
			// variables related to each slowlog entry
			$date          = 0;
			$user          = '';
			$host          = '';
			$ip            = '';
			$query         = '';
			$oquery        = '';
			$schema        = '';
			$qc_hit        = 0;
			$query_time    = 0;
			$thread_id     = 0;
			$lock_time     = 0;
			$rows_sent     = 0;
			$rows_examined = 0;
			$rows_affected = 0;
			$bytes_sent    = 0;
			$start_time    = 0;
			$curtime       = null;
			$lines         = 0;

			foreach($entries as $l) {
				if ($start && substr($l, 0, 1) != '#') {
					$query_start = true;
				}

				if (strpos($l, 'SET timestamp=') !== false) {
					$parts   = explode('=', $l);
					$date    = date('Y-m-d H:i:s', intval($parts[1]));
				} elseif (strpos($l, '# Time:') !== false) {
					// we are a good log, let's make an entry
					if (!$start) {
						$save['logid']         = 0;
						$save['description']   = $description;
						$save['import_date']   = date('Y-m-d H:i:s');
						$save['import_lines']  = 0;
						$save['import_tables'] = $table_names;
						$save['start_time']    = date('Y-m-d H:i:s');
						$save['end_time']      = date('Y-m-d H:i:s');

						$logid = sql_save($save, 'plugin_slowlog', 'logid');

						if ($logid == 0) {
							print "FATAL: Can not import due to error creating parent record in 'plugin_slowlog'\n";
							exit -1;
						}

						$start = true;
					} else {
						if ($query != '') {
							if ($length != -1) {
								$query = substr($query, 0, $length);
							}

							$records[] = '(' .
							$logid           . ', ' .
							db_qstr($date)   . ', ' .
							db_qstr($user)   . ', ' .
							db_qstr($host)   . ', ' .
							db_qstr($ip)     . ', ' .
							$query_time      . ', ' .
							$lock_time       . ', ' .
							$thread_id       . ', ' .
							db_qstr($schema) . ', ' .
							$qc_hit          . ', ' .
							$rows_sent       . ', ' .
							$rows_examined   . ', ' .
							$rows_affected   . ', ' .
							$bytes_sent      . ', ' .
							db_qstr($oquery) . ', ' .
							db_qstr($query)  . ')';
						}
					}

					$query  = '';
					$oquery = '';
					$p1     = explode(' ', $l);
					$date   = '20' .
						substr($p1[2], 0, 2) . '-' .
						substr($p1[2], 2, 2) . '-' .
						substr($p1[2], 4, 2) .
						(isset($p1[3]) ? ' ' . $p1[3]:'');

					if ($start_time == 0) {
						$start_time = $date;
					}

					$query_start = false;
				} elseif (strpos($l, '# User@Host:') !== false) {
					if ($query_start) {
						if ($query != '') {
							if ($length != -1) {
								$query = substr($query, 0, $length);
							}

							$records[] = '(' .
							$logid           . ', ' .
							db_qstr($date)   . ', ' .
							db_qstr($user)   . ', ' .
							db_qstr($host)   . ', ' .
							db_qstr($ip)     . ', ' .
							$query_time      . ', ' .
							$lock_time       . ', ' .
							$thread_id       . ', ' .
							db_qstr($schema) . ', ' .
							$qc_hit          . ', ' .
							$rows_sent       . ', ' .
							$rows_examined   . ', ' .
							$rows_affected   . ', ' .
							$bytes_sent      . ', ' .
							db_qstr($oquery) . ', ' .
							db_qstr($query)  . ')';
						}

						$query  = '';
						$oquery = '';
					}

					$p1    = explode(':', $l);
					$p2    = explode('@', $p1[1]);
					$user1 = explode('[', trim($p2[0]));
					$user  = $user1[0];
					$host1 = explode('[', trim($p2[1]));
					$host  = slowlog_strip_domain(trim($host1[0]));
					$ip    = str_replace(']', '', trim($host1[1]));

					$query_start = false;
				} elseif (strpos($l, '# Query_time:') !== false) {
					$data = preg_split('/\s+/', $l);

					$query_time    = $data[2];
					$lock_time     = $data[4];
					$rows_sent     = $data[6];
					$rows_examined = $data[8];
				} elseif (strpos($l, '# Rows_affected:') !== false) {
					$data = preg_split('/\s+/', $l);

					$rows_affected = $data[2];
					$bytes_sent    = $data[4];
				} elseif (strpos($l, '# Thread_id:') !== false) {
					$data = preg_split('/\s+/', $l);

					$thread_id     = $data[2];
					$schema        = $data[4];
					$qc_hit        = $data[6] == 'No' ? 0:1;
				} elseif (strpos($l, '# administrator command:') !== false) {
					continue;
				} elseif ($start) {
					if (strpos($l, '/*!32311 LOCAL */') === false) {
						$oquery .= $l;

						/* Don't add comments to the normalized queries */
						if (substr(trim($l), 0, 2) == '--') {
							continue;
						} else {
							$query  .= trim($l) . ' ';
						}
					} else {
						$query   = '';
						$oquery  = '';
					}
				}

				if (cacti_sizeof($records) > 1) {
					// turn the records array into a string
					$sql_data = implode(',', $records);
					$lines   += sizeof($records);

					// insert the records
					db_execute($sql_prefix . $sql_data);

					// reinitialize the records array
					$records = array();
				}
			}

			if ($query != '') {
				if ($length != -1) {
					$query = substr($query, 0, $length);
				}

				$records[] = '(' .
				$logid           . ', ' .
				db_qstr($date)   . ', ' .
				db_qstr($user)   . ', ' .
				db_qstr($host)   . ', ' .
				db_qstr($ip)     . ', ' .
				$query_time      . ', ' .
				$lock_time       . ', ' .
				$thread_id       . ', ' .
				db_qstr($schema) . ', ' .
				$qc_hit          . ', ' .
				$rows_sent       . ', ' .
				$rows_examined   . ', ' .
				$rows_affected   . ', ' .
				$bytes_sent      . ', ' .
				db_qstr($oquery) . ', ' .
				db_qstr($query)  . ')';

				// turn the records array into a string
				$sql_data = implode(',', $records);
				$lines   += sizeof($records);

				// insert the records
				db_execute($sql_prefix . $sql_data);
			}

			$values = db_fetch_row_prepared('SELECT COUNT(*) AS import_lines, MIN(date) AS start_time, MAX(date) AS end_time
				FROM plugin_slowlog_details
				WHERE logid = ?',
				array($logid));

			// update statistics
			if (cacti_sizeof($values)) {
				db_execute_prepared('UPDATE plugin_slowlog
					SET import_lines = ?,
					import_status = 1,
					start_time = ?,
					end_time = ?
					WHERE logid = ?',
					array($values['import_lines'], $values['start_time'], $values['end_time'], $logid));
			}
		}


		if ($batch) {
			raise_message('import_pre', __('The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete', 'slowlog'), MESSAGE_LEVEL_INFO);

			$php = cacti_escapeshellcmd(read_config_option('path_php_binary'));

			exec_background($php, $config['base_path'] . "/plugins/slowlog/import_log.php --logid=$logid" . ($usecacti ? ' --usecacti':''));

			db_execute_prepared('UPDATE plugin_slowlog
				SET import_text_status = ?
				WHERE logid = ?',
				array('Post Processing with Table Detection', $logid));
		} else {
			import_post_process($logid, $table_names);
		}
	} else {
		print "FATAL: Can not find file '$logfile'\n";
		exit -1;
	}
}

function load_reserved_words() {
	global $reserved_words;

	$reserved_words = array_rekey(db_fetch_assoc('SELECT word FROM plugin_slowlog_reserved_words'), 'word', 'word');
}

function is_reserved_word($token) {
	global $reserved_words;

	// Cleanup the token
	$token = trim(strtoupper($token), '();');

	//slowlog_debug("Pre Token: " . $token);

	if (strpos($token, '=') !== false) {
		$parts = explode('=', $token);
		$token = trim($parts[0]);
	} elseif (strpos($token, '(') !== false) {
		$parts = explode('(', $token);
		$token = trim($parts[0]);
	}

	// slowlog_debug("Post Token: " . $token);

	if (isset($reserved_words[$token])) {
		slowlog_debug("Reserved Word: " . $token);
		return true;
	} else {
		return false;
	}
}

function import_post_process($logid, $table_names = '', $usecacti = false) {
	$records = db_fetch_cell_prepared('SELECT COUNT(*)
		FROM plugin_slowlog_details
		WHERE logid = ?',
		array($logid));

	if ($records > 0) {
		$start = microtime(true);

		// perform fairly fast
		$methods = db_fetch_assoc('SELECT *
			FROM plugin_slowlog_methods
			ORDER BY method');

		foreach($methods as $row) {
			if ($row['method'] != 'OTHERS') {
				$queries = explode(',', $row['query']);

				foreach($queries as $q) {
					db_execute_prepared("INSERT INTO plugin_slowlog_details_methods
						(logid, logentry, methodid)
						SELECT '$logid' AS logid, logentry, '" . $row['methodid'] . "' AS methodid
						FROM plugin_slowlog_details
						WHERE logid = ?
						AND query LIKE ?",
						array($logid, '%' . $q . '%'));
				}
			} else {
				$sql_where    = 'WHERE logid = ?';
				$sql_params   = array();
				$sql_params[] = $logid;

				foreach($methods as $method) {
					$queries = explode(',', $method['query']);

					foreach($queries as $q) {
						$sql_where .= ' AND query NOT LIKE ?';
						$sql_params[] = '%' . $q . '%';
					}
				}

				db_execute_prepared("INSERT INTO plugin_slowlog_details_methods
					(logid, logentry, methodid)
					SELECT '$logid' AS logid, logentry, " . $row['methodid'] . " AS methodid
					FROM plugin_slowlog_details
					$sql_where",
					$sql_params);
			}
		}

		$end = microtime(true);

		cacti_log(sprintf('STATS: Time:%0.2f, Pre-Processing for Methods Complete for %s', $end-$start, $logid), false, 'SLOWLOG');

		$start = microtime(true);

		// perform table name analysis
		$tables = array();
		if ($table_names != '') {
			$tables = explode(' ', trim($table_names));
		} elseif ($usecacti) {
			$tables = explode(' ', db_fetch_cell_prepared('SELECT import_tables FROM plugin_slowlog WHERE logid = ?', array($logid)));
		} else {
			get_table_associations($logid);
		}

		$total_tables = cacti_sizeof($tables);

		$i = 0;

		if ($total_tables > 0) {
			foreach($tables as $t) {
				db_execute("INSERT INTO plugin_slowlog_tables (logid, table_name) VALUES ($logid, '$t')");

				db_execute("INSERT INTO plugin_slowlog_details_tables (logid, logentry, table_name)
					SELECT '$logid' AS logid, logentry, '$t' AS table_name
					FROM plugin_slowlog_details
					WHERE logid=$logid
					AND (query LIKE '%FROM $t %'
					OR query LIKE '%FROM ($t,%'
					OR query LIKE '%FROM (%,$t)%'
					OR query LIKE '%JOIN $t %'
					OR query LIKE '%`$t`%'
					OR query LIKE '%UPDATE $t %'
					OR query LIKE '%INTO $t %'
					OR query LIKE '%FROM $t')");

				if ($i % 20 == 0) {
					db_execute_prepared('UPDATE plugin_slowlog
						SET import_text_status = ?
						WHERE logid = ?',
						array("$i / $total_tables Tables Processed", $logid));
				}

				$i++;
			}
		}
	}

	db_execute_prepared('UPDATE plugin_slowlog
		SET import_text_status = ?,
		import_status = 2
		WHERE logid = ?',
		array("All Tables Processed", $logid));

	$end = microtime(true);

	cacti_log(sprintf('STATS: Time:%0.2f, Post-Processing for Tables Complete for %s', $end-$start, $logid), false, 'SLOWLOG');
}

function slowlog_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs = array(
		'select'  => 'Summary',
		'methods' => 'By Method',
		'tables'  => 'By Table',
		'details' => 'Details');

	if (isset($_REQUEST['logentry'])) {
		$tabs = array_merge($tabs, array('query' => 'Query'));
	}

	/* set the default tab */
	$current_tab = $_REQUEST['action'];

	if ($current_tab == 'select') {
		unset($_REQUEST['logid']);
	}

	/* draw the tabs */
	print '<div class="tabs"><nav><ul>';

	if (cacti_sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print '<li><a ' . (($tab_short_name == $current_tab) ? "class='selected pic'" : "class='pic'") . " href='" . html_escape($config['url_path'] .
				'plugins/slowlog/slowlog.php' .
				'?action=' . $tab_short_name .
				(isset($_REQUEST['logid']) ? '&logid=' . $_REQUEST['logid']:'') .
				(isset($_REQUEST['logentry']) ? '&logentry=' . $_REQUEST['logentry']:'')) .
				"'>" . $tabs[$tab_short_name] . '</a></li>';

			if (!isset($_REQUEST['logid'])) {
				break;
			}
		}
	}

	print '</ul></nav></div>';
}

function get_table_associations($logid, $logentry = -1) {
	load_reserved_words();

	$sql = array();
	$sql_prefix = 'INSERT INTO plugin_slowlog_details_tables (logid, logentry, table_name) VALUES ';
	$sql_suffix = 'ON DUPLICATE KEY UPDATE table_name=VALUES(table_name)';

	if ($logentry == -1) {
		$rows = db_fetch_assoc_prepared('SELECT *
			FROM plugin_slowlog_details
			WHERE logid = ?',
			array($logid));
	} else {
		$rows = db_fetch_assoc_prepared('SELECT *
			FROM plugin_slowlog_details
			WHERE logid = ? AND logentry = ?',
			array($logid, $logentry));
	}

	foreach($rows as $row) {
		$tokens = preg_split('/\s+/', $row['query']);

		$query = $row['query'];
		slowlog_debug('--------------------------------------------------------------------');
		slowlog_debug(substr($row['query'],0,4000));

		$in_delete   = false;
		$in_show     = false;
		$in_select   = false;
		$in_from     = false;
		$in_as       = false;
		$in_join     = false;
		$in_on       = false;
		$in_using    = false;
		$in_where    = false;
		$in_having   = false;
		$in_limit    = false;
		$in_order    = false;
		$in_groupby  = false;

		$in_update   = false;
		$in_table    = false;
		$in_modify   = false;
		$in_change   = false;
		$in_set      = false;
		$in_insert   = false;
		$in_into     = false;
		$in_values   = false;
		$in_truncate = false;
		$in_with     = false;

		$table_mod   = false;

		$tables = array();

		foreach($tokens as $index => $t) {
			$t = trim(strtolower($t));
			$bparen = false;
			$eparen = false;

			if (substr($t, 0, 1) == '(') {
				$bparen = true;
				//slowlog_debug('Bparen: ' . $t);
			} elseif (substr($t, 0, 1) == ')') {
				$eparen = true;
			}

			$t = trim($t, ')(');

			if ($t == '') {
				continue;
			}

			//slowlog_debug('Token: ' . $t);

			switch($t) {
				case 'delete':
					slowlog_debug('In DELETE');

					$in_delete   = true;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_limit    = false;
					$in_order    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'show':
					slowlog_debug('In SHOW');

					$in_delete   = false;
					$in_show     = true;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_limit    = false;
					$in_order    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'select':
					slowlog_debug('In SELECT');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = true;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_limit    = false;
					$in_order    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'from':
					if (!$in_select && !$in_as && !$in_delete && !$in_show) {
						break;
					}

					slowlog_debug('In FROM');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = true;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_limit    = false;
					$in_order    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'as':
					//slowlog_debug('In AS');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = true;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_limit    = false;
					$in_order    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'join':
				case 'strait_join':
					slowlog_debug('In JOIN');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = true;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'on':
					slowlog_debug('In ON');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = true;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					if (strtolower($tokens[$index + 1]) == 'duplicate') {
						break 2;
					}

					break;
				case 'using':
					slowlog_debug('In USING');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = true;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'where':
					slowlog_debug('In WHERE');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = true;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'group':
					slowlog_debug('In GROUP');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = true;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'having':
					slowlog_debug('In HAVING');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = true;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'order':
					slowlog_debug('In ORDER');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = true;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'limit':
					slowlog_debug('In LIMIT');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = true;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'insert':
					slowlog_debug('In INSERT');

					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = true;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'into':
					slowlog_debug('In INTO');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = true;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'truncate':
					slowlog_debug('In TRUNCATE');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = true;
					$in_with     = false;

					break;
				case 'update':
					if ($in_values) {
						break;
					}

					slowlog_debug('In UPDATE');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = true;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'set':
					slowlog_debug('In SET');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = true;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'table':
				case 'tables':
					slowlog_debug('In TABLE');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = true;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'modify':
					slowlog_debug('In MODIFY');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = true;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'change':
					slowlog_debug('In CHANGE');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = true;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'values':
					slowlog_debug('In VALUES');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = true;
					$in_truncate = false;
					$in_with     = false;

					break;
				case 'with':
					slowlog_debug('In WITH');

					$in_delete   = false;
					$in_show     = false;
					$in_select   = false;
					$in_from     = false;
					$in_as       = false;
					$in_join     = false;
					$in_on       = false;
					$in_using    = false;
					$in_where    = false;
					$in_groupby  = false;
					$in_having   = false;
					$in_order    = false;
					$in_limit    = false;

					$in_update   = false;
					$in_table    = false;
					$in_modify   = false;
					$in_change   = false;
					$in_set      = false;
					$in_insert   = false;
					$in_into     = false;
					$in_values   = false;
					$in_truncate = false;
					$in_with     = true;

					break;
				default:
					if ($in_select) {
						$table_mod = false;
						break;
					} elseif ($in_where) {
						$table_mod = false;
						break;
					} elseif ($in_groupby) {
						$table_mod = false;
						break;
					} elseif ($in_having) {
						$table_mod = false;
						break;
					} elseif ($in_order) {
						$table_mod = false;
						break;
					} elseif ($in_limit) {
						$table_mod = false;
						break;
					} elseif ($in_on) {
						$table_mod = false;
						break;
					} elseif ($in_using) {
						$table_mod = false;
						break;
					} elseif ($in_set) {
						$table_mod = false;
						break;
					} elseif ($in_insert) {
						$table_mod = false;
						break;
					} elseif ($in_as) {
						$table_mod = false;
						break;
					} elseif ($in_values) {
						$table_mod = false;
						break;
					} elseif ($in_with) {
						$table_mod = false;
						break;
					} elseif ($in_modify) {
						$table_mod = false;
						break;
					} elseif ($in_change) {
						$table_mod = false;
						break;
					} elseif ($in_from) {
						if (is_reserved_word($t)) {
							$table_mod = true;
							$table_mod = true;
						} elseif ($t == ',') {
							$table_mod = false;
						} elseif ($t != '' && !is_reserved_word($t) && !$table_mod) {
							$table = trim($t, ')( ');

							if (substr($table, -1) == ',') {
								$table_mod = false;
							} else {
								$table_mod = true;
							}

							$table = trim($table, ',');

							if (strpos($table, ',') !== false) {
								$tparts = explode(',', $table);
								foreach($tparts as $table) {
									$table = parseTable($table);
									$tables[$table] = $table;
								}
							} else {
								$table = parseTable($t);
								$tables[$table] = $table;
							}

							slowlog_debug('FROM: ' . $table);
						}
					} elseif ($in_into) {
						if (is_reserved_word($t) && $t != 'table') {
							slowlog_debug("RES WORD: " . $t);
							$table_mod = true;
						} elseif ($bparen) {
							$table_mod = true;
						} elseif ($t == 'table') {
							$table_mod = false;
						} elseif (!$table_mod) {
							if ($t != '' && !is_reserved_word($t)) {
								$table = parseTable($t);
								$tables[$table] = $table;
								slowlog_debug('INTO: ' . $table);
								$table_mod = true;
							} elseif (is_reserved_word($t)) {
								$table_mod = false;
							} else {
								$table_mod = true;
							}
						}
					} elseif ($in_join) {
						if ($t != '' && !is_reserved_word($t) && !$table_mod) {
							$table = parseTable($t);
							$tables[$table] = $table;
							slowlog_debug('JOIN: ' . $table);
							$table_mod = true;
						}
					} elseif ($in_update) {
						if ($t == 'force' || $t == 'index') {
							$table_mod = true;
						} elseif ($t == 'inner' || $t == 'left' || $t == 'right' || $t == 'outer') {
							$table_mod = false;
						} elseif (!$table_mod) {
							if ($t != '' && !is_reserved_word($t)) {
								$table = parseTable($t);
								$tables[$table] = $table;
								slowlog_debug('UPDATE: ' . $table);
							} elseif (is_reserved_word($t)) {
								$table_mod = false;
							} else {
								$table_mod = true;
							}
						}
					} elseif ($in_truncate) {
						$table_mod = false;
						if (is_reserved_word($t)) {
							$table_mod = false;
						} elseif ($t != '') {
							$table = parseTable($t);
							$tables[$table] = $table;
							slowlog_debug('TRUNCATE: ' . $t);
						} else {
							$table_mod = true;
						}
					} elseif ($in_table) {
						if (is_reserved_word($t)) {
							$table_mod = false;
						} elseif ($t != '' && !$table_mod) {
							$table = parseTable($t);
							$tables[$table] = $table;
							slowlog_debug('TABLE: ' . $t);

							$table_mod = true;
						}

					} else {
						slowlog_debug('Something: ' . $t);
					}
			}
		}

		if (cacti_sizeof($tables)) {
			foreach($tables as $t) {
				$sql[] = '(' . $logid . ', ' . $row['logentry'] . ', ' . db_qstr($t) . ')';
			}
		} else {
			slowlog_debug("No tables found!!!: $query");
		}
	}

	if (cacti_sizeof($sql)) {
		cacti_log('Post Processing Tables associated: ' . cacti_sizeof($sql), false, 'SLOWLOG');

		$sqls = array_chunk($sql, 500);

		foreach($sqls as $sql) {
			db_execute($sql_prefix . implode(', ', $sql) . $sql_suffix);
		}
	}
}

function parseTable($table) {
	$table = trim($table, ';)');
	$table = str_replace('`', '', $table);
	$parts = explode('.', $table);

	// Prune the schema
	if (cacti_sizeof($parts) > 1) {
		$table = $parts[1];
	}

	// Remove beginning braces
	$parts = explode('(', $table);
	if (cacti_sizeof($parts) == 1) {
		return trim($table, '\'`');
	} else {
		return trim($parts[0], '\'`');
	}
}

function slowlog_debug($string) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($string) . PHP_EOL;
	}
}

function slowlog_strip_domain($host) {
	$parts = explode('.', $host);
	return str_replace('-new', '', $parts[0]);
}
