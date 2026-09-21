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

/*
 * Removes every per-logid row this plugin ever writes, across every table introduced since
 * v2.1 - including the v2.4 stats cache, which is easy to forget since it's the newest and
 * lives outside the original details/tables/methods set this function started with.
 */
function api_slowlog_remove($logid) {
	db_execute_prepared('DELETE FROM plugin_slowlog WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details_methods WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_stats WHERE logid = ?', array($logid));
}

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
 * Bulk-inserts (logid, logentry, methodid) rows into plugin_slowlog_details_methods in
 * chunks. Fully parameterized - logid/logentry/methodid are always bound placeholders, never
 * concatenated into the SQL text - so a value that reaches here without prior numeric
 * validation (e.g. --logid from the CLI) can't alter the VALUES clause.
 * $rows is an array of array($logid, $logentry, $methodid) tuples.
 */
function slowlog_bulk_insert_method_rows(array $rows) {
	if (!cacti_sizeof($rows)) {
		return;
	}

	$sql_prefix = 'INSERT INTO plugin_slowlog_details_methods (logid, logentry, methodid) VALUES ';
	$sql_suffix = ' ON DUPLICATE KEY UPDATE methodid=VALUES(methodid)';

	foreach(array_chunk($rows, 500) as $chunk) {
		$placeholders = array();
		$params       = array();

		foreach($chunk as $row) {
			$placeholders[] = '(?, ?, ?)';
			$params[]       = (int) $row[0];
			$params[]       = (int) $row[1];
			$params[]       = (int) $row[2];
		}

		db_execute_prepared($sql_prefix . implode(', ', $placeholders) . $sql_suffix, $params);
	}
}

/*
 * Bulk-inserts (logid, logentry, table_name) rows into plugin_slowlog_details_tables in
 * chunks. Fully parameterized for the same reason as slowlog_bulk_insert_method_rows() -
 * logid/logentry are always bound placeholders, never concatenated into the SQL text.
 * $rows is an array of array($logid, $logentry, $table_name) tuples.
 */
function slowlog_bulk_insert_table_rows(array $rows) {
	if (!cacti_sizeof($rows)) {
		return;
	}

	$sql_prefix = 'INSERT INTO plugin_slowlog_details_tables (logid, logentry, table_name) VALUES ';
	$sql_suffix = ' ON DUPLICATE KEY UPDATE table_name=VALUES(table_name)';

	foreach(array_chunk($rows, 500) as $chunk) {
		$placeholders = array();
		$params       = array();

		foreach($chunk as $row) {
			$placeholders[] = '(?, ?, ?)';
			$params[]       = (int) $row[0];
			$params[]       = (int) $row[1];
			$params[]       = (string) $row[2];
		}

		db_execute_prepared($sql_prefix . implode(', ', $placeholders) . $sql_suffix, $params);
	}
}

/* the 5 plugin_slowlog_details columns plugin_slowlog_stats tracks a distribution for */
const SLOWLOG_STATS_METRICS = array('query_time', 'rows_sent', 'rows_examined', 'rows_affected', 'bytes_sent');

/*
 * Linear-interpolation percentile (matches numpy's default / Excel PERCENTILE.INC) over an
 * already-sorted array of numeric values. $p is 0-100. Computed in PHP rather than via a SQL
 * PERCENTILE_CONT window function since that isn't available on every MySQL/MariaDB version
 * this plugin still supports.
 */
function slowlog_percentile(array $sorted, $p) {
	$n = count($sorted);

	if ($n === 0) {
		return 0.0;
	}

	if ($n === 1) {
		return (float) $sorted[0];
	}

	$rank = ($p / 100) * ($n - 1);
	$low  = (int) floor($rank);
	$high = (int) ceil($rank);

	if ($low === $high) {
		return (float) $sorted[$low];
	}

	return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low);
}

/*
 * Hard cap on how many raw samples per (scope, metric) the collectors below keep in memory at
 * once. Box-whisker percentiles are estimated from a bounded reservoir sample rather than
 * every row once a metric exceeds this many values, so memory no longer grows with the size
 * of the imported log; sample_count/total_value stay exact regardless (see
 * slowlog_accumulate_stat_value()).
 */
const SLOWLOG_STATS_SAMPLE_CAP = 20000;

/*
 * Reduces one metric's raw value list (any order) into the summary plugin_slowlog_stats
 * stores for it: sample count, sum, and the min/p25/median/p75/p95/max box-whisker points.
 * $exact_count/$exact_sum override the count/total derived from $values, for callers (e.g.
 * slowlog_compute_stats()) that only pass in a bounded sample of the real population.
 */
function slowlog_summarize_values(array $values, $exact_count = null, $exact_sum = null) {
	$count = ($exact_count !== null) ? $exact_count : count($values);

	if ($count === 0 || !count($values)) {
		return array(
			'sample_count' => 0,
			'total_value'  => 0.0,
			'min_value'    => 0.0,
			'p25_value'    => 0.0,
			'median_value' => 0.0,
			'p75_value'    => 0.0,
			'p95_value'    => 0.0,
			'max_value'    => 0.0,
		);
	}

	sort($values, SORT_NUMERIC);

	return array(
		'sample_count' => $count,
		'total_value'  => ($exact_sum !== null) ? $exact_sum : array_sum($values),
		'min_value'    => $values[0],
		'p25_value'    => slowlog_percentile($values, 25),
		'median_value' => slowlog_percentile($values, 50),
		'p75_value'    => slowlog_percentile($values, 75),
		'p95_value'    => slowlog_percentile($values, 95),
		'max_value'    => $values[count($values) - 1],
	);
}

/*
 * Records one metric value into the bounded reservoir $values[$scope_key][$metric] (capped at
 * SLOWLOG_STATS_SAMPLE_CAP elements via reservoir sampling) while $totals[$scope_key][$metric]
 * keeps an exact running count/sum, so sample_count/total_value never lose precision even
 * once the reservoir is full and older samples start being probabilistically replaced.
 */
function slowlog_accumulate_stat_value(array &$values, array &$totals, $scope_key, $metric, $value) {
	if (!isset($totals[$scope_key][$metric])) {
		$totals[$scope_key][$metric] = array('count' => 0, 'sum' => 0.0);
	}

	$totals[$scope_key][$metric]['count']++;
	$totals[$scope_key][$metric]['sum'] += $value;

	if (!isset($values[$scope_key][$metric])) {
		$values[$scope_key][$metric] = array();
	}

	if (count($values[$scope_key][$metric]) < SLOWLOG_STATS_SAMPLE_CAP) {
		$values[$scope_key][$metric][] = $value;
	} else {
		$slot = mt_rand(0, $totals[$scope_key][$metric]['count'] - 1);

		if ($slot < SLOWLOG_STATS_SAMPLE_CAP) {
			$values[$scope_key][$metric][$slot] = $value;
		}
	}
}

/*
 * Bulk-inserts plugin_slowlog_stats rows, fully parameterized like the method/table bulk
 * inserters above. $rows is an array of the assoc arrays slowlog_summarize_values() returns,
 * each additionally carrying 'logid', 'scope', 'scope_key', and 'metric'.
 */
function slowlog_bulk_insert_stats_rows(array $rows) {
	if (!cacti_sizeof($rows)) {
		return;
	}

	$sql_prefix = 'INSERT INTO plugin_slowlog_stats
		(logid, scope, scope_key, metric, sample_count, total_value, min_value, p25_value, median_value, p75_value, p95_value, max_value)
		VALUES ';
	$sql_suffix = ' ON DUPLICATE KEY UPDATE
		sample_count = VALUES(sample_count),
		total_value  = VALUES(total_value),
		min_value    = VALUES(min_value),
		p25_value    = VALUES(p25_value),
		median_value = VALUES(median_value),
		p75_value    = VALUES(p75_value),
		p95_value    = VALUES(p95_value),
		max_value    = VALUES(max_value)';

	foreach(array_chunk($rows, 200) as $chunk) {
		$placeholders = array();
		$params       = array();

		foreach($chunk as $row) {
			$placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
			$params[]       = (int) $row['logid'];
			$params[]       = (string) $row['scope'];
			$params[]       = (string) $row['scope_key'];
			$params[]       = (string) $row['metric'];
			$params[]       = (int) $row['sample_count'];
			$params[]       = (float) $row['total_value'];
			$params[]       = (float) $row['min_value'];
			$params[]       = (float) $row['p25_value'];
			$params[]       = (float) $row['median_value'];
			$params[]       = (float) $row['p75_value'];
			$params[]       = (float) $row['p95_value'];
			$params[]       = (float) $row['max_value'];
		}

		db_execute_prepared($sql_prefix . implode(', ', $placeholders) . $sql_suffix, $params);
	}
}

/*
 * Streams every (method, metric-values) pair for $logid in chunks and accumulates each
 * metric's values per method via slowlog_accumulate_stat_value() (bounded reservoir in
 * $values, exact running count/sum in $totals) so the caller can summarize them once every
 * chunk has been read without memory growing with the size of the imported log. A logentry
 * matching more than one method contributes its values to every matched method, same as the
 * raw-totals chart's GROUP BY sm.methodid. Paginated by plugin_slowlog_details_methods.id (a
 * unique, strictly increasing surrogate key) rather than logentry - logentry alone isn't
 * unique here (one logentry can have several method rows), so a page boundary landing inside
 * such a group would otherwise skip the remaining rows for that logentry once the next page
 * filters with "id/logentry > ?".
 */
function slowlog_collect_stats_by_method($logid, array &$values, $chunk_size = 5000, array &$totals = array()) {
	$last_id = 0;

	do {
		$rows = db_fetch_assoc_prepared('SELECT sldm.id, sm.method AS scope_key,
			d.query_time, d.rows_sent, d.rows_examined, d.rows_affected, d.bytes_sent
			FROM plugin_slowlog_details_methods AS sldm
			INNER JOIN plugin_slowlog_methods AS sm ON sm.methodid = sldm.methodid
			INNER JOIN plugin_slowlog_details AS d ON d.logid = sldm.logid AND d.logentry = sldm.logentry
			WHERE sldm.logid = ?
			AND sldm.id > ?
			ORDER BY sldm.id
			LIMIT ' . (int) $chunk_size,
			array($logid, $last_id));

		$batch_count = cacti_sizeof($rows);

		foreach($rows as $row) {
			$last_id = $row['id'];

			foreach(SLOWLOG_STATS_METRICS as $metric) {
				slowlog_accumulate_stat_value($values, $totals, $row['scope_key'], $metric, (float) $row[$metric]);
			}
		}
	} while ($batch_count === $chunk_size);
}

/*
 * Same as slowlog_collect_stats_by_method(), but grouped by table_name - including an
 * 'others' bucket for entries with no recognized table, matching the label
 * slowlog_get_chart_object() already uses for that bucket in the raw-totals chart. Split into
 * two independently-paginated queries rather than one UNION ALL cursored on the shared (and,
 * for the matched branch, non-unique) logentry column: the matched branch pages on
 * plugin_slowlog_details_tables.tableid (unique - a logentry can have several table rows,
 * same pitfall as the method collector above), while the "others" branch pages on
 * plugin_slowlog_details.logentry, which - unlike the matched branch - really is unique there
 * (a logentry with no table match can only ever produce one row via the LEFT JOIN).
 */
function slowlog_collect_stats_by_table($logid, array &$values, $chunk_size = 5000, array &$totals = array()) {
	slowlog_collect_stats_by_matched_table($logid, $values, $chunk_size, $totals);
	slowlog_collect_stats_by_unmatched_table($logid, $values, $chunk_size, $totals);
}

function slowlog_collect_stats_by_matched_table($logid, array &$values, $chunk_size = 5000, array &$totals = array()) {
	$last_id = 0;

	do {
		$rows = db_fetch_assoc_prepared('SELECT sldt.tableid, sldt.table_name AS scope_key,
			d.query_time, d.rows_sent, d.rows_examined, d.rows_affected, d.bytes_sent
			FROM plugin_slowlog_details_tables AS sldt
			INNER JOIN plugin_slowlog_details AS d ON d.logid = sldt.logid AND d.logentry = sldt.logentry
			WHERE sldt.logid = ?
			AND sldt.tableid > ?
			ORDER BY sldt.tableid
			LIMIT ' . (int) $chunk_size,
			array($logid, $last_id));

		$batch_count = cacti_sizeof($rows);

		foreach($rows as $row) {
			$last_id = $row['tableid'];

			foreach(SLOWLOG_STATS_METRICS as $metric) {
				slowlog_accumulate_stat_value($values, $totals, $row['scope_key'], $metric, (float) $row[$metric]);
			}
		}
	} while ($batch_count === $chunk_size);
}

function slowlog_collect_stats_by_unmatched_table($logid, array &$values, $chunk_size = 5000, array &$totals = array()) {
	$last_logentry = 0;

	do {
		$rows = db_fetch_assoc_prepared('SELECT d.logentry,
				d.query_time, d.rows_sent, d.rows_examined, d.rows_affected, d.bytes_sent
			FROM plugin_slowlog_details AS d
			LEFT JOIN plugin_slowlog_details_tables AS sldt ON sldt.logid = d.logid AND sldt.logentry = d.logentry
			WHERE d.logid = ?
			AND d.logentry > ?
			AND sldt.table_name IS NULL
			ORDER BY d.logentry
			LIMIT ' . (int) $chunk_size,
			array($logid, $last_logentry));

		$batch_count = cacti_sizeof($rows);

		foreach($rows as $row) {
			$last_logentry = $row['logentry'];

			foreach(SLOWLOG_STATS_METRICS as $metric) {
				slowlog_accumulate_stat_value($values, $totals, 'others', $metric, (float) $row[$metric]);
			}
		}
	} while ($batch_count === $chunk_size);
}

/*
 * Computes and caches box-whisker + total statistics (plugin_slowlog_stats) for every method
 * and table $logid's queries were classified under, across the 5 metrics in
 * SLOWLOG_STATS_METRICS. Run once at the end of import_post_process()/slowlog_reprocess() so
 * the By Method/By Table chart pages are a single indexed lookup instead of a live aggregate
 * query over plugin_slowlog_details.
 */
function slowlog_compute_stats($logid) {
	$start = microtime(true);

	$by_method     = array();
	$by_table      = array();
	$method_totals = array();
	$table_totals  = array();

	slowlog_collect_stats_by_method($logid, $by_method, 5000, $method_totals);
	slowlog_collect_stats_by_table($logid, $by_table, 5000, $table_totals);

	$stat_rows = array();

	$scopes = array(
		'method' => array('values' => $by_method, 'totals' => $method_totals),
		'table'  => array('values' => $by_table, 'totals' => $table_totals),
	);

	foreach($scopes as $scope => $scope_data) {
		foreach($scope_data['values'] as $scope_key => $metrics) {
			foreach($metrics as $metric => $raw_values) {
				$totals  = $scope_data['totals'][$scope_key][$metric];
				$summary = slowlog_summarize_values($raw_values, $totals['count'], $totals['sum']);

				$summary['logid']     = $logid;
				$summary['scope']     = $scope;
				$summary['scope_key'] = $scope_key;
				$summary['metric']    = $metric;

				$stat_rows[] = $summary;
			}
		}
	}

	slowlog_bulk_insert_stats_rows($stat_rows);

	$end = microtime(true);

	cacti_log(sprintf('STATS: Time:%0.2f, Stats Cache Complete for %s', $end-$start, $logid), false, 'SLOWLOG');
}

/*
 * Keeps plugin_slowlog_table_names (the deduplicated table_name dictionary) in sync with
 * whatever table association just found for this logid. $known_tables is either null (we
 * have no reference list to compare against - a row is added if missing via INSERT IGNORE,
 * but any previously-determined is_cacti_table value is left alone rather than being reset to
 * "unknown") or an array of table names to compare against, in which case is_cacti_table is
 * set/updated accordingly.
 *
 * Only ever pass the live Cacti DB's table list (i.e. 'cacti' table-detection mode) here -
 * that's a stable, global source of truth safe to share across every log. Never pass a
 * user-supplied 'reference' list: it's specific to one import, and a later reference import
 * with a different list would silently flip this shared, global flag and corrupt
 * classification for every other log that references the same table name. Use
 * slowlog_classify_other_tables_against_list() for reference-mode classification instead,
 * which compares per-log without touching this shared dictionary.
 */
function slowlog_sync_table_dictionary($logid, $known_tables = null) {
	$tables = db_fetch_assoc_prepared('SELECT DISTINCT table_name
		FROM plugin_slowlog_details_tables
		WHERE logid = ?',
		array($logid));

	if (!cacti_sizeof($tables)) {
		return;
	}

	if ($known_tables !== null) {
		$known_lookup = array_flip($known_tables);
	}

	foreach($tables as $row) {
		$t = $row['table_name'];

		if ($known_tables !== null) {
			db_execute_prepared('INSERT INTO plugin_slowlog_table_names
				(table_name, is_cacti_table)
				VALUES (?, ?)
				ON DUPLICATE KEY UPDATE is_cacti_table = VALUES(is_cacti_table)',
				array($t, isset($known_lookup[$t]) ? 1 : 0));
		} else {
			db_execute_prepared('INSERT IGNORE INTO plugin_slowlog_table_names
				(table_name)
				VALUES (?)',
				array($t));
		}
	}
}

/*
 * Tags any logentry that references at least one table not recognized as a Cacti table
 * (per plugin_slowlog_table_names.is_cacti_table, just refreshed by
 * slowlog_sync_table_dictionary()) with the 'OTHER TABLES' method - a separate concept from
 * the 'OTHERS' method, which flags a query that didn't match any known SQL construct at all.
 * Only meaningful once is_cacti_table has actually been determined, so import_post_process()
 * only calls this for the 'cacti'/'reference' table modes (i.e. whenever a reference list of
 * known tables was actually available), never for 'list'/'all'.
 */
function slowlog_classify_other_tables($logid) {
	$methodid = db_fetch_cell_prepared("SELECT methodid
		FROM plugin_slowlog_methods
		WHERE method = 'OTHER TABLES'",
		array());

	if (!$methodid) {
		return;
	}

	$rows = db_fetch_assoc_prepared('SELECT DISTINCT dt.logentry
		FROM plugin_slowlog_details_tables AS dt
		INNER JOIN plugin_slowlog_table_names AS tn
		ON tn.table_name = dt.table_name
		WHERE dt.logid = ?
		AND tn.is_cacti_table = 0',
		array($logid));

	if (!cacti_sizeof($rows)) {
		return;
	}

	$method_rows = array();

	foreach($rows as $row) {
		$method_rows[] = array($logid, $row['logentry'], $methodid);
	}

	slowlog_bulk_insert_method_rows($method_rows);
}

/*
 * Same 'OTHER TABLES' tagging as slowlog_classify_other_tables(), but compares this log's
 * table associations directly against a caller-supplied reference list instead of the shared
 * plugin_slowlog_table_names.is_cacti_table flag. Used for 'reference' table-detection mode,
 * where the list is specific to one import and must never be written into that shared,
 * global dictionary (see slowlog_sync_table_dictionary()).
 */
function slowlog_classify_other_tables_against_list($logid, array $reference_tables) {
	$methodid = db_fetch_cell_prepared("SELECT methodid
		FROM plugin_slowlog_methods
		WHERE method = 'OTHER TABLES'",
		array());

	if (!$methodid) {
		return;
	}

	$tables = db_fetch_assoc_prepared('SELECT DISTINCT table_name
		FROM plugin_slowlog_details_tables
		WHERE logid = ?',
		array($logid));

	if (!cacti_sizeof($tables)) {
		return;
	}

	$known_lookup = array_flip($reference_tables);
	$other_tables = array();

	foreach($tables as $row) {
		if (!isset($known_lookup[$row['table_name']])) {
			$other_tables[] = $row['table_name'];
		}
	}

	if (!cacti_sizeof($other_tables)) {
		return;
	}

	$placeholders = implode(', ', array_fill(0, count($other_tables), '?'));

	$rows = db_fetch_assoc_prepared('SELECT DISTINCT logentry
		FROM plugin_slowlog_details_tables
		WHERE logid = ?
		AND table_name IN (' . $placeholders . ')',
		array_merge(array($logid), $other_tables));

	if (!cacti_sizeof($rows)) {
		return;
	}

	$method_rows = array();

	foreach($rows as $row) {
		$method_rows[] = array($logid, $row['logentry'], $methodid);
	}

	slowlog_bulk_insert_method_rows($method_rows);
}


function import_logfile($logfile, $description = 'Imported using import_log utility', $length = 8192, $table_names = '', $usecacti = false, $batch = true, $table_mode = null, $logid = null) {
	global $config;

	ini_set('max_execution_time', 0);

	// Normalize the legacy --usecacti flag to explicit 'cacti' mode before
	// auto-populating $table_names, so import_post_process() infers 'cacti'
	// mode (external-table discovery) instead of 'list' mode.
	if ($table_mode === null && $usecacti) {
		$table_mode = 'cacti';
	}

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
						// A caller (the web upload handler) may have already inserted the parent
						// record up front so the UI has something to show immediately - only
						// create one here if it didn't.
						if ($logid === null) {
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

		// No '# Time:' line was ever found - this isn't a recognizable MySQL/MariaDB
		// slow query log, so there's nothing to post-process. Report it rather than
		// leaving the (possibly pre-created) parent record stuck at Pre-Processing.
		if (!$start) {
			if ($logid !== null) {
				db_execute_prepared('UPDATE plugin_slowlog
					SET import_status = 3,
					import_text_status = ?
					WHERE logid = ?',
					array(__('Bad File Format - No Slow Query Log Entries Found', 'slowlog'), $logid));
			} else {
				print "FATAL: Bad File Format - No Slow Query Log Entries Found in '$logfile'\n";
			}

			return;
		}

		if ($batch) {
			raise_message('import_pre', __('The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete', 'slowlog'), MESSAGE_LEVEL_INFO);

			$php = cacti_escapeshellcmd(read_config_option('path_php_binary'));

			$cmd = $config['base_path'] . "/plugins/slowlog/import_log.php --logid=$logid";
			$cmd .= $usecacti ? ' --usecacti' : '';
			$cmd .= $table_mode !== null ? ' --table-mode=' . cacti_escapeshellarg($table_mode) : '';
			$cmd .= trim($table_names) !== '' ? ' --table-names=' . cacti_escapeshellarg(trim($table_names)) : '';

			exec_background($php, $cmd);

			db_execute_prepared('UPDATE plugin_slowlog
				SET import_text_status = ?
				WHERE logid = ?',
				array('Post Processing with Table Detection', $logid));
		} else {
			import_post_process($logid, $table_names, $usecacti, $table_mode);
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

function import_post_process($logid, $table_names = '', $usecacti = false, $table_mode = null) {
	// Preserve legacy precedence (explicit list wins, then usecacti, then auto-detect) when a
	// caller doesn't pass $table_mode explicitly - only the new 3-option UI ever passes it.
	if ($table_mode === null) {
		if ($table_names != '') {
			$table_mode = 'list';
		} elseif ($usecacti) {
			$table_mode = 'cacti';
		} else {
			$table_mode = 'all';
		}
	}

	$records = db_fetch_cell_prepared('SELECT COUNT(*)
		FROM plugin_slowlog_details
		WHERE logid = ?',
		array($logid));

	if ($records > 0) {
		$start = microtime(true);

		/*
		 * Classify every row by method in a single pass: fetched in bounded chunks (rather
		 * than materializing the whole log's query text in PHP at once, which for an
		 * unbounded slow-query log could exhaust the worker's memory) and matched against
		 * each method's fragments in PHP (stripos - LIKE '%frag%' was case-insensitive by
		 * default too), instead of one LIKE/NOT LIKE table scan per method (~20 round trips
		 * previously for the default method dictionary).
		 */
		$methods = db_fetch_assoc_prepared('SELECT *
			FROM plugin_slowlog_methods
			ORDER BY method',
			array());

		$method_fragments = array();
		$others_methodid   = null;

		foreach($methods as $row) {
			// OTHERS is the "matched nothing else" bucket; OTHER TABLES is classified
			// separately below (by table recognition, not a query text fragment).
			if ($row['method'] == 'OTHERS') {
				$others_methodid = $row['methodid'];
			} elseif ($row['method'] != 'OTHER TABLES') {
				$method_fragments[$row['methodid']] = explode(',', $row['query']);
			}
		}

		$method_chunk_size = 2000;
		$last_logentry     = 0;

		do {
			$detail_rows = db_fetch_assoc_prepared('SELECT logentry, query
				FROM plugin_slowlog_details
				WHERE logid = ?
				AND logentry > ?
				ORDER BY logentry
				LIMIT ' . (int) $method_chunk_size,
				array($logid, $last_logentry));

			$batch_count = cacti_sizeof($detail_rows);
			$method_rows = array();

			foreach($detail_rows as $row) {
				$last_logentry = $row['logentry'];
				$matched       = false;

				foreach($method_fragments as $methodid => $fragments) {
					foreach($fragments as $fragment) {
						if (stripos($row['query'], $fragment) !== false) {
							$method_rows[] = array($logid, $row['logentry'], $methodid);
							$matched = true;

							break;
						}
					}
				}

				if (!$matched && $others_methodid !== null) {
					$method_rows[] = array($logid, $row['logentry'], $others_methodid);
				}
			}

			slowlog_bulk_insert_method_rows($method_rows);
		} while ($batch_count === $method_chunk_size);

		$end = microtime(true);

		cacti_log(sprintf('STATS: Time:%0.2f, Pre-Processing for Methods Complete for %s', $end-$start, $logid), false, 'SLOWLOG');

		$start = microtime(true);

		// perform table name analysis
		$tables       = array();
		$known_tables = null;

		if ($table_mode == 'list') {
			$tables = explode(' ', trim($table_names));
		} elseif ($table_mode == 'cacti') {
			// must run the tokenizer here (not the old known-tables-only LIKE scan) so that
			// tables NOT in get_cacti_tables() are actually discovered and can be classified
			// as OTHER TABLES below - scanning only for known tables can never find "other".
			get_table_associations($logid);

			$known_tables = explode(' ', trim(get_cacti_tables()));
		} elseif ($table_mode == 'reference') {
			get_table_associations($logid);

			$known_tables = explode(' ', trim(db_fetch_cell_prepared('SELECT import_tables FROM plugin_slowlog WHERE logid = ?', array($logid))));
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

		if ($table_mode == 'reference') {
			// Registers any newly-seen table names, but never touches the shared
			// is_cacti_table flag for them - that column must stay reserved for the live
			// Cacti DB (see slowlog_sync_table_dictionary()'s doc comment).
			slowlog_sync_table_dictionary($logid);
			slowlog_classify_other_tables_against_list($logid, $known_tables);
		} else {
			slowlog_sync_table_dictionary($logid, $known_tables);

			if ($known_tables !== null) {
				slowlog_classify_other_tables($logid);
			}
		}

		slowlog_set_timeouts($logid);

		slowlog_compute_stats($logid);
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
function slowlog_reprocess($logid, $table_names = '', $usecacti = false, $table_mode = null) {
	db_execute_prepared('DELETE FROM plugin_slowlog_details_methods WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_stats WHERE logid = ?', array($logid));
	db_execute_prepared('UPDATE plugin_slowlog_details SET timeout = 0 WHERE logid = ?', array($logid));

	db_execute_prepared('UPDATE plugin_slowlog
		SET import_status = 1,
		import_text_status = ?
		WHERE logid = ?',
		array('Reprocessing', $logid));

	cacti_log("NOTE: Reprocessing logid $logid", false, 'SLOWLOG');

	import_post_process($logid, $table_names, $usecacti, $table_mode);
}

/* slowlog_reprocess() for every logid currently in plugin_slowlog */
function slowlog_reprocess_all($table_names = '', $usecacti = false, $table_mode = null) {
	$logids = db_fetch_assoc_prepared('SELECT logid FROM plugin_slowlog', array());

	foreach($logids as $row) {
		slowlog_reprocess($row['logid'], $table_names, $usecacti, $table_mode);
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
	$rows_out = array();

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
				$rows_out[] = array($logid, $row['logentry'], $t);
			}
		} else {
			slowlog_debug('No tables found: ' . substr($row['query'], 0, 4000));
		}
	}

	if (cacti_sizeof($rows_out)) {
		cacti_log('Post Processing Tables associated: ' . cacti_sizeof($rows_out), false, 'SLOWLOG');

		slowlog_bulk_insert_table_rows($rows_out);
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
 * Replaces the content of single/double-quoted string literals with same-length filler
 * (keeping the delimiters and any newlines intact), so keyword/pattern scanning elsewhere
 * can't be fooled by SQL-looking text inside a string constant. Backtick-quoted identifiers
 * are left untouched since they're table/column names, not string content.
 */
function slowlog_mask_quoted_strings($query) {
	$len  = strlen($query);
	$out  = '';
	$i    = 0;
	$in_s = null;

	while ($i < $len) {
		$ch = $query[$i];

		if ($in_s !== null) {
			if ($ch === '\\' && $i + 1 < $len) {
				$out .= '  ';
				$i   += 2;

				continue;
			}

			if ($ch === $in_s) {
				if ($i + 1 < $len && $query[$i + 1] === $in_s) {
					// a doubled quote ('' or "") is an escaped quote, still inside the string
					$out .= '  ';
					$i   += 2;

					continue;
				}

				$in_s = null;
				$out .= $ch;
				$i++;

				continue;
			}

			$out .= ($ch === "\n" || $ch === "\t") ? $ch : ' ';
			$i++;

			continue;
		}

		if ($ch === "'" || $ch === '"') {
			$in_s = $ch;
			$out .= $ch;
			$i++;

			continue;
		}

		$out .= $ch;
		$i++;
	}

	return $out;
}

/*
 * Replaces --/#/\/* *\/ SQL comments with same-length filler. Run this after
 * slowlog_mask_quoted_strings() so a comment-looking sequence inside a string literal isn't
 * mistaken for a real comment.
 */
function slowlog_mask_sql_comments($query) {
	$len = strlen($query);
	$out = '';
	$i   = 0;

	while ($i < $len) {
		// MySQL/MariaDB only treat "--" as a comment starter when the second
		// dash is followed by whitespace/a control character (or end of
		// string) - otherwise "a--b" is a valid expression, not a comment.
		if ($query[$i] === '-' && $i + 1 < $len && $query[$i + 1] === '-' &&
			($i + 2 >= $len || ctype_space($query[$i + 2]))) {
			$end = strpos($query, "\n", $i);
			$end = ($end === false) ? $len : $end;
			$out .= str_repeat(' ', $end - $i);
			$i    = $end;

			continue;
		}

		if ($query[$i] === '#') {
			$end = strpos($query, "\n", $i);
			$end = ($end === false) ? $len : $end;
			$out .= str_repeat(' ', $end - $i);
			$i    = $end;

			continue;
		}

		if ($query[$i] === '/' && $i + 1 < $len && $query[$i + 1] === '*') {
			$end = strpos($query, '*/', $i + 2);
			$end = ($end === false) ? $len : $end + 2;
			$out .= str_repeat(' ', $end - $i);
			$i    = $end;

			continue;
		}

		$out .= $query[$i];
		$i++;
	}

	return $out;
}

/*
 * Masks both string literals and comments (in that order) so the table/JOIN scanner below
 * can't mistake SQL-looking text inside either one for a real clause - e.g.
 * "SELECT 1 /* FROM admins * /" no longer looks like it references a table named admins.
 */
function slowlog_mask_strings_and_comments($query) {
	return slowlog_mask_sql_comments(slowlog_mask_quoted_strings($query));
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

			// $balanced[1] begins with the derived table's own "[AS] alias ON
			// ..." - that alias names the subquery just extracted, not a real
			// table, so fall through to the JOIN-keyword search below instead
			// of looping back and mistaking the alias for an identifier.
			$text = $balanced[1];
		} else {
			$id = slowlog_first_identifier($text);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}

		if (!preg_match('/\s+' . SLOWLOG_JOIN_KEYWORD . '\s+/i', $text, $m, PREG_OFFSET_CAPTURE)) {
			return;
		}

		$text = substr($text, $m[0][1] + strlen($m[0][0]));
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
 * Finds a top-level "USING <table_ref_list>" clause (the multi-table DELETE FROM ... USING
 * form) and extracts every table it lists, including comma-separated ones. A JOIN's
 * "USING (col1, col2)" column list is skipped instead, since it's always immediately
 * followed by an open paren rather than a bare identifier.
 */
function slowlog_extract_using_clause_tables($query, &$tables) {
	$offset = 0;
	$len    = strlen($query);

	while ($offset < $len && preg_match('/\bUSING\b/i', $query, $m, PREG_OFFSET_CAPTURE, $offset)) {
		$pos    = $m[0][1];
		$after  = substr($query, $pos + 5);
		$padlen = strlen($after) - strlen(ltrim($after));
		$start  = $pos + 5 + $padlen;

		if ($start >= $len || $query[$start] === '(') {
			$offset = $pos + 5;

			continue;
		}

		$end = slowlog_scan_clause_span($query, $start);

		slowlog_extract_table_ref_list(substr($query, $start, $end - $start), $tables);

		$offset = max($end, $pos + 5);
	}
}

/*
 * Determines every table referenced by a (normalized, single-line) SQL statement:
 * SELECT/DELETE FROM lists, JOINs (including chains and derived tables), INSERT/REPLACE
 * INTO, UPDATE ... SET (including JOIN'd targets), TRUNCATE TABLE, CREATE TABLE (including its
 * source table when cloned via LIKE), DROP TABLE, ALTER TABLE, ANALYZE/OPTIMIZE/CHECK/REPAIR
 * TABLE, RENAME TABLE ... TO ..., FLUSH TABLE(S), LOAD DATA ... INTO TABLE, and SHOW
 * TABLES/COLUMNS/INDEX/CREATE TABLE. Subqueries are followed recursively wherever they're found.
 */
function slowlog_extract_tables_from_query($query, &$tables = null) {
	if ($tables === null) {
		$tables = array();
	}

	$query = slowlog_normalize_query_text(slowlog_mask_strings_and_comments((string) $query));

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
	} elseif (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $query, $m)) {
		$rest = substr($query, strlen($m[0]));
		$id   = slowlog_first_identifier($rest);

		if ($id !== '') {
			$tables[$id] = $id;
		}

		// "CREATE TABLE new LIKE existing" clones another table's structure - only look for a
		// top-level LIKE (before any column-definition parenthesis), so a LIKE comparison buried
		// inside a column's DEFAULT/CHECK expression isn't mistaken for this clause.
		$paren_pos = strpos($rest, '(');

		if (preg_match('/\bLIKE\s+/i', $rest, $lm, PREG_OFFSET_CAPTURE) && ($paren_pos === false || $lm[0][1] < $paren_pos)) {
			$like_id = slowlog_first_identifier(substr($rest, $lm[0][1] + strlen($lm[0][0])));

			if ($like_id !== '') {
				$tables[$like_id] = $like_id;
			}
		}
	} elseif (preg_match('/^DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?/i', $query, $m)) {
		foreach (slowlog_split_top_level(substr($query, strlen($m[0])), ',') as $t) {
			$id = slowlog_first_identifier($t);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}
	} elseif (preg_match('/^ALTER\s+TABLE\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^(?:ANALYZE|OPTIMIZE|CHECK|REPAIR)\s+(?:NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLE\s+/i', $query, $m)) {
		foreach (slowlog_split_top_level(substr($query, strlen($m[0])), ',') as $t) {
			$id = slowlog_first_identifier($t);

			if ($id !== '') {
				$tables[$id] = $id;
			}
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
	slowlog_extract_using_clause_tables($query, $tables);

	return $tables;
}

/*
 * Pulls the numeric timeout out of a query using a MAX_EXECUTION_TIME(N) optimizer hint
 * (MySQL, milliseconds) or a MariaDB `SET STATEMENT max_statement_time=N FOR ...` wrapper
 * (seconds), normalized to seconds so it's comparable to query_time/lock_time. Restricted to
 * these specific syntactic forms - a SELECT-leading optimizer hint comment, or a leading SET
 * STATEMENT wrapper - rather than matching the text anywhere in the query, so a string
 * literal or an ordinary comment merely containing this text isn't mistaken for a real
 * optimizer hint. Returns null when neither is present.
 */
function slowlog_extract_timeout_value($query) {
	$query = slowlog_normalize_query_text(slowlog_mask_quoted_strings((string) $query));

	if (preg_match('/^SELECT\s+\/\*\+.*?MAX_EXECUTION_TIME\s*\(\s*([0-9]+(?:\.[0-9]+)?)\s*\).*?\*\//is', $query, $m)) {
		return round($m[1] / 1000, 6);
	}

	if (preg_match('/^SET\s+STATEMENT\s+MAX_STATEMENT_TIME\s*=\s*([0-9]+(?:\.[0-9]+)?)\s+FOR\b/i', $query, $m)) {
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

/* parses a php.ini shorthand byte value (e.g. '8M', '2G', '-1'); returns null for unlimited */
function slowlog_parse_ini_bytes($value) {
	$value = trim((string) $value);

	if ($value === '' || $value === '-1') {
		return null;
	}

	$unit = strtolower(substr($value, -1));
	$num  = (float) $value;

	switch ($unit) {
		case 'g':
			return (int) ($num * 1024 * 1024 * 1024);
		case 'm':
			return (int) ($num * 1024 * 1024);
		case 'k':
			return (int) ($num * 1024);
		default:
			return (int) $value;
	}
}

/*
 * Checks the current request's upload-related php.ini settings for anything likely to make a
 * large slow-query-log import fail, so slowlog_import() can surface it before the user even
 * tries. This plugin already forces max_execution_time/memory_limit to unlimited for its own
 * requests (see the ini_set() calls at the top of slowlog.php), so those are only flagged if
 * that override didn't actually take (e.g. blocked by disable_functions or an open_basedir/
 * PHP_INI_SYSTEM restriction on the host) - and even when it does take, an independent web
 * server/proxy timeout (Apache Timeout, Nginx fastcgi_read_timeout/proxy_read_timeout, PHP-FPM
 * request_terminate_timeout) is outside PHP's control and can't be detected from here.
 */
function slowlog_upload_environment_status() {
	$max_execution_time = ini_get('max_execution_time');
	$memory_limit       = ini_get('memory_limit');
	$upload_max_filesize = ini_get('upload_max_filesize');
	$post_max_size        = ini_get('post_max_size');

	$upload_max_bytes = slowlog_parse_ini_bytes($upload_max_filesize);
	$post_max_bytes    = slowlog_parse_ini_bytes($post_max_size);

	$warnings = array();

	if ((int) $max_execution_time !== 0) {
		$warnings[] = __('max_execution_time is %d seconds (not unlimited) for this request - a large import could be killed mid-run. Some web servers/proxies (Apache Timeout, Nginx fastcgi_read_timeout/proxy_read_timeout, PHP-FPM request_terminate_timeout) enforce their own independent cutoff that this setting cannot override.', $max_execution_time, 'slowlog');
	}

	if ($memory_limit !== '-1') {
		$warnings[] = __('memory_limit is %s (not unlimited) for this request - a very large slow query log can exceed this while it is being read/imported.', $memory_limit, 'slowlog');
	}

	if ($post_max_bytes !== null && $upload_max_bytes !== null && $post_max_bytes < $upload_max_bytes) {
		$warnings[] = __('post_max_size (%s) is smaller than upload_max_filesize (%s) - uploads up to the advertised limit will still be rejected.', $post_max_size, $upload_max_filesize, 'slowlog');
	}

	if ($upload_max_bytes !== null && $upload_max_bytes < (64 * 1024 * 1024)) {
		$warnings[] = __('upload_max_filesize (%s) is small for a MySQL/MariaDB slow query log, which can easily be hundreds of MB.', $upload_max_filesize, 'slowlog');
	}

	return array(
		'max_execution_time' => $max_execution_time,
		'memory_limit'       => $memory_limit,
		'warnings'           => $warnings,
	);
}

/*
 * Shared unit/suffix labels for each summable metric, used by both the raw-totals chart
 * (slowlog_get_chart_object()) and the box-whisker distribution chart
 * (slowlog_get_stats_chart_object()) so the two stay in sync.
 */
function slowlog_chart_measures() {
	return array(
		'count' => array(
			'unit'   => __esc('Queries', 'slowlog'),
			'suffix' => __esc('Total Queries', 'slowlog')
		),
		'rows_sent' => array(
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Returned', 'slowlog')
		),
		'rows_examined' => array(
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Examined', 'slowlog')
		),
		'lock_time' => array(
			'unit'   => __esc('Seconds', 'slowlog'),
			'suffix' => __esc('Lock Seconds', 'slowlog')
		),
		'query_time' => array(
			'unit'   => __esc('Seconds', 'slowlog'),
			'suffix' => __esc('Query Seconds', 'slowlog')
		),
		'rows_affected' => array(
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Affected', 'slowlog')
		),
		'bytes_sent' => array(
			'unit'   => __esc('Bytes', 'slowlog'),
			'suffix' => __esc('Bytes Sent', 'slowlog')
		)
	);
}

/*
 * Reads the cached box-whisker summary (plugin_slowlog_stats) for one metric, scoped to
 * either methods or tables, and shapes it into the categories/box-data/p95-data arrays
 * renderBoxChart() expects. Ordered/limited the same way as slowlog_get_chart_object() (by
 * total value descending, top 10 for tables) so the raw and whisker charts for the same
 * metric show categories in the same order.
 */
function slowlog_get_stats_chart_object($chart_type, $measure) {
	$id = get_filter_request_var('logid');

	$description = db_fetch_cell_prepared('SELECT description
		FROM plugin_slowlog
		WHERE logid = ?',
		array($id));

	$scope = ($chart_type != 'tables') ? 'method' : 'table';
	$limit = ($scope == 'table') ? ' LIMIT 10' : '';

	$stats = db_fetch_assoc_prepared('SELECT scope_key, sample_count, total_value,
			min_value, p25_value, median_value, p75_value, p95_value, max_value
		FROM plugin_slowlog_stats
		WHERE logid = ?
		AND scope = ?
		AND metric = ?
		ORDER BY total_value DESC' . $limit,
		array($id, $scope, $measure));

	$measures = slowlog_chart_measures();

	$categories = array();
	$box_data   = array();
	$p95_data   = array();

	foreach($stats as $row) {
		$categories[] = $row['scope_key'];

		$box_data[] = array(
			'x' => $row['scope_key'],
			'y' => array(
				round((float) $row['min_value'], 3),
				round((float) $row['p25_value'], 3),
				round((float) $row['median_value'], 3),
				round((float) $row['p75_value'], 3),
				round((float) $row['max_value'], 3)
			)
		);

		$p95_data[] = array(
			'x' => $row['scope_key'],
			'y' => round((float) $row['p95_value'], 3)
		);
	}

	$title = $description . ' [ ' . $measures[$measure]['suffix'] . ' Distribution ]';

	return array(
		'title'      => $title,
		'categories' => $categories,
		'box_data'   => $box_data,
		'p95_data'   => $p95_data,
		'yaxislabel' => $measures[$measure]['unit']
	);
}

/*
 * Reads the cached totals (plugin_slowlog_stats) for one metric, scoped to either methods or
 * tables, and shapes them into the categories/values arrays renderChart() expects. Reuses the
 * same stats cache the box-whisker chart (slowlog_get_stats_chart_object()) reads, rather than
 * live-aggregating plugin_slowlog_details on every chart view.
 */
function slowlog_get_chart_object($chart_type, $measure) {
	$id = get_filter_request_var('logid');

	$description = db_fetch_cell_prepared('SELECT description
		FROM plugin_slowlog
		WHERE logid = ?',
		array($id));

	$scope = ($chart_type != 'tables') ? 'method' : 'table';
	$limit = ($scope == 'table') ? ' LIMIT 10' : '';

	// 'count' isn't itself a tracked metric - every tracked metric's cached sample_count is
	// identical for a given scope_key (they're all counted over the same matched detail
	// rows), so read it off any one tracked metric's rows instead of total_value.
	if ($measure == 'count') {
		$stats_metric = SLOWLOG_STATS_METRICS[0];
		$value_column = 'sample_count';
	} else {
		$stats_metric = $measure;
		$value_column = 'total_value';
	}

	$stats = db_fetch_assoc_prepared("SELECT scope_key, $value_column AS value
		FROM plugin_slowlog_stats
		WHERE logid = ?
		AND scope = ?
		AND metric = ?
		ORDER BY $value_column DESC" . $limit,
		array($id, $scope, $stats_metric));

	$measures = slowlog_chart_measures();

	if (cacti_sizeof($stats)) {
		$categories = array();
		$values     = array();

		foreach($stats as $entry) {
			$categories[] = $entry['scope_key'];
			$values[]     = $entry['value'];
		}

		$title = $description . ' [ ' . $measures[$measure]['suffix'] . ' ]';

		return array(
			'title'      => $title,
			'categories' => $categories,
			'values'     => $values,
			'yaxislabel' => $measures[$measure]['unit']
		);
	} else {
		return array();
	}
}

