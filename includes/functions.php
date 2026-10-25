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
 * Removes every per-logid row this plugin ever writes, across every table introduced since
 * v2.1 - including the v2.4 stats cache, which is easy to forget since it's the newest and
 * lives outside the original details/tables/methods set this function started with.
 *
 * Called from slowlog.php's form_actions() when a user deletes an
 * imported log from the Slowlog list.
 *
 * @param int $logid The plugin_slowlog.logid to delete along with all
 *                   of its related rows.
 *
 * @return void
 */
function api_slowlog_remove(int $logid): void {
	db_execute_prepared('DELETE FROM plugin_slowlog WHERE logid = ?', [$logid]);
	db_execute_prepared('DELETE FROM plugin_slowlog_details WHERE logid = ?', [$logid]);
	db_execute_prepared('DELETE FROM plugin_slowlog_details_tables WHERE logid = ?', [$logid]);
	db_execute_prepared('DELETE FROM plugin_slowlog_details_methods WHERE logid = ?', [$logid]);
	db_execute_prepared('DELETE FROM plugin_slowlog_stats WHERE logid = ?', [$logid]);
}

/**
 * Wraps a view-rendering callback with the shared Cacti page header and
 * footer. Called from slowlog.php's dispatcher for each view action, to
 * avoid repeating the header/footer boilerplate in every view function.
 *
 * @param callable $render_callback The view-rendering function to call
 *                                  between the header and footer.
 *
 * @return void
 */
function slowlog_render_with_layout(callable $render_callback): void {
	general_header();
	$render_callback();
	bottom_footer();
}

/**
 * Builds a space-separated list of every table name across every
 * database on the connected MySQL/MariaDB server (excluding
 * information_schema and mysql), used to distinguish Cacti's own tables
 * from others in a slow log. Called from import_logfile() (when
 * '--usecacti' is passed) and import_post_process() (in 'cacti' table
 * mode).
 *
 * @return string A space-separated list of all table names found.
 */
function get_cacti_tables(): string {
	$databases = db_fetch_assoc('SHOW DATABASES');
	$tables    = '';
	if (!is_array($databases)) {
		throw new RuntimeException('Slowlog database list read failed');
	}

	if (cacti_sizeof($databases)) {
		foreach ($databases as $db) {
			if ($db['Database'] == 'information_schema' || $db['Database'] == 'mysql') {
				// Skip
			} else {
				$rows = db_fetch_assoc('SHOW TABLES FROM `' . str_replace('`', '``', $db['Database']) . '`');
				if (!is_array($rows)) {
					throw new RuntimeException('Slowlog table list read failed');
				}
				$tables .= (strlen($tables) ? ' ' : '') . implode(' ', array_rekey($rows, 'Tables_in_' . $db['Database'], 'Tables_in_' . $db['Database']));
			}
		}
	}

	return $tables;
}

/**
 * Execute an import write, stopping the current stage on database failure.
 *
 * @param string $sql SQL with placeholders.
 * @param array $params Bound values.
 *
 * @return void
 */
function slowlog_import_write(string $sql, array $params = []): void {
	if (db_execute_prepared($sql, $params) === false) {
		throw new RuntimeException('Slowlog import database write failed');
	}
}

/**
 * Read an import batch without treating a failed query as an empty batch.
 *
 * @param string $sql SQL with placeholders.
 * @param array $params Bound values.
 *
 * @return array The fetched rows.
 */
function slowlog_import_rows(string $sql, array $params = []): array {
	$rows = db_fetch_assoc_prepared($sql, $params);
	if (!is_array($rows)) {
		throw new RuntimeException('Slowlog import database read failed');
	}

	return $rows;
}

/**
 * Record a failed stage and return its unsuccessful outcome.
 *
 * @param int|null $logid Parent record, if one has been created.
 * @param string $message Failure description.
 *
 * @return bool Always false.
 */
function slowlog_fail_import(?int $logid, string $message): bool {
	if ($logid !== null && $logid > 0) {
		try {
			db_execute_prepared('UPDATE plugin_slowlog SET import_status = 3, import_text_status = ? WHERE logid = ?', [$message, $logid]);
		} catch (Throwable $error) {
			// A database outage must not turn an unsuccessful import into success.
		}
	}

	print $message . PHP_EOL;

	return false;
}

/**
 * Finish a CLI file import, retaining failed input for an operator to retry.
 *
 * @param bool $success Whether ingestion and post-processing both succeeded.
 * @param string $logfile Input file path.
 * @param bool $delete_after Remove the input after successful processing.
 *
 * @return int Zero on success, one on import or cleanup failure.
 */
function slowlog_finish_file_import(bool $success, string $logfile, bool $delete_after = false): int {
	if (!$success) {
		return 1;
	}

	if ($delete_after && file_exists($logfile) && !@unlink($logfile)) {
		print __('ERROR: Unable to remove the imported Slowlog file.', 'slowlog') . PHP_EOL;

		return 1;
	}

	return 0;
}

/**
 * Bulk-inserts (logid, logentry, methodid) rows into plugin_slowlog_details_methods in
 * chunks. Fully parameterized - logid/logentry/methodid are always bound placeholders, never
 * concatenated into the SQL text - so a value that reaches here without prior numeric
 * validation (e.g. --logid from the CLI) can't alter the VALUES clause.
 * $rows is an array of array($logid, $logentry, $methodid) tuples.
 *
 * Called from import_post_process() while classifying each imported
 * query's detected method(s).
 *
 * @param array $rows An array of [$logid, $logentry, $methodid] tuples
 *                    to insert (or update on duplicate key).
 *
 * @return void
 */
function slowlog_bulk_insert_method_rows(array $rows): void {
	if (!cacti_sizeof($rows)) {
		return;
	}

	$sql_prefix = 'INSERT INTO plugin_slowlog_details_methods (logid, logentry, method) VALUES ';
	$sql_suffix = ' ON DUPLICATE KEY UPDATE method=VALUES(method)';

	foreach (array_chunk($rows, 500) as $chunk) {
		$placeholders = [];
		$params       = [];

		foreach ($chunk as $row) {
			$placeholders[] = '(?, ?, ?)';
			$params[]       = (int) $row[0];
			$params[]       = (int) $row[1];
			$params[]       = (string) $row[2];
		}

		slowlog_import_write($sql_prefix . implode(', ', $placeholders) . $sql_suffix, $params);
	}
}

/**
 * Bulk-inserts (logid, logentry, table_name) rows into plugin_slowlog_details_tables in
 * chunks. Fully parameterized for the same reason as slowlog_bulk_insert_method_rows() -
 * logid/logentry are always bound placeholders, never concatenated into the SQL text.
 * $rows is an array of array($logid, $logentry, $table_name) tuples.
 *
 * Called from import_post_process() while classifying each imported
 * query's detected table references.
 *
 * @param array $rows An array of [$logid, $logentry, $table_name]
 *                    tuples to insert (or update on duplicate key).
 *
 * @return void
 */
function slowlog_bulk_insert_table_rows(array $rows): void {
	if (!cacti_sizeof($rows)) {
		return;
	}

	$sql_prefix = 'INSERT INTO plugin_slowlog_details_tables (logid, logentry, table_name) VALUES ';
	$sql_suffix = ' ON DUPLICATE KEY UPDATE table_name=VALUES(table_name)';

	foreach (array_chunk($rows, 500) as $chunk) {
		$placeholders = [];
		$params       = [];

		foreach ($chunk as $row) {
			$placeholders[] = '(?, ?, ?)';
			$params[]       = (int) $row[0];
			$params[]       = (int) $row[1];
			$params[]       = (string) $row[2];
		}

		slowlog_import_write($sql_prefix . implode(', ', $placeholders) . $sql_suffix, $params);
	}
}

/**
 * Truncates a query to at most $length bytes without cutting a multibyte character in
 * half, which would make the INSERT fail on a UTF-8 column. A length of -1, or any value
 * below 1, leaves the query as is.
 *
 * Called from import_logfile() for each entry when a maximum query length is set.
 *
 * @param string $query  The normalized query text.
 * @param int    $length The maximum length in bytes, or -1 for no limit.
 *
 * @return string The query, truncated when $length is a positive number.
 */
function slowlog_truncate_query(string $query, int $length): string {
	if ($length < 1) {
		return $query;
	}

	return function_exists('mb_strcut') ? mb_strcut($query, 0, $length, 'UTF-8') : substr($query, 0, $length);
}

/**
 * Marks an import as failed after a batch of detail rows could not be inserted, so the log
 * is not finalized as a complete import with rows silently missing.
 *
 * Called from import_logfile() when a bulk INSERT returns false.
 *
 * @param int $logid The plugin_slowlog.logid being imported.
 *
 * @return void
 */
function slowlog_import_failed(int $logid): void {
	db_execute_prepared('UPDATE plugin_slowlog
		SET import_status = 3,
		import_text_status = ?
		WHERE logid = ?',
		[__('Import Failed - Row Insert Error', 'slowlog'), $logid]);
}


/**
 * Returns the SQL for a timestamp column value taken from an epoch. The epoch is an absolute instant,
 * so it goes through FROM_UNIXTIME() and the database's own session time zone, not through PHP's
 * date(), whose zone can differ from the database's.
 *
 * Called from slowlog_time_expression() and import_logfile() for "SET timestamp=" lines.
 *
 * @param int $epoch Seconds since 1970-01-01 UTC; clamped to the range of a TIMESTAMP column.
 *
 * @return string The SQL expression.
 */
function slowlog_epoch_expression(int $epoch): string {
	return 'FROM_UNIXTIME(' . min(max($epoch, 0), 2147483647) . ')';
}

/**
 * Returns the SQL for the time in a "# Time:" header line. MySQL 8 writes an ISO 8601 time in UTC
 * or with an offset ("2024-01-15T01:02:03.123456Z"), which names an absolute instant and is read
 * with that zone. Older servers write a wall-clock "YYMMDD H:MM:SS" in the server's own zone, which
 * is stored as written.
 *
 * Called from import_logfile() for each "# Time:" header.
 *
 * @param string $line The "# Time:" header line.
 *
 * @return string The SQL expression for the timestamp column.
 */
function slowlog_time_expression(string $line): string {
	// the server pads a one digit hour with a second space, so split on any run of whitespace
	$parts = preg_split('/\s+/', trim($line)) ?: [];
	$time  = $parts[2] ?? '';

	if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $time)) {
		$epoch = strtotime($time);

		if ($epoch !== false) {
			return slowlog_epoch_expression($epoch);
		}
	}

	return db_qstr('20' . substr($time, 0, 2) . '-' . substr($time, 2, 2) . '-' . substr($time, 4, 2) . (isset($parts[3]) ? ' ' . $parts[3] : ''));
}

// the pseudo file name of the management realm; no page has this name, the realm only exists so an
// administrator can grant it separately from the viewing realm
const SLOWLOG_MANAGE_FILE = 'slowlog_manage.php';

/**
 * Returns the id of the realm that allows importing and deleting logs, as Cacti numbers it for
 * is_realm_allowed() (the plugin realm number plus 100).
 *
 * Called from slowlog_can_manage().
 *
 * @return int The realm id, or 0 when the realm is not registered.
 */
function slowlog_manage_realm(): int {
	$id = db_fetch_cell_prepared('SELECT id
		FROM plugin_realms
		WHERE plugin = ?
		AND file = ?',
		['slowlog', SLOWLOG_MANAGE_FILE]);

	return empty($id) ? 0 : (int) $id + 100;
}

/**
 * Whether the current user may import and delete logs. Viewing needs the slowlog.php realm; this
 * needs the management realm as well. It fails closed when the realm is not registered.
 *
 * Called from slowlog.php before an import, a delete or the import form, and to decide which
 * controls the list shows.
 *
 * @return bool True when the user holds the management realm.
 */
function slowlog_can_manage(): bool {
	$realm = slowlog_manage_realm();

	return $realm > 0 && is_realm_allowed($realm);
}

/**
 * Raises PHP's execution time and memory limits for the requests that need them, and only those:
 * the import form and upload (a large file is staged and handed to the background worker) and the
 * delete of a large log. Every other request keeps the limits the web server was configured with.
 *
 * Called from slowlog.php once the request's action is known.
 *
 * @param string $action The request's action.
 *
 * @return bool True when a limit was raised.
 */
function slowlog_apply_request_limits(string $action): bool {
	$raised = false;

	if (in_array($action, ['edit', 'save', 'import', 'actions'], true)) {
		ini_set('max_execution_time', '0');

		$raised = true;
	}

	if (in_array($action, ['edit', 'save', 'import'], true)) {
		ini_set('memory_limit', '-1');
	}

	return $raised;
}

// the largest page the Details view will build, matching the biggest rows-per-page choice
const SLOWLOG_MAX_PAGE_ROWS = 1000;

/**
 * Bounds the rows-per-page and page number taken from the request before they are used in
 * a LIMIT clause. The request filter only checks that they are integers, so zero, negative
 * and very large values would otherwise reach the query; a huge page size loads every
 * matching row, including both query columns, into memory.
 *
 * Called from slowlog_view_details().
 *
 * @param mixed $rows The requested (or configured default) rows per page.
 * @param mixed $page The requested page number.
 *
 * @return array{0: int, 1: int} The rows per page (1 to SLOWLOG_MAX_PAGE_ROWS, 30 when the
 *                               value is not positive) and the page number (at least 1).
 */
function slowlog_page_bounds($rows, $page): array {
	$rows = (int) $rows;

	if ($rows < 1) {
		$rows = 30;
	}

	return [min($rows, SLOWLOG_MAX_PAGE_ROWS), max(1, (int) $page)];
}

// the 5 plugin_slowlog_details columns plugin_slowlog_stats tracks a distribution for
const SLOWLOG_STATS_METRICS = ['query_time', 'rows_sent', 'rows_examined', 'rows_affected', 'bytes_sent'];

// number of plugin_slowlog_details rows accumulated per bulk INSERT during import
const SLOWLOG_IMPORT_BATCH_SIZE = 1000;

// The SQL-method classifier dictionary: method name => query-text fragments matched
// case-insensitively (stripos) when classifying each imported query. Replaces the former
// plugin_slowlog_methods table lookup now that the method name is stored directly in
// plugin_slowlog_details_methods.method. OTHERS (matched-nothing fallback) and OTHER TABLES
// (classified by table recognition, not a text fragment) are handled separately.
const SLOWLOG_METHOD_FRAGMENTS = [
	'INSERTS'            => ['INSERT INTO', 'INSERT IGNORE INTO'],
	'REPLACES'           => ['REPLACE INTO', 'REPLACE IGNORE INTO'],
	'DELETES'            => ['DELETE '],
	'SELECTS'            => ['SELECT '],
	'DISTINCTS'          => ['SELECT DISTINCT'],
	'UNIONS'             => ['UNION'],
	'JOINS'              => ['JOIN '],
	'UPDATES'            => ['UPDATE '],
	'RENAMES'            => ['RENAME TABLE'],
	'FLUSHES'            => ['FLUSH TABLE'],
	'TRUNCATES'          => ['TRUNCATE '],
	'LOAD DATA'          => ['LOAD DATA INFILE '],
	'OUTFILES'           => ['INTO OUTFILE '],
	'INFILES'            => ['INFILE '],
	'GROUP BY'           => ['GROUP BY '],
	'COUNTS'             => ['COUNT('],
	'SHOWS'              => ['SHOW '],
	'UNION ALLS'         => ['UNION ALL'],
	'MAX_EXECUTION_TIME' => ['MAX_EXECUTION_TIME('],
	'MAX_STATEMENT_TIME' => ['MAX_STATEMENT_TIME'],
	'FORCE INDEX'        => ['FORCE INDEX'],
	'ALTERS'             => ['ALTER TABLE'],
	'DROPS'              => ['DROP TABLE', 'DROP TEMPORARY TABLE'],
	'ANALYZES'           => ['ANALYZE TABLE', 'ANALYZE NO_WRITE_TO_BINLOG TABLE', 'ANALYZE LOCAL TABLE'],
	'OPTIMIZES'          => ['OPTIMIZE TABLE', 'OPTIMIZE NO_WRITE_TO_BINLOG TABLE', 'OPTIMIZE LOCAL TABLE'],
	'CREATES'            => ['create table'],
	'CREATE TEMPS'       => ['create temporary table'],
];

// Bucket method names stored directly in plugin_slowlog_details_methods.method.
const SLOWLOG_METHOD_OTHERS = 'OTHERS';
const SLOWLOG_METHOD_OTHER_TABLES = 'OTHER TABLES';

// the By Method/By Table charts' 'Top N' selectmenu options
const SLOWLOG_CHART_TOP_OPTIONS = ['2', '10', '15', '20', '25', '30'];

/**
 * Restricts the chart 'Top N' selectmenu to its fixed option list, falling back to the
 * default of 10 for anything else (a tampered value, or a stale cached selection from
 * before the option list existed/changed).
 *
 * Called as the FILTER_CALLBACK validator for the 'chart_top' request
 * variable in slowlog_request_charts_validation().
 *
 * @param mixed $value The submitted 'chart_top' value to validate.
 *
 * @return string The validated value if it matches
 *                SLOWLOG_CHART_TOP_OPTIONS, otherwise '10'.
 */
function slowlog_sanitize_chart_top($value): string {
	return in_array((string) $value, SLOWLOG_CHART_TOP_OPTIONS, true) ? (string) $value : '10';
}

/**
 * The 'LIMIT N' clause fragment for the By Method/By Table charts, honoring the Top N
 * selectmenu - but only when the user hasn't picked explicit scopes to chart via the
 * multiselect, since a selected category beyond the top N by value would otherwise be
 * silently dropped from its own chart.
 *
 * Called from slowlog_get_chart_object()/slowlog_get_stats_chart_object()
 * when building the chart query.
 *
 * @param array $scope_filter The explicitly selected method/table scope
 *                            values (from
 *                            slowlog_get_chart_scope_filter()); when
 *                            non-empty, no LIMIT is applied.
 *
 * @return string The ' LIMIT N' SQL fragment, or '' when an explicit
 *                scope filter is active.
 */
function slowlog_chart_top_limit(array $scope_filter): string {
	return cacti_sizeof($scope_filter) ? '' : ' LIMIT ' . (int) get_request_var('chart_top');
}


/**
 * The scope_key used for the synthetic "no recognized table" bucket. Ordinarily just
 * 'others', but a real table can legitimately be named that too - since both would
 * otherwise merge into the same plugin_slowlog_stats/chart-category bucket (and, from
 * the By Table chart, become indistinguishable when drilling down to slowlog.php?table=...),
 * fall back to the first candidate below no real table_name for this log collides with.
 *
 * Called from slowlog_collect_stats_by_unmatched_table() and
 * slowlog_charts_filter() to consistently label/identify the 'others'
 * bucket.
 *
 * @param int $logid The plugin_slowlog.logid to check for table-name
 *                   collisions against.
 *
 * @return string The bucket key to use: 'others', or one of its
 *                collision-avoidance fallbacks.
 */
function slowlog_others_bucket_key(int $logid): string {
	$candidates = ['others', 'others (unmatched)', 'others (unmatched queries)'];

	foreach ($candidates as $candidate) {
		$collision = db_fetch_cell_prepared('SELECT 1
			FROM plugin_slowlog_details_tables
			WHERE logid = ?
			AND table_name = ?
			LIMIT 1',
			[$logid, $candidate]);

		if (!$collision) {
			return $candidate;
		}
	}

	// All candidates collided (astronomically unlikely) - fall back to something
	// no real, human-typed table name would ever contain.
	return "\x00others";
}


/**
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
 *
 * Called from import_post_process() after a log's table associations
 * have been recorded, for the 'cacti'/'all' table-detection modes.
 *
 * @param int        $logid        The plugin_slowlog.logid whose
 *                                 associated tables should be synced
 *                                 into the dictionary.
 * @param array|null $known_tables The list of known/recognized table
 *                                 names (e.g. from get_cacti_tables())
 *                                 to mark as Cacti tables, or null to
 *                                 only add missing entries without
 *                                 changing their is_cacti_table flag;
 *                                 defaults to null.
 *
 * @return void
 */
function slowlog_sync_table_dictionary(int $logid, ?array $known_tables = null): void {
	$tables = slowlog_import_rows('SELECT DISTINCT table_name
		FROM plugin_slowlog_details_tables
		WHERE logid = ?',
		[$logid]);

	if (!cacti_sizeof($tables)) {
		return;
	}

	if ($known_tables !== null) {
		$known_lookup = array_flip($known_tables);
	}

	foreach ($tables as $row) {
		$t = $row['table_name'];

		if ($known_tables !== null) {
			slowlog_import_write('INSERT INTO plugin_slowlog_table_names
				(table_name, is_cacti_table)
				VALUES (?, ?)
				ON DUPLICATE KEY UPDATE is_cacti_table = VALUES(is_cacti_table)',
				[$t, isset($known_lookup[$t]) ? 1 : 0]);
		} else {
			slowlog_import_write('INSERT IGNORE INTO plugin_slowlog_table_names
				(table_name)
				VALUES (?)',
				[$t]);
		}
	}
}

/**
 * Tags any logentry that references at least one table not recognized as a Cacti table
 * (per plugin_slowlog_table_names.is_cacti_table, just refreshed by
 * slowlog_sync_table_dictionary()) with the 'OTHER TABLES' method - a separate concept from
 * the 'OTHERS' method, which flags a query that didn't match any known SQL construct at all.
 * Only meaningful once is_cacti_table has actually been determined, so import_post_process()
 * only calls this for the 'cacti'/'reference' table modes (i.e. whenever a reference list of
 * known tables was actually available), never for 'list'/'all'.
 *
 * @param int $logid The plugin_slowlog.logid to classify.
 *
 * @return void
 */
function slowlog_classify_other_tables(int $logid): void {
	$rows = slowlog_import_rows('SELECT DISTINCT dt.logentry
		FROM plugin_slowlog_details_tables AS dt
		INNER JOIN plugin_slowlog_table_names AS tn
		ON tn.table_name = dt.table_name
		WHERE dt.logid = ?
		AND tn.is_cacti_table = 0',
		[$logid]);

	if (!cacti_sizeof($rows)) {
		return;
	}

	$method_rows = [];

	foreach ($rows as $row) {
		$method_rows[] = [$logid, $row['logentry'], SLOWLOG_METHOD_OTHER_TABLES];
	}

	slowlog_bulk_insert_method_rows($method_rows);
}

/**
 * Same 'OTHER TABLES' tagging as slowlog_classify_other_tables(), but compares this log's
 * table associations directly against a caller-supplied reference list instead of the shared
 * plugin_slowlog_table_names.is_cacti_table flag. Used for 'reference' table-detection mode,
 * where the list is specific to one import and must never be written into that shared,
 * global dictionary (see slowlog_sync_table_dictionary()).
 *
 * Called from import_post_process() after a log's table associations
 * have been recorded, for the 'reference' table-detection mode.
 *
 * @param int   $logid            The plugin_slowlog.logid to classify.
 * @param array $reference_tables The list of table names to treat as
 *                                'known' for this specific import.
 *
 * @return void
 */
function slowlog_classify_other_tables_against_list(int $logid, array $reference_tables): void {
	$tables = slowlog_import_rows('SELECT DISTINCT table_name
		FROM plugin_slowlog_details_tables
		WHERE logid = ?',
		[$logid]);

	if (!cacti_sizeof($tables)) {
		return;
	}

	$known_lookup = array_flip($reference_tables);
	$other_tables = [];

	foreach ($tables as $row) {
		if (!isset($known_lookup[$row['table_name']])) {
			$other_tables[] = $row['table_name'];
		}
	}

	if (!cacti_sizeof($other_tables)) {
		return;
	}

	$placeholders = implode(', ', array_fill(0, cacti_sizeof($other_tables), '?'));

	$rows = slowlog_import_rows('SELECT DISTINCT logentry
		FROM plugin_slowlog_details_tables
		WHERE logid = ?
		AND table_name IN (' . $placeholders . ')',
		array_merge([$logid], $other_tables));

	if (!cacti_sizeof($rows)) {
		return;
	}

	$method_rows = [];

	foreach ($rows as $row) {
		$method_rows[] = [$logid, $row['logentry'], SLOWLOG_METHOD_OTHER_TABLES];
	}

	slowlog_bulk_insert_method_rows($method_rows);
}

/**
 * Parses a MySQL/MariaDB slow query log file line by line, extracting
 * each entry's metadata (timestamp, user/host/IP, timing, row/byte
 * counts) and original/normalized query text, batching them into
 * plugin_slowlog_details INSERTs. Creates the plugin_slowlog parent
 * record if one wasn't already provided, then either queues a background
 * import_log.php worker to run post-processing (method/table
 * classification and stats caching) or runs it inline. Called from
 * import_log.php's main flow (via the '--logfile' CLI option) to import
 * a slow query log.
 *
 * @param string      $logfile     The slow query log file path to
 *                                 parse.
 * @param string      $description The description to save with a
 *                                 newly created plugin_slowlog parent
 *                                 record; defaults to 'Imported using
 *                                 import_log utility'.
 * @param int         $length      Truncate each imported query to this
 *                                 many characters, -1 for no limit;
 *                                 defaults to 8192.
 * @param string      $table_names Space-separated reference table list
 *                                 (for 'reference' table-mode), or the
 *                                 discovered Cacti table list (for
 *                                 'cacti'/'--usecacti' mode); defaults
 *                                 to ''.
 * @param bool        $usecacti    Legacy flag equivalent to
 *                                 $table_mode = 'cacti'; also
 *                                 auto-populates $table_names via
 *                                 get_cacti_tables() when empty;
 *                                 defaults to false.
 * @param bool        $batch       Whether to run post-processing in a
 *                                 background worker (true) or inline in
 *                                 this call (false); defaults to true.
 * @param string|null $table_mode  How to distinguish Cacti tables from
 *                                 others: 'cacti', 'reference', 'all',
 *                                 or null to infer from $usecacti;
 *                                 defaults to null.
 * @param int|null    $logid       Reuse an already-created
 *                                 plugin_slowlog parent record instead
 *                                 of creating a new one; defaults to
 *                                 null.
 *
 * @return bool True on success, false when a stage failed.
 *
 * @global array $config Cacti global configuration array; used to
 *                       resolve the PHP binary and import_log.php path
 *                       for the background worker.
 */
function import_logfile(string $logfile, string $description = 'Imported using import_log utility', int $length = 8192, string $table_names = '', bool $usecacti = false, bool $batch = true, ?string $table_mode = null, ?int $logid = null): bool {
	try {
		return slowlog_ingest_logfile($logfile, $description, $length, $table_names, $usecacti, $batch, $table_mode, $logid);
	} catch (SlowlogImportReadException $error) {
		if ($logid === null) {
			print "FATAL: Read error while importing '$logfile'\n";
			return false;
		}
		return slowlog_fail_import($logid, __('Read Error - Import Aborted, Log May Be Truncated', 'slowlog'));
	} catch (SlowlogImportWriteException $error) {
		return slowlog_fail_import($logid, __('Import Failed - Row Insert Error', 'slowlog'));
	} catch (Throwable $error) {
		return slowlog_fail_import($logid, __('Import Failed - Database Error', 'slowlog'));
	}
}

/**
 * Serialize one validated parser entry for the existing multi-row detail insert.
 *
 * @param int $logid Parent import ID.
 * @param array $entry Parsed fields.
 * @param int $length Maximum normalized query length.
 *
 * @return string One escaped SQL tuple.
 */
function slowlog_detail_tuple(int $logid, array $entry, int $length): string {
	$date = $entry['epoch'] !== null
		? slowlog_epoch_expression($entry['epoch'])
		: ($entry['date'] === 0 ? db_qstr('0') : slowlog_time_expression($entry['date']));
	return '(' . $logid . ', ' . $date . ', ' . db_qstr($entry['user']) . ', ' .
		db_qstr($entry['host']) . ', ' . db_qstr($entry['ip']) . ', ' . $entry['query_time'] . ', ' .
		$entry['lock_time'] . ', ' . $entry['thread_id'] . ', ' . db_qstr($entry['schema']) . ', ' .
		$entry['qc_hit'] . ', ' . $entry['rows_sent'] . ', ' . $entry['rows_examined'] . ', ' .
		$entry['rows_affected'] . ', ' . $entry['bytes_sent'] . ', ' . db_qstr($entry['oquery']) . ', ' .
		db_qstr(slowlog_truncate_query($entry['query'], $length)) . ')';
}

/**
 * Persist one detail batch and reject a failed write.
 *
 * @param array $tuples Escaped tuples produced by slowlog_detail_tuple().
 *
 * @return void
 */
function slowlog_write_detail_batch(array $tuples): void {
	if (!$tuples) {
		return;
	}
	$sql_prefix = 'INSERT INTO plugin_slowlog_details (logid, date, user, host, ip_address, query_time, lock_time, thread_id, `schema`, qc_hit, rows_sent, rows_examined, rows_affected, bytes_sent, oquery, query) VALUES ';
	if (db_execute($sql_prefix . implode(',', $tuples)) === false) {
		throw new SlowlogImportWriteException();
	}
}

/**
 * Ingest parser entries in bounded batches, sharing the parent ID with the caller.
 *
 * @param string $logfile Input file.
 * @param string $description Import description.
 * @param int $length Maximum normalized query length.
 * @param string $table_names Reference table names.
 * @param bool $usecacti Legacy Cacti table mode.
 * @param bool $batch Queue post-processing instead of running it inline.
 * @param string|null $table_mode Table classification mode.
 * @param int|null $logid Parent ID, updated when one is created.
 *
 * @return bool Whether processing succeeded, or post-processing was queued.
 */
function slowlog_ingest_logfile(string $logfile, string $description, int $length, string $table_names, bool $usecacti, bool $batch, ?string $table_mode, ?int &$logid): bool {
	global $config;
	ini_set('max_execution_time', 0);
	if ($table_mode === null && $usecacti) {
		$table_mode = 'cacti';
	}
	if ($table_names === '' && $usecacti) {
		$table_names = get_cacti_tables();
	}
	$logfile = trim($logfile);
	$stream = @fopen($logfile, 'r');
	if ($stream === false) {
		return slowlog_fail_import($logid, __('Import Failed - Unable to Open File', 'slowlog'));
	}
	$tuples = [];
	$bytes = 0;
	$seen = false;
	try {
		foreach (slowlog_parse_log_stream($stream) as $entry) {
			if ($logid === null) {
				$now = date('Y-m-d H:i:s');
				$logid = (int) sql_save(['logid' => 0, 'description' => $description, 'import_date' => $now,
					'import_lines' => 0, 'import_tables' => $table_names, 'start_time' => $now, 'end_time' => $now], 'plugin_slowlog', 'logid');
				if ($logid === 0) {
					return slowlog_fail_import(null, __('Import Failed - Unable to Create Parent Record', 'slowlog'));
				}
			}
			$seen = true;
			$tuple = slowlog_detail_tuple($logid, $entry, $length);
			// Flush before adding another row that would exceed the byte budget.
			// A single large query keeps its own batch; the database decides whether it fits.
			if ($tuples && $bytes + strlen($tuple) > 1048576) {
				slowlog_write_detail_batch($tuples);
				$tuples = [];
				$bytes = 0;
			}
			$tuples[] = $tuple;
			$bytes += strlen($tuple) + 1;
			if (cacti_sizeof($tuples) >= SLOWLOG_IMPORT_BATCH_SIZE) {
				slowlog_write_detail_batch($tuples);
				$tuples = [];
				$bytes = 0;
			}
		}
		slowlog_write_detail_batch($tuples);
	} finally {
		fclose($stream);
	}
	if (!$seen) {
		return slowlog_fail_import($logid, __('Bad File Format - No Slow Query Log Entries Found', 'slowlog'));
	}
	$values = db_fetch_row_prepared('SELECT COUNT(*) AS import_lines, MIN(date) AS start_time, MAX(date) AS end_time FROM plugin_slowlog_details WHERE logid = ?', [$logid]);
	if ($values === false) {
		throw new RuntimeException('Slowlog import metadata read failed');
	}
	if ($values) {
		slowlog_import_write('UPDATE plugin_slowlog SET import_lines = ?, import_status = 1, start_time = ?, end_time = ? WHERE logid = ?', [$values['import_lines'], $values['start_time'], $values['end_time'], $logid]);
	}
	if (!$batch) {
		return import_post_process($logid, $table_names, $usecacti, $table_mode);
	}
	raise_message('import_pre', __('The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete', 'slowlog'), MESSAGE_LEVEL_INFO);
	$php = cacti_escapeshellcmd(read_config_option('path_php_binary'));
	$cmd = $config['base_path'] . "/plugins/slowlog/import_log.php --logid=$logid";
	$cmd .= $usecacti ? ' --usecacti' : '';
	$cmd .= $table_mode !== null ? ' --table-mode=' . cacti_escapeshellarg($table_mode) : '';
	$cmd .= trim($table_names) !== '' ? ' --table-names=' . cacti_escapeshellarg(trim($table_names)) : '';
	slowlog_import_write('UPDATE plugin_slowlog SET import_text_status = ? WHERE logid = ?', ['Post Processing with Table Detection', $logid]);
	exec_background($php, $cmd);
	return true;
}

/**
 * Loads the full set of SQL reserved words from plugin_slowlog_reserved_words
 * into the module-level $reserved_words cache. Called once from
 * import_post_process() before classifying queries, so is_reserved_word()
 * can perform in-memory lookups instead of a query per token.
 *
 * @return void
 *
 * @global array $reserved_words Set to a lookup map of every reserved
 *                               word (uppercase word => word).
 */
function load_reserved_words(): void {
	global $reserved_words;

	$reserved_words = array_rekey(slowlog_import_rows('SELECT word
		FROM plugin_slowlog_reserved_words',
		[]), 'word', 'word');
}

/**
 * Checks whether a token (typically the first word/identifier of a
 * query fragment) is a known SQL reserved word, after stripping
 * trailing parens/semicolons and any '='/'(' suffix. Called during
 * query classification in import_post_process() to help distinguish
 * table references from SQL keywords.
 *
 * @param string $token The token to check.
 *
 * @return bool True if the cleaned-up token is a known reserved word,
 *              false otherwise.
 *
 * @global array $reserved_words The reserved-word lookup map populated
 *                               by load_reserved_words().
 */
function is_reserved_word(string $token): bool {
	global $reserved_words;

	// Cleanup the token
	$token = trim(strtoupper($token), '();');

	// slowlog_debug("Pre Token: " . $token);

	if (strpos($token, '=') !== false) {
		$parts = explode('=', $token);
		$token = trim($parts[0]);
	} elseif (strpos($token, '(') !== false) {
		$parts = explode('(', $token);
		$token = trim($parts[0]);
	}

	// slowlog_debug("Post Token: " . $token);

	if (isset($reserved_words[$token])) {
		slowlog_debug('Reserved Word: ' . $token);

		return true;
	} else {
		return false;
	}
}

/**
 * Classifies every detail row of an imported log by SQL method (via
 * in-memory fragment matching against plugin_slowlog_methods, chunked to
 * bound memory) and by referenced table (via LIKE scans against an
 * explicit list, or via the full tokenizer in
 * get_table_associations() for 'cacti'/'reference'/'all' modes),
 * classifies 'OTHER TABLES' queries where applicable, detects per-query
 * timeouts, and computes/caches summary statistics. Called from
 * import_logfile() (inline or via a background import_log.php worker)
 * and slowlog_reprocess() to finish processing a newly imported or
 * re-processed log.
 *
 * @param int         $logid       The plugin_slowlog.logid to
 *                                 post-process.
 * @param string      $table_names Space-separated reference table list
 *                                 (used when $table_mode is 'list', or
 *                                 inferred as such when $table_mode is
 *                                 null and this is non-empty); defaults
 *                                 to ''.
 * @param bool        $usecacti    Legacy flag equivalent to
 *                                 $table_mode = 'cacti' when $table_mode
 *                                 is null; defaults to false.
 * @param string|null $table_mode  How to distinguish Cacti tables from
 *                                 others: 'list', 'cacti', 'reference',
 *                                 'all', or null to infer from
 *                                 $table_names/$usecacti; defaults to
 *                                 null.
 *
 * @return bool True on success, false when a stage failed.
 */
function import_post_process(int $logid, string $table_names = '', bool $usecacti = false, ?string $table_mode = null): bool {
	try {
		slowlog_process_import($logid, $table_names, $usecacti, $table_mode);

		return true;
	} catch (Throwable $error) {
		return slowlog_fail_import($logid, __('Post Processing Failed - Database Error', 'slowlog'));
	}
}

/**
 * Build associations and statistics, throwing when a database stage fails.
 *
 * @param int $logid Parent import ID.
 * @param string $table_names Reference table names.
 * @param bool $usecacti Legacy Cacti table mode.
 * @param string|null $table_mode Explicit classification mode.
 *
 * @return void
 */
function slowlog_process_import(int $logid, string $table_names = '', bool $usecacti = false, ?string $table_mode = null): void {
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
		[$logid]);

	if ($records === false) {
		throw new RuntimeException('Slowlog import count failed');
	}

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
		$method_fragments = SLOWLOG_METHOD_FRAGMENTS;

		$method_chunk_size = 2000;
		$last_logentry     = 0;

		do {
			$detail_rows = slowlog_import_rows('SELECT logentry, query
				FROM plugin_slowlog_details
				WHERE logid = ?
				AND logentry > ?
				ORDER BY logentry
				LIMIT ' . (int) $method_chunk_size,
				[$logid, $last_logentry]);

			$batch_count = cacti_sizeof($detail_rows);
			$method_rows = [];

			foreach ($detail_rows as $row) {
				$last_logentry = $row['logentry'];
				$matched       = false;

				foreach ($method_fragments as $method => $fragments) {
					foreach ($fragments as $fragment) {
						if (stripos($row['query'], $fragment) !== false) {
							$method_rows[] = [$logid, $row['logentry'], $method];
							$matched       = true;

							break;
						}
					}
				}

				if (!$matched) {
					$method_rows[] = [$logid, $row['logentry'], SLOWLOG_METHOD_OTHERS];
				}
			}

			slowlog_bulk_insert_method_rows($method_rows);
		} while ($batch_count === $method_chunk_size);

		$end = microtime(true);

		cacti_log(sprintf('STATS: Time:%0.2f, Pre-Processing for Methods Complete for %s', $end - $start, $logid), false, 'SLOWLOG');

		$start = microtime(true);

		// perform table name analysis
		$tables       = [];
		$known_tables = null;

		if ($table_mode == 'list') {
			$tables = preg_split('/\s+/', trim($table_names), -1, PREG_SPLIT_NO_EMPTY);
		} elseif ($table_mode == 'cacti') {
			// must run the tokenizer here (not the old known-tables-only LIKE scan) so that
			// tables NOT in get_cacti_tables() are actually discovered and can be classified
			// as OTHER TABLES below - scanning only for known tables can never find "other".
			get_table_associations($logid);

			$known_tables = explode(' ', trim(get_cacti_tables()));
		} elseif ($table_mode == 'reference') {
			get_table_associations($logid);

			$reference = db_fetch_cell_prepared('SELECT import_tables FROM plugin_slowlog WHERE logid = ?', [$logid]);
			if ($reference === false) {
				throw new RuntimeException('Slowlog reference lookup failed');
			}
			$known_tables = preg_split('/\s+/', trim($reference), -1, PREG_SPLIT_NO_EMPTY);
		} else {
			get_table_associations($logid);
		}

		$total_tables = cacti_sizeof($tables);

		$i = 0;

		if ($total_tables > 0) {
			foreach ($tables as $t) {
				slowlog_import_write('INSERT INTO plugin_slowlog_details_tables (logid, logentry, table_name)
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
					[$logid, $t, $logid, '%FROM ' . $t . ' %', '%FROM (' . $t . ',%', '%FROM (%,' . $t . ')%', '%JOIN ' . $t . ' %', '%`' . $t . '`%', '%UPDATE ' . $t . ' %', '%INTO ' . $t . ' %', '%FROM ' . $t]);

				if ($i % 20 == 0) {
					slowlog_import_write('UPDATE plugin_slowlog
						SET import_text_status = ?
						WHERE logid = ?',
						["$i / $total_tables Tables Processed", $logid]);
				}

				$i++;
			}
		}

		$end = microtime(true);

		cacti_log(sprintf('STATS: Time:%0.2f, Post-Processing for Tables Complete for %s', $end - $start, $logid), false, 'SLOWLOG');

		if ($table_mode == 'reference') {
			// Registers any newly-seen table names, but never touches the shared
			// is_cacti_table flag for them - that column must stay reserved for the live
			// Cacti DB (see slowlog_sync_table_dictionary()'s doc comment).
			slowlog_sync_table_dictionary($logid);
			slowlog_classify_other_tables_against_list($logid, $known_tables ?? []);
		} else {
			slowlog_sync_table_dictionary($logid, $known_tables);

			if ($known_tables !== null) {
				slowlog_classify_other_tables($logid);
			}
		}

		slowlog_set_timeouts($logid);

		slowlog_compute_stats($logid);
	}

	slowlog_import_write('UPDATE plugin_slowlog
		SET import_text_status = ?,
		import_status = 2
		WHERE logid = ?',
		['All Tables Processed', $logid]);
}

/**
 * Re-runs method/table/timeout classification for a logid that's already been imported,
 * without needing the original logfile - e.g. after new methods/tables are added to the
 * schema, or the tokenizer itself is improved. Clears the previously-derived associations
 * first since import_post_process()'s method inserts aren't safe to run twice otherwise.
 *
 * Called from import_log.php's main flow (via the '--reprocess' CLI
 * option) for a single log.
 *
 * @param int         $logid       The plugin_slowlog.logid to
 *                                 reprocess.
 * @param string      $table_names Space-separated reference table list;
 *                                 defaults to ''.
 * @param bool        $usecacti    Legacy flag equivalent to
 *                                 $table_mode = 'cacti'; defaults to
 *                                 false.
 * @param string|null $table_mode  How to distinguish Cacti tables from
 *                                 others; defaults to null (infer from
 *                                 $table_names/$usecacti).
 *
 * @return bool True on success, false when a stage failed.
 */
function slowlog_reprocess(int $logid, string $table_names = '', bool $usecacti = false, ?string $table_mode = null): bool {
	try {
		slowlog_import_write('DELETE FROM plugin_slowlog_details_methods WHERE logid = ?', [$logid]);
		slowlog_import_write('DELETE FROM plugin_slowlog_details_tables WHERE logid = ?', [$logid]);
		slowlog_import_write('DELETE FROM plugin_slowlog_stats WHERE logid = ?', [$logid]);
		slowlog_import_write('UPDATE plugin_slowlog_details SET timeout = 0 WHERE logid = ?', [$logid]);

		slowlog_import_write('UPDATE plugin_slowlog
			SET import_status = 1,
			import_text_status = ?
			WHERE logid = ?',
			['Reprocessing', $logid]);

		cacti_log("NOTE: Reprocessing logid $logid", false, 'SLOWLOG');

		return import_post_process($logid, $table_names, $usecacti, $table_mode);
	} catch (Throwable $error) {
		return slowlog_fail_import($logid, __('Post Processing Failed - Database Error', 'slowlog'));
	}
}

/**
 * slowlog_reprocess() for every logid currently in plugin_slowlog
 *
 * Called from import_log.php's main flow (via '--reprocess=all').
 *
 * @param string      $table_names Space-separated reference table list,
 *                                 applied to every log; defaults to ''.
 * @param bool        $usecacti    Legacy flag equivalent to
 *                                 $table_mode = 'cacti'; defaults to
 *                                 false.
 * @param string|null $table_mode  How to distinguish Cacti tables from
 *                                 others, applied to every log; defaults
 *                                 to null.
 *
 * @return bool True on success, false when a stage failed.
 */
function slowlog_reprocess_all(string $table_names = '', bool $usecacti = false, ?string $table_mode = null): bool {
	try {
		$logids = slowlog_import_rows('SELECT logid FROM plugin_slowlog', []);

		$success = true;

		foreach ($logids as $row) {
			$success = slowlog_reprocess($row['logid'], $table_names, $usecacti, $table_mode) && $success;
		}

		return $success;
	} catch (Throwable $error) {
		return slowlog_fail_import(null, __('Post Processing Failed - Database Error', 'slowlog'));
	}
}

/**
 * Renders this plugin's tabbed interface (Summary/By Method/By Table/
 * Details, plus a Query tab when viewing a specific entry), highlighting
 * the currently active tab. Called from each of slowlog.php's view
 * functions before rendering their content.
 *
 * @return void Outputs the tab bar HTML directly.
 *
 * @global array $config Cacti global configuration array; used to build
 *                       the tab link URLs.
 */
function slowlog_tabs(): void {
	global $config;

	// present a tabbed interface
	$tabs = [
		'select'  => 'Summary',
		'methods' => 'By Method',
		'tables'  => 'By Table',
		'details' => 'Details'];

	if (isset($_REQUEST['logentry'])) {
		$tabs = array_merge($tabs, ['query' => 'Query']);
	}

	// set the default tab
	$current_tab = $_REQUEST['action'];

	if ($current_tab == 'select') {
		unset($_REQUEST['logid']);
	}

	// draw the tabs
	print '<div class="tabs"><nav><ul>';

	if (cacti_sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print '<li><a ' . (($tab_short_name == $current_tab) ? "class='selected pic'" : "class='pic'") . " href='" . html_escape($config['url_path'] .
				'plugins/slowlog/slowlog.php' .
				'?action=' . $tab_short_name .
				(isset($_REQUEST['logid']) ? '&logid=' . $_REQUEST['logid'] : '') .
				(isset($_REQUEST['logentry']) ? '&logentry=' . $_REQUEST['logentry'] : '')) .
				"'>" . $tabs[$tab_short_name] . '</a></li>';

			if (!isset($_REQUEST['logid'])) {
				break;
			}
		}
	}

	print '</ul></nav></div>';
}

/**
 * Tokenizes every (or one specific) query in a log to discover its
 * referenced tables via slowlog_extract_tables_from_query(), and bulk-
 * inserts the resulting associations into plugin_slowlog_details_tables.
 * Called from import_post_process() for the 'cacti', 'reference', and
 * 'all' table-detection modes.
 *
 * @param int $logid    The plugin_slowlog.logid to analyze.
 * @param int $logentry A specific logentry to analyze, or -1 to analyze
 *                      every entry in the log; defaults to -1.
 *
 * @return void
 */
function get_table_associations(int $logid, int $logentry = -1): void {
	$chunk_size    = 2000;
	$last_logentry = 0;
	$associated    = 0;

	// Read the log in bounded chunks, like the method classification does, so a large log
	// is never held in memory all at once.
	do {
		$rows_out = [];

		if ($logentry == -1) {
			$rows = slowlog_import_rows('SELECT logentry, query, oquery
				FROM plugin_slowlog_details
				WHERE logid = ?
				AND logentry > ?
				ORDER BY logentry
				LIMIT ' . $chunk_size,
				[$logid, $last_logentry]);
		} else {
			$rows = slowlog_import_rows('SELECT logentry, query, oquery
				FROM plugin_slowlog_details
				WHERE logid = ? AND logentry = ?',
				[$logid, $logentry]);
		}

		$batch_count = cacti_sizeof($rows);

		foreach ($rows as $row) {
			$last_logentry = $row['logentry'];

			// The stored query has its line breaks collapsed, so a trailing -- or # comment
			// would hide the rest of the statement from the comment masking. The original text
			// keeps its newlines.
			$tables = slowlog_extract_tables_from_query(isset($row['oquery']) && $row['oquery'] !== '' ? $row['oquery'] : $row['query']);

			if (cacti_sizeof($tables)) {
				foreach ($tables as $t) {
					$rows_out[] = [$logid, $row['logentry'], $t];
				}
			} else {
				slowlog_debug('No tables found: ' . substr($row['query'], 0, 4000));
			}
		}

		if (cacti_sizeof($rows_out)) {
			$associated += cacti_sizeof($rows_out);

			slowlog_bulk_insert_table_rows($rows_out);
		}
	} while ($logentry == -1 && $batch_count === $chunk_size);

	if ($associated > 0) {
		cacti_log('Post Processing Tables associated: ' . $associated, false, 'SLOWLOG');
	}
}

/**
 * Pulls the numeric timeout out of a query using a MAX_EXECUTION_TIME(N) optimizer hint
 * (MySQL, milliseconds) or a MariaDB `SET STATEMENT max_statement_time=N FOR ...` wrapper
 * (seconds), normalized to seconds so it's comparable to query_time/lock_time. Restricted to
 * these specific syntactic forms - a SELECT-leading optimizer hint comment, or a leading SET
 * STATEMENT wrapper - rather than matching the text anywhere in the query, so a string
 * literal or an ordinary comment merely containing this text isn't mistaken for a real
 * optimizer hint. Returns null when neither is present.
 *
 * Called from slowlog_set_timeouts() for each query matching a timeout
 * hint's fragment.
 *
 * @param mixed $query The raw query text to check.
 *
 * @return float|null The detected timeout in seconds, or null if no
 *                    recognized timeout hint is present.
 */
function slowlog_extract_timeout_value($query): ?float {
	$query = slowlog_normalize_query_text(slowlog_mask_quoted_strings((string) $query));

	if (preg_match('/^SELECT\s+\/\*\+.*?MAX_EXECUTION_TIME\s*\(\s*([0-9]+(?:\.[0-9]+)?)\s*\).*?\*\//is', $query, $m)) {
		return round((float) $m[1] / 1000, 6);
	}

	if (preg_match('/^SET\s+STATEMENT\s+MAX_STATEMENT_TIME\s*=\s*([0-9]+(?:\.[0-9]+)?)\s+FOR\b/i', $query, $m)) {
		return (float) $m[1];
	}

	return null;
}

/**
 * populates plugin_slowlog_details.timeout for any row using a MAX_EXECUTION_TIME/MAX_STATEMENT_TIME hint
 *
 * Called from import_post_process() (for a whole log) and
 * get_table_associations()-adjacent reprocessing flows to detect and
 * record query-level timeout hints.
 *
 * @param int $logid    The plugin_slowlog.logid to scan.
 * @param int $logentry A specific logentry to scan, or -1 to scan every
 *                      matching entry in the log; defaults to -1.
 *
 * @return void
 */
function slowlog_set_timeouts(int $logid, int $logentry = -1): void {
	$sql_where  = 'WHERE logid = ? AND (query LIKE ? OR query LIKE ?)';
	$sql_params = [$logid, '%MAX_EXECUTION_TIME(%', '%MAX_STATEMENT_TIME%'];

	if ($logentry != -1) {
		$sql_where .= ' AND logentry = ?';
		$sql_params[] = $logentry;
	}

	$rows = slowlog_import_rows("SELECT logentry, query
		FROM plugin_slowlog_details
		$sql_where",
		$sql_params);

	foreach ($rows as $row) {
		$timeout = slowlog_extract_timeout_value($row['query']);

		if ($timeout !== null) {
			slowlog_import_write('UPDATE plugin_slowlog_details
				SET timeout = ?
				WHERE logid = ?
				AND logentry = ?',
				[$timeout, $logid, $row['logentry']]);
		}
	}
}


/**
 * Prints a debug message to stdout when debug output is enabled. Called
 * throughout this file to report progress during import/classification.
 *
 * @param string $string The debug message to print.
 *
 * @return void
 *
 * @global bool $debug Whether debug output is enabled; when false, this
 *                     function is a no-op.
 */
function slowlog_debug(string $string): void {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($string) . PHP_EOL;
	}
}


/**
 * parses a php.ini shorthand byte value (e.g. '8M', '2G', '-1'); returns null for unlimited
 *
 * Called from slowlog_upload_environment_status() to compare
 * upload_max_filesize and post_max_size.
 *
 * @param mixed $value The php.ini value to parse.
 *
 * @return int|null The value in bytes, or null for '' or '-1'
 *                  (unlimited).
 */
function slowlog_parse_ini_bytes($value): ?int {
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

/**
 * Checks the current request's upload-related php.ini settings for anything likely to make a
 * large slow-query-log import fail, so slowlog_import() can surface it before the user even
 * tries. This plugin already forces max_execution_time/memory_limit to unlimited for its own
 * requests (see the ini_set() calls at the top of slowlog.php), so those are only flagged if
 * that override didn't actually take (e.g. blocked by disable_functions or an open_basedir/
 * PHP_INI_SYSTEM restriction on the host) - and even when it does take, an independent web
 * server/proxy timeout (Apache Timeout, Nginx fastcgi_read_timeout/proxy_read_timeout, PHP-FPM
 * request_terminate_timeout) is outside PHP's control and can't be detected from here.
 *
 * Called from the import form view to display environment warnings
 * before a user attempts a large upload.
 *
 * @return array The current 'max_execution_time', 'memory_limit', and
 *               any 'warnings' detected.
 */
function slowlog_upload_environment_status(): array {
	$max_execution_time   = ini_get('max_execution_time');
	$memory_limit         = ini_get('memory_limit');
	$upload_max_filesize  = ini_get('upload_max_filesize');
	$post_max_size        = ini_get('post_max_size');

	$upload_max_bytes  = slowlog_parse_ini_bytes($upload_max_filesize);
	$post_max_bytes    = slowlog_parse_ini_bytes($post_max_size);

	$warnings = [];

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

	return [
		'max_execution_time' => $max_execution_time,
		'memory_limit'       => $memory_limit,
		'warnings'           => $warnings,
	];
}

/**
 * Maps a PHP $_FILES[...]['error'] upload error code (anything but UPLOAD_ERR_OK/_NO_FILE) to
 * a specific, translated message for raise_message(), so a rejected Slowlog upload (size
 * limits, an interrupted transfer, a server-side write failure, etc.) is never silently
 * dropped without feedback to the user.
 *
 * Called from slowlog.php's form_save() when a file upload fails.
 *
 * @param int $error_code One of the UPLOAD_ERR_* constants
 *
 * @return string The translated, user-facing error message
 */
function slowlog_upload_error_message(int $error_code): string {
	switch ($error_code) {
		case UPLOAD_ERR_INI_SIZE:
		case UPLOAD_ERR_FORM_SIZE:
			return __('ERROR: The uploaded Slowlog file exceeds the server\'s maximum upload filesize.', 'slowlog');
		case UPLOAD_ERR_PARTIAL:
			return __('ERROR: The Slowlog file upload was interrupted before it finished - please try again.', 'slowlog');
		case UPLOAD_ERR_NO_TMP_DIR:
			return __('ERROR: The server has no temporary folder configured to receive the uploaded Slowlog file.', 'slowlog');
		case UPLOAD_ERR_CANT_WRITE:
			return __('ERROR: The server failed to write the uploaded Slowlog file to disk.', 'slowlog');
		case UPLOAD_ERR_EXTENSION:
			return __('ERROR: A server extension blocked the Slowlog file upload.', 'slowlog');
		default:
			return __('ERROR: The Slowlog file upload failed (error code %d).', $error_code, 'slowlog');
	}
}

/**
 * Shared unit/suffix labels for each summable metric, used by both the raw-totals chart
 * (slowlog_get_chart_object()) and the box-whisker distribution chart
 * (slowlog_get_stats_chart_object()) so the two stay in sync.
 *
 * @return array Map of metric key (e.g. 'count', 'query_time') to its
 *               'unit' and 'suffix' display labels.
 */
function slowlog_chart_measures(): array {
	return [
		'count' => [
			'unit'   => __esc('Queries', 'slowlog'),
			'suffix' => __esc('Total Queries', 'slowlog')
		],
		'rows_sent' => [
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Returned', 'slowlog')
		],
		'rows_examined' => [
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Examined', 'slowlog')
		],
		'lock_time' => [
			'unit'   => __esc('Seconds', 'slowlog'),
			'suffix' => __esc('Lock Seconds', 'slowlog')
		],
		'query_time' => [
			'unit'   => __esc('Seconds', 'slowlog'),
			'suffix' => __esc('Query Seconds', 'slowlog')
		],
		'rows_affected' => [
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Affected', 'slowlog')
		],
		'bytes_sent' => [
			'unit'   => __esc('Bytes', 'slowlog'),
			'suffix' => __esc('Bytes Sent', 'slowlog')
		]
	];
}


/**
 * The details-page filters that slowlog_details_filter_url() carries forward when
 * overriding one of them.
 *
 * @return array The details view's filter field names.
 */
function slowlog_details_filter_fields(): array {
	return ['logid', 'mmethod', 'method_name', 'table', 'user', 'host', 'filter', 'date1', 'date2', 'rows'];
}

/**
 * Each clearable field's 'unset' value - shared between the click-to-filter links (table/
 * method/user/host cells) and their per-field 'clear this filter' trash-can links, so both
 * always agree on what 'default' means for a given field. date1/date2/filter/rows/logid
 * aren't included: they're not set by the click-to-filter links, so they have no trash can.
 *
 * @return array Map of clearable filter field name to its default
 *               ('unset') value.
 */
function slowlog_details_filter_defaults(): array {
	return [
		'mmethod'     => '-1',
		'method_name' => '',
		'table'       => '-1',
		'user'        => '-1',
		'host'        => '-1'
	];
}

/**
 * Builds a details-page URL that keeps every current filter as-is except $field, which is
 * set to $value. Passing a field's own default (see slowlog_details_filter_defaults()) as
 * $value clears just that one filter. method_name and mmethod both represent "the Method
 * filter" (by name vs by id), so setting either one clears the other.
 *
 * Called from the details view's click-to-filter table/method/user/host
 * cell links, and from slowlog_details_filter_clear_glyph().
 *
 * @param string $field The filter field to override.
 * @param string $value The new value for $field.
 *
 * @return string The resulting slowlog.php details URL.
 */
function slowlog_details_filter_url(string $field, string $value): string {
	$current = [];

	foreach (slowlog_details_filter_fields() as $key) {
		$current[$key] = get_request_var($key);
	}

	$current[$field] = $value;

	if ($field == 'method_name' && $value != '') {
		$current['mmethod'] = '-1';
	} elseif ($field == 'mmethod') {
		$current['method_name'] = '';
	}

	$query = 'action=details';

	foreach ($current as $key => $value) {
		$query .= '&' . $key . '=' . urlencode($value);
	}

	return 'slowlog.php?' . $query;
}

/**
 * Whether $field currently differs from its default - i.e. whether its 'clear this filter'
 * trash-can link should be shown at all.
 *
 * Called from slowlog_details_filter_clear_glyph().
 *
 * @param string $field The filter field to check.
 *
 * @return bool True if the field's current value differs from its
 *              default, false otherwise.
 */
function slowlog_details_filter_is_active(string $field): bool {
	$defaults = slowlog_details_filter_defaults();

	if ($field == 'mmethod') {
		return get_request_var('mmethod') != $defaults['mmethod'] || get_request_var('method_name') != $defaults['method_name'];
	}

	return get_request_var($field) != $defaults[$field];
}

/**
 * A small trash-can icon next to a details-page filter label, shown only while that filter
 * is active, that clears just that one field (leaving every other active filter alone).
 * Uses the same 'pic' anchor+icon pairing as the row-action icons elsewhere in this file
 * (e.g. the 'View Details' magnifying glass), rather than a bespoke CSS class.
 *
 * Called from slowlog_view_details() next to each filterable column
 * header.
 *
 * @param string $field The filter field this glyph clears.
 *
 * @return string The clear-filter link HTML, or '' if the filter isn't
 *                currently active.
 */
function slowlog_details_filter_clear_glyph(string $field): string {
	if (!slowlog_details_filter_is_active($field)) {
		return '';
	}

	$defaults = slowlog_details_filter_defaults();
	$url      = slowlog_details_filter_url($field, $defaults[$field]) . '&header=false';

	return " <a class='pic slowlogClearFilter' href='#' data-url='" . $url . "'><i class='fa fa-trash-alt pic' title='" . __esc('Clear this filter', 'slowlog') . "'></i></a>";
}


/**
 * Resolves the active Cacti theme's chart surface colors so the plugin's
 * ApexCharts match whatever core theme (and dark/light color mode) the user has
 * selected, instead of a single hard-coded palette.
 *
 * Mirrors Cacti core's own rrdtheme loading (lib/rrd.php): the selected theme is
 * validated against the themes allowlist, its rrdtheme.php is required for the
 * canonical font/grid colors, and the CactiColorMode cookie's dark/light
 * variants are honored when a theme defines them. The light/dark ApexCharts mode
 * is derived from the luminance of the theme's own font color, so every current
 * and future theme is covered without a hand-maintained theme-name list.
 *
 * Called from slowlog_import() and slowlog_view_charts() to skin the upload
 * donut and the By Method/By Table charts.
 *
 * @return array{mode:string,foreColor:string,gridColor:string,background:string}
 *               ApexCharts theme mode ('light'|'dark') plus '#rrggbb' text,
 *               gridline, and chart-surface background colors.
 *
 * @global array $config Cacti global configuration array; used to locate the
 *                       active theme's rrdtheme.php.
 */
function slowlog_apex_theme(): array {
	global $config;

	$theme = get_selected_theme();
	$theme = function_exists('cacti_validate_theme') ? cacti_validate_theme($theme) : basename($theme);

	$rrdcolors   = [];
	$themes_base = $config['base_path'] . '/include/themes';
	$themefile   = $themes_base . '/' . $theme . '/rrdtheme.php';

	// cacti_validate_theme() already allowlists the name, but confine the resolved
	// path to the themes directory as defense-in-depth so a poisoned theme can
	// never steer this require() outside the tree (CVE-class path traversal). Fail
	// fast with require() once the file is confirmed readable and contained.
	if (strpos($themefile, "\0") === false
		&& is_readable($themefile)
		&& (!function_exists('cacti_path_is_within') || cacti_path_is_within($themefile, $themes_base))) {
		require($themefile);

		// Themes such as midwinter ship dark/light variants selected by the same
		// CactiColorMode cookie core keys off of; prefer the matching variant.
		if (isset($_COOKIE['CactiColorMode']) && in_array($_COOKIE['CactiColorMode'], ['dark', 'light'], true)) {
			$variant = 'rrdcolors_' . $_COOKIE['CactiColorMode'];

			if (isset($$variant) && is_array($$variant)) {
				$rrdcolors = array_merge($rrdcolors, $$variant);
			}
		}
	}

	$font = slowlog_normalize_hex_color($rrdcolors['font'] ?? '', '000000');
	$grid = slowlog_normalize_hex_color($rrdcolors['grid'] ?? '', 'cccccc');

	// The chart surface must follow the theme too, or ApexCharts paints its default
	// white plot background over a dark theme. Prefer the theme's panel background
	// ('back'), falling back to the plotting canvas, then white for light defaults.
	$back = slowlog_normalize_hex_color($rrdcolors['back'] ?? ($rrdcolors['canvas'] ?? ''), 'ffffff');

	// A light font implies a dark theme; ApexCharts' mode drives the tooltip surface.
	$r = hexdec(substr($font, 0, 2));
	$g = hexdec(substr($font, 2, 2));
	$b = hexdec(substr($font, 4, 2));

	$luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);

	return [
		'mode'       => ($luminance >= 128) ? 'dark' : 'light',
		'foreColor'  => '#' . $font,
		'gridColor'  => '#' . $grid,
		'background' => '#' . $back
	];
}

/**
 * Normalizes a Cacti rrdtheme color (which may carry an RRDtool alpha suffix or
 * be shorthand) into a plain 6-digit lowercase hex string usable by CSS/SVG.
 *
 * Called from slowlog_apex_theme() to sanitize the theme's font/grid colors.
 *
 * @param string $value    Raw rrdtheme color, e.g. 'FFFFffaa' or '21212400'.
 * @param string $fallback 6-digit hex to return when $value has no usable hex.
 *
 * @return string A 6-character lowercase hex string (no leading '#').
 */
function slowlog_normalize_hex_color(string $value, string $fallback): string {
	$hex = preg_replace('/[^0-9A-Fa-f]/', '', $value);

	if (strlen($hex) >= 6) {
		return strtolower(substr($hex, 0, 6));
	}

	if (strlen($hex) === 3) {
		return strtolower($hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]);
	}

	return strtolower($fallback);
}

require_once __DIR__ . '/sql_tables.php';
require_once __DIR__ . '/statistics.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/log_parser.php';
