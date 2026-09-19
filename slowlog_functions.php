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

function slowlog_render_with_layout(callable $render_callback): void {
	general_header();
	$render_callback();
	bottom_footer();
}

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

/*
 * Keeps plugin_slowlog_table_names (the deduplicated table_name dictionary) in sync with
 * whatever get_table_associations()/the explicit table_names list just found for this logid.
 * is_cacti_table is only touched when $usecacti is true (we have a live table list to compare
 * against) - otherwise a row is added if missing, but any previously-determined
 * is_cacti_table value is left alone rather than being reset to "unknown".
 */
function slowlog_sync_table_dictionary($logid, $usecacti = false) {
	$tables = db_fetch_assoc_prepared('SELECT DISTINCT table_name
		FROM plugin_slowlog_details_tables
		WHERE logid = ?',
		array($logid));

	if (!cacti_sizeof($tables)) {
		return;
	}

	if ($usecacti) {
		$cacti_tables = array_flip(explode(' ', trim(get_cacti_tables())));
	}

	foreach($tables as $row) {
		$t = $row['table_name'];

		if ($usecacti) {
			db_execute_prepared('INSERT INTO plugin_slowlog_table_names
				(table_name, is_cacti_table)
				VALUES (?, ?)
				ON DUPLICATE KEY UPDATE is_cacti_table = VALUES(is_cacti_table)',
				array($t, isset($cacti_tables[$t]) ? 1 : 0));
		} else {
			db_execute_prepared('INSERT IGNORE INTO plugin_slowlog_table_names
				(table_name)
				VALUES (?)',
				array($t));
		}
	}
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

	$reserved_words = array_rekey(db_fetch_assoc_prepared('SELECT word
		FROM plugin_slowlog_reserved_words',
		array()), 'word', 'word');
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
		$methods = db_fetch_assoc_prepared('SELECT *
			FROM plugin_slowlog_methods
			ORDER BY method',
			array());

		foreach($methods as $row) {
			if ($row['method'] != 'OTHERS') {
				$queries = explode(',', $row['query']);

				foreach($queries as $q) {
					db_execute_prepared('INSERT INTO plugin_slowlog_details_methods
						(logid, logentry, methodid)
						SELECT ? AS logid, logentry, ? AS methodid
						FROM plugin_slowlog_details
						WHERE logid = ?
						AND query LIKE ?',
						array($logid, $row['methodid'], $logid, '%' . $q . '%'));
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
					SELECT ? AS logid, logentry, ? AS methodid
					FROM plugin_slowlog_details
					$sql_where",
					array_merge(array($logid, $row['methodid']), $sql_params));
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
				db_execute_prepared('INSERT INTO plugin_slowlog_tables
					(logid, table_name)
					VALUES (?, ?)',
					array($logid, $t));

				db_execute_prepared('INSERT INTO plugin_slowlog_details_tables (logid, logentry, table_name)
					SELECT ? AS logid, logentry, ? AS table_name
					FROM plugin_slowlog_details
					WHERE logid = ?
					AND (query LIKE ?
					OR query LIKE ?
					OR query LIKE ?
					OR query LIKE ?
					OR query LIKE ?
					OR query LIKE ?
					OR query LIKE ?
					OR query LIKE ?)',
					array($logid, $t, $logid, '%FROM ' . $t . ' %', '%FROM (' . $t . ',%', '%FROM (%,' . $t . ')%', '%JOIN ' . $t . ' %', '%`' . $t . '`%', '%UPDATE ' . $t . ' %', '%INTO ' . $t . ' %', '%FROM ' . $t));

				if ($i % 20 == 0) {
					db_execute_prepared('UPDATE plugin_slowlog
						SET import_text_status = ?
						WHERE logid = ?',
						array("$i / $total_tables Tables Processed", $logid));
				}

				$i++;
			}
		}

		$end = microtime(true);

		cacti_log(sprintf('STATS: Time:%0.2f, Post-Processing for Tables Complete for %s', $end-$start, $logid), false, 'SLOWLOG');

		slowlog_sync_table_dictionary($logid, $usecacti);
		slowlog_set_timeouts($logid);
	}

	db_execute_prepared('UPDATE plugin_slowlog
		SET import_text_status = ?,
		import_status = 2
		WHERE logid = ?',
		array("All Tables Processed", $logid));
}

/*
 * Re-runs method/table/timeout classification for a logid that's already been imported,
 * without needing the original logfile - e.g. after new methods/tables are added to the
 * schema, or the tokenizer itself is improved. Clears the previously-derived associations
 * first since import_post_process()'s method inserts aren't safe to run twice otherwise.
 */
function slowlog_reprocess($logid, $table_names = '', $usecacti = false) {
	db_execute_prepared('DELETE FROM plugin_slowlog_details_methods WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_tables WHERE logid = ?', array($logid));
	db_execute_prepared('UPDATE plugin_slowlog_details SET timeout = 0 WHERE logid = ?', array($logid));

	db_execute_prepared('UPDATE plugin_slowlog
		SET import_status = 1,
		import_text_status = ?
		WHERE logid = ?',
		array('Reprocessing', $logid));

	cacti_log("NOTE: Reprocessing logid $logid", false, 'SLOWLOG');

	import_post_process($logid, $table_names, $usecacti);
}

/* slowlog_reprocess() for every logid currently in plugin_slowlog */
function slowlog_reprocess_all($table_names = '', $usecacti = false) {
	$logids = db_fetch_assoc_prepared('SELECT logid FROM plugin_slowlog', array());

	foreach($logids as $row) {
		slowlog_reprocess($row['logid'], $table_names, $usecacti);
	}
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
		$tables = slowlog_extract_tables_from_query($row['query']);

		if (cacti_sizeof($tables)) {
			foreach($tables as $t) {
				$sql[] = '(' . $logid . ', ' . $row['logentry'] . ', ' . db_qstr($t) . ')';
			}
		} else {
			slowlog_debug('No tables found: ' . substr($row['query'], 0, 4000));
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

/*
 * Regex/scanner based query tokenizer, replacing the old per-token state machine.
 *
 * Rather than walking the query one whitespace-delimited token at a time and toggling
 * dozens of "in_xxx" flags, this dispatches on the statement's leading keyword and then
 * pulls out every FROM clause and every JOIN target directly with targeted patterns. A
 * small hand-rolled scanner (slowlog_scan_clause_span/slowlog_match_balanced_parens) tracks
 * parenthesis depth so a FROM/JOIN clause is bounded correctly even when it contains a
 * derived table (subquery). Every table name found - including ones nested inside
 * subqueries, whether reached via the primary dispatch or not - is added to a de-duplicating
 * associative array, so overlapping/redundant discovery of the same table is harmless.
 */

/* keywords that end a FROM/JOIN table-reference-list clause at the current nesting depth */
const SLOWLOG_CLAUSE_BOUNDARY = '(?:WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION|INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|STRAIGHT_JOIN|JOIN|ON|USING|INTO\s+OUTFILE|PROCEDURE|FOR\s+UPDATE|LOCK\s+IN)';

/* any flavor of JOIN keyword, used both to split a table-ref-list and to find join targets */
const SLOWLOG_JOIN_KEYWORD = '(?:INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|STRAIGHT_JOIN|JOIN)';

function slowlog_normalize_query_text($query) {
	return trim(preg_replace('/\s+/', ' ', (string) $query));
}

/*
 * Splits $text on a top-level delimiter, i.e. one that isn't nested inside parens - so a
 * comma inside a function call or a derived table doesn't split a table-reference list.
 */
function slowlog_split_top_level($text, $delim = ',') {
	$parts   = array();
	$depth   = 0;
	$current = '';
	$len     = strlen($text);

	for ($i = 0; $i < $len; $i++) {
		$ch = $text[$i];

		if ($ch === '(') {
			$depth++;
		} elseif ($ch === ')') {
			$depth--;
		}

		if ($ch === $delim && $depth === 0) {
			$parts[] = $current;
			$current = '';
		} else {
			$current .= $ch;
		}
	}

	if (trim($current) !== '') {
		$parts[] = $current;
	}

	return $parts;
}

/*
 * $text must start with '('. Returns array(innerContent, remainderAfterClosingParen), or
 * false if the leading '(' has no matching close (malformed input).
 */
function slowlog_match_balanced_parens($text) {
	if (preg_match('/\((?:[^()]|(?R))*\)/', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] === 0) {
		$whole = $m[0][0];

		return array(substr($whole, 1, -1), substr($text, strlen($whole)));
	}

	return false;
}

/* grabs a leading `schema`.`table`/schema.table/table identifier off of $text */
function slowlog_first_identifier($text) {
	$text = ltrim($text);

	if (preg_match('/^`[^`]+`(?:\.`[^`]+`)?|^[A-Za-z0-9_$]+(?:\.[A-Za-z0-9_$]+)?/', $text, $m)) {
		return parseTable($m[0]);
	}

	return '';
}

/*
 * Finds the end offset (exclusive) of a clause starting at $start: the first depth-0
 * SLOWLOG_CLAUSE_BOUNDARY keyword, an unmatched ')', a ';', or end of string - whichever
 * comes first. Depth is relative to $start, so a derived table's own inner keywords don't
 * end the clause early.
 */
function slowlog_scan_clause_span($text, $start) {
	$depth = 0;
	$len   = strlen($text);
	$i     = $start;

	while ($i < $len) {
		$ch = $text[$i];

		if ($ch === '(') {
			$depth++;
			$i++;
			continue;
		}

		if ($ch === ')') {
			if ($depth === 0) {
				return $i;
			}

			$depth--;
			$i++;
			continue;
		}

		if ($ch === ';') {
			return $i;
		}

		if ($depth === 0 && preg_match('/\G\s+' . SLOWLOG_CLAUSE_BOUNDARY . '\b/i', $text, $bm, 0, $i)) {
			return $i;
		}

		$i++;
	}

	return $len;
}

/* a single item of a comma-separated table-reference list, e.g. "t1 a" or "(SELECT ...) x" */
function slowlog_extract_single_table_ref($text, &$tables) {
	$text = trim($text);

	if ($text === '') {
		return;
	}

	if ($text[0] === '(') {
		$balanced = slowlog_match_balanced_parens($text);

		if ($balanced !== false) {
			slowlog_extract_tables_from_query($balanced[0], $tables);

			return;
		}
	}

	$id = slowlog_first_identifier($text);

	if ($id !== '') {
		$tables[$id] = $id;
	}
}

/* the continuation of a table-reference list after its first JOIN keyword has been consumed */
function slowlog_extract_join_chain($text, &$tables) {
	while (true) {
		$text = ltrim($text);

		if ($text === '') {
			return;
		}

		if ($text[0] === '(') {
			$balanced = slowlog_match_balanced_parens($text);

			if ($balanced === false) {
				return;
			}

			slowlog_extract_tables_from_query($balanced[0], $tables);
			$text = $balanced[1];
		} else {
			$id = slowlog_first_identifier($text);

			if ($id !== '') {
				$tables[$id] = $id;
			}

			if (!preg_match('/\s+' . SLOWLOG_JOIN_KEYWORD . '\s+/i', $text, $m, PREG_OFFSET_CAPTURE)) {
				return;
			}

			$text = substr($text, $m[0][1] + strlen($m[0][0]));
		}
	}
}

/* a comma-separated table-reference list, e.g. a FROM clause or an UPDATE target list */
function slowlog_extract_table_ref_list($text, &$tables) {
	$text = trim($text);

	if ($text === '') {
		return;
	}

	foreach (slowlog_split_top_level($text, ',') as $part) {
		$part = trim($part);

		if ($part === '') {
			continue;
		}

		if (preg_match('/^(.*?)\s+' . SLOWLOG_JOIN_KEYWORD . '\s+(.*)$/is', $part, $m)) {
			slowlog_extract_single_table_ref($m[1], $tables);
			slowlog_extract_join_chain($m[2], $tables);
		} else {
			slowlog_extract_single_table_ref($part, $tables);
		}
	}
}

/*
 * Finds every FROM keyword in $query - at any nesting depth - and extracts its
 * table-reference list. Overlap with subqueries discovered elsewhere (e.g. via a JOIN
 * target) is intentional and harmless, since $tables is a de-duplicating set.
 */
function slowlog_extract_from_clauses($query, &$tables) {
	$offset = 0;
	$len    = strlen($query);

	while ($offset < $len && preg_match('/\bFROM\b/i', $query, $m, PREG_OFFSET_CAPTURE, $offset)) {
		$pos   = $m[0][1];
		$start = $pos + 4;
		$end   = slowlog_scan_clause_span($query, $start);

		slowlog_extract_table_ref_list(substr($query, $start, $end - $start), $tables);

		$offset = max($end, $pos + 4);
	}
}

/* finds every JOIN keyword in $query and extracts the table (or derived subquery) it targets */
function slowlog_extract_join_targets($query, &$tables) {
	if (!preg_match_all('/\b' . SLOWLOG_JOIN_KEYWORD . '\s+/i', $query, $m, PREG_OFFSET_CAPTURE)) {
		return;
	}

	foreach ($m[0] as $match) {
		$after = ltrim(substr($query, $match[1] + strlen($match[0])));

		if ($after !== '' && $after[0] === '(') {
			$balanced = slowlog_match_balanced_parens($after);

			if ($balanced !== false) {
				slowlog_extract_tables_from_query($balanced[0], $tables);
				continue;
			}
		}

		$id = slowlog_first_identifier($after);

		if ($id !== '') {
			$tables[$id] = $id;
		}
	}
}

/*
 * Determines every table referenced by a (normalized, single-line) SQL statement:
 * SELECT/DELETE FROM lists, JOINs (including chains and derived tables), INSERT/REPLACE
 * INTO, UPDATE ... SET (including JOIN'd targets), TRUNCATE TABLE, RENAME TABLE ... TO ...,
 * FLUSH TABLE(S), LOAD DATA ... INTO TABLE, and SHOW TABLES/COLUMNS/INDEX/CREATE TABLE.
 * Subqueries are followed recursively wherever they're found.
 */
function slowlog_extract_tables_from_query($query, &$tables = null) {
	if ($tables === null) {
		$tables = array();
	}

	$query = slowlog_normalize_query_text($query);

	if ($query === '') {
		return $tables;
	}

	if (preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^UPDATE\s+(.*?)\s+SET\b/is', $query, $m)) {
		slowlog_extract_table_ref_list($m[1], $tables);
	} elseif (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^RENAME\s+TABLE\s+(.*)$/i', $query, $m)) {
		foreach (slowlog_split_top_level($m[1], ',') as $pair) {
			if (preg_match('/^\s*(.+?)\s+TO\s+(.+)\s*$/i', $pair, $pm)) {
				$from = slowlog_first_identifier($pm[1]);
				$to   = slowlog_first_identifier($pm[2]);

				if ($from !== '') { $tables[$from] = $from; }
				if ($to !== '')   { $tables[$to]   = $to; }
			}
		}
	} elseif (preg_match('/^FLUSH\s+TABLES?\s+(.*)$/i', $query, $m)) {
		$rest = preg_replace('/\s+WITH\s+READ\s+LOCK\s*$/i', '', $m[1]);

		foreach (slowlog_split_top_level($rest, ',') as $t) {
			$id = slowlog_first_identifier($t);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}
	} elseif (preg_match('/^LOAD\s+DATA\b/i', $query) && preg_match('/\bINTO\s+TABLE\s+/i', $query, $m, PREG_OFFSET_CAPTURE)) {
		$id = slowlog_first_identifier(substr($query, $m[0][1] + strlen($m[0][0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^SHOW\s+CREATE\s+TABLE\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^SHOW\s+(?:FULL\s+)?(?:TABLES|COLUMNS|FIELDS|INDEX(?:ES)?|KEYS)\s+(?:FROM|IN)\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	}

	// Always look for FROM clauses and JOINs too - covers INSERT ... SELECT ... FROM,
	// subqueries in a WHERE/SET/etc, and multi-table DELETE/UPDATE ... JOIN forms.
	slowlog_extract_from_clauses($query, $tables);
	slowlog_extract_join_targets($query, $tables);

	return $tables;
}

/*
 * Pulls the numeric timeout out of a query using a MAX_EXECUTION_TIME(N) optimizer hint
 * (MySQL, milliseconds) or a max_statement_time=N wrapper (MariaDB, seconds), normalized to
 * seconds so it's comparable to query_time/lock_time. Returns null when neither is present.
 */
function slowlog_extract_timeout_value($query) {
	if (preg_match('/MAX_EXECUTION_TIME\s*\(\s*([0-9]+(?:\.[0-9]+)?)\s*\)/i', $query, $m)) {
		return round($m[1] / 1000, 6);
	}

	if (preg_match('/MAX_STATEMENT_TIME\s*=\s*([0-9]+(?:\.[0-9]+)?)/i', $query, $m)) {
		return (float) $m[1];
	}

	return null;
}

/* populates plugin_slowlog_details.timeout for any row using a MAX_EXECUTION_TIME/MAX_STATEMENT_TIME hint */
function slowlog_set_timeouts($logid, $logentry = -1) {
	$sql_where  = 'WHERE logid = ? AND (query LIKE ? OR query LIKE ?)';
	$sql_params = array($logid, '%MAX_EXECUTION_TIME(%', '%MAX_STATEMENT_TIME%');

	if ($logentry != -1) {
		$sql_where   .= ' AND logentry = ?';
		$sql_params[] = $logentry;
	}

	$rows = db_fetch_assoc_prepared("SELECT logentry, query
		FROM plugin_slowlog_details
		$sql_where",
		$sql_params);

	foreach($rows as $row) {
		$timeout = slowlog_extract_timeout_value($row['query']);

		if ($timeout !== null) {
			db_execute_prepared('UPDATE plugin_slowlog_details
				SET timeout = ?
				WHERE logid = ?
				AND logentry = ?',
				array($timeout, $logid, $row['logentry']));
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
