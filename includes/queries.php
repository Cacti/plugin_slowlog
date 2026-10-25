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
 * Reads the cached box-whisker summary (plugin_slowlog_stats) for one metric, scoped to
 * either methods or tables, and shapes it into the categories/box-data/p95-data arrays
 * renderBoxChart() expects. Ordered/limited the same way as slowlog_get_chart_object() (by
 * total value descending, capped to the chart filter's Top N selectmenu) so the raw and
 * whisker charts for the same metric show categories in the same order. $scope_filter
 * optionally restricts to specific scope_key values (the chart filter's multiselect);
 * $include_max defaults to false so the box's top value is p95 (not the true max), since a
 * rare true-max outlier can otherwise flatten the rest of the box - pass true to show the
 * true max instead.
 *
 * Called from slowlog_view_charts() to render the box-whisker chart.
 *
 * @param string $chart_type   Which grouping to chart: 'methods' or
 *                             'tables'.
 * @param string $measure      Which SLOWLOG_STATS_METRICS metric to
 *                             summarize.
 * @param array  $scope_filter Selected method/table scope values to
 *                             restrict to; defaults to an empty array
 *                             (no restriction).
 * @param bool   $include_max  Whether to use the true max value as the
 *                             box's top point instead of p95; defaults
 *                             to false.
 *
 * @return array Chart data: 'title', 'categories', 'box_data',
 *               'p95_data', 'yaxislabel'.
 */
function slowlog_get_stats_chart_object(string $chart_type, string $measure, array $scope_filter = [], bool $include_max = false): array {
	return slowlog_build_stats_chart((int) get_filter_request_var('logid'), $chart_type, $measure, $scope_filter, $include_max, (int) get_request_var('chart_top'));
}

/**
 * Build chart data with explicit import identity and result limit.
 *
 * @param int $id Parent import ID.
 * @param string $chart_type Method or table chart.
 * @param string $measure Requested metric.
 * @param array $scope_filter Selected group keys.
 * @param bool $include_max Use the exact maximum instead of p95.
 * @param int $top Requested unfiltered result limit.
 *
 * @return array Chart data.
 */
function slowlog_build_stats_chart(int $id, string $chart_type, string $measure, array $scope_filter = [], bool $include_max = false, int $top = 10): array {

	if (!in_array($measure, SLOWLOG_STATS_METRICS, true)) {
		return [];
	}

	$description = db_fetch_cell_prepared('SELECT description
		FROM plugin_slowlog
		WHERE logid = ?',
		[$id]);

	$scope = ($chart_type != 'tables') ? 'method' : 'table';

	$limit = (cacti_sizeof($scope_filter) ? '' : ' LIMIT ' . max(1, min(10000, $top)));

	$params      = [$id, $scope, $measure];
	$scope_where = '';

	if (cacti_sizeof($scope_filter)) {
		$scope_where = ' AND scope_key IN (' . implode(',', array_fill(0, count($scope_filter), '?')) . ')';
		$params      = array_merge($params, $scope_filter);
	}

	$stats = db_fetch_assoc_prepared('SELECT scope_key, sample_count, total_value,
			min_value, p25_value, median_value, p75_value, p95_value, max_value
		FROM plugin_slowlog_stats
		WHERE logid = ?
		AND scope = ?
		AND metric = ?' . $scope_where . '
		ORDER BY total_value DESC' . $limit,
		$params);

	$measures = slowlog_chart_measures();

	$categories = [];
	$box_data   = [];
	$p95_data   = [];

	foreach ($stats as $row) {
		$categories[] = $row['scope_key'];

		$box_max = $include_max ? $row['max_value'] : $row['p95_value'];

		$box_data[] = [
			'x' => $row['scope_key'],
			'y' => [
				round((float) $row['min_value'], 3),
				round((float) $row['p25_value'], 3),
				round((float) $row['median_value'], 3),
				round((float) $row['p75_value'], 3),
				round((float) $box_max, 3)
			]
		];

		$p95_data[] = [
			'x' => $row['scope_key'],
			'y' => round((float) $row['p95_value'], 3)
		];
	}

	$title = $description . ' [ ' . $measures[$measure]['suffix'] . ' Distribution ]';

	return [
		'title'      => $title,
		'categories' => $categories,
		'box_data'   => $box_data,
		'p95_data'   => $p95_data,
		'yaxislabel' => $measures[$measure]['unit']
	];
}

/**
 * Reads the cached totals (plugin_slowlog_stats) for one metric, scoped to either methods or
 * tables, and shapes them into the categories/values arrays renderChart() expects. Reuses the
 * same stats cache the box-whisker chart (slowlog_get_stats_chart_object()) reads, rather than
 * live-aggregating plugin_slowlog_details on every chart view. $scope_filter optionally
 * restricts to specific scope_key values (the chart filter's multiselect). Falls back to live
 * aggregation for unfiltered logs imported before the stats cache existed, so upgrading doesn't
 * blank their charts (live aggregation doesn't support $scope_filter).
 *
 * Called from slowlog_view_charts() to render the raw-totals chart.
 *
 * @param string $chart_type   Which grouping to chart: 'methods' or
 *                             'tables'.
 * @param string $measure      Which metric to total: 'count' or one of
 *                             SLOWLOG_STATS_METRICS.
 * @param array  $scope_filter Selected method/table scope values to
 *                             restrict to; defaults to an empty array
 *                             (no restriction).
 *
 * @return array Chart data: 'title', 'categories', 'values',
 *               'yaxislabel'; an empty array for an invalid $measure.
 */
function slowlog_get_chart_object(string $chart_type, string $measure, array $scope_filter = []): array {
	return slowlog_build_totals_chart((int) get_filter_request_var('logid'), $chart_type, $measure, $scope_filter, (int) get_request_var('chart_top'));
}

/**
 * Build chart data with explicit import identity and result limit.
 *
 * @param int $id Parent import ID.
 * @param string $chart_type Method or table chart.
 * @param string $measure Requested metric.
 * @param array $scope_filter Selected group keys.
 * @param int $top Requested unfiltered result limit.
 *
 * @return array Chart data.
 */
function slowlog_build_totals_chart(int $id, string $chart_type, string $measure, array $scope_filter = [], int $top = 10): array {
	if ($measure != 'count' && !in_array($measure, SLOWLOG_STATS_METRICS, true)) {
		return [];
	}


	$description = db_fetch_cell_prepared('SELECT description
		FROM plugin_slowlog
		WHERE logid = ?',
		[$id]);

	$scope = ($chart_type != 'tables') ? 'method' : 'table';

	$limit = (cacti_sizeof($scope_filter) ? '' : ' LIMIT ' . max(1, min(10000, $top)));

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

	$params      = [$id, $scope, $stats_metric];
	$scope_where = '';

	if (cacti_sizeof($scope_filter)) {
		$scope_where = ' AND scope_key IN (' . implode(',', array_fill(0, count($scope_filter), '?')) . ')';
		$params      = array_merge($params, $scope_filter);
	}

	$stats = db_fetch_assoc_prepared("SELECT scope_key, $value_column AS value
		FROM plugin_slowlog_stats
		WHERE logid = ?
		AND scope = ?
		AND metric = ?" . $scope_where . "
		ORDER BY $value_column DESC" . $limit,
		$params);

	// No cached stats at all for this log (as opposed to cached stats that happen to have
	// no rows for this scope/metric) means it was imported before the stats cache existed.
	// Live aggregation doesn't support scope filtering, so only fall back when unfiltered.
	if (!cacti_sizeof($stats) && !cacti_sizeof($scope_filter) && !slowlog_has_stats_cache($id)) {
		$stats = slowlog_get_chart_object_live($id, $scope, $measure, $limit);
	}

	$measures = slowlog_chart_measures();

	$categories = [];
	$values     = [];

	foreach ($stats as $entry) {
		$categories[] = $entry['scope_key'];
		$values[]     = $entry['value'];
	}

	$title = $description . ' [ ' . $measures[$measure]['suffix'] . ' ]';

	// Always return the full shape (with empty categories/values when $stats is empty) so
	// callers can safely index every key without a null-guard of their own.
	return [
		'title'      => $title,
		'categories' => $categories,
		'values'     => $values,
		'yaxislabel' => $measures[$measure]['unit']
	];
}

/**
 * Whether any plugin_slowlog_stats rows exist at all for $logid, regardless of scope/metric.
 * Distinguishes "never cached (pre-cache log)" from "cached, but legitimately no matches".
 *
 * Called from slowlog_get_chart_object() to decide whether to fall back
 * to live aggregation.
 *
 * @param int $logid The plugin_slowlog.logid to check.
 *
 * @return bool True if at least one cached stats row exists for this
 *              log, false otherwise.
 */
function slowlog_has_stats_cache(int $logid): bool {
	return (bool) db_fetch_cell_prepared('SELECT 1
		FROM plugin_slowlog_stats
		WHERE logid = ?
		LIMIT 1',
		[$logid]);
}

/**
 * Live-aggregates plugin_slowlog_details for one measure, scoped to methods or tables, matching
 * the pre-cache query shape. Only used as a fallback for logs imported before the
 * plugin_slowlog_stats cache existed (slowlog_has_stats_cache() returns false for them), since
 * their raw-totals charts have no cached rows to read.
 *
 * Called from slowlog_get_chart_object() when no stats cache exists for
 * the log.
 *
 * @param int    $logid   The plugin_slowlog.logid to aggregate.
 * @param string $scope   Which grouping to aggregate: 'method' or
 *                        'table'.
 * @param string $measure Which metric to total: 'count' or one of
 *                        SLOWLOG_STATS_METRICS.
 * @param string $limit   The ' LIMIT N' SQL fragment (from
 *                        slowlog_chart_top_limit()) to apply.
 *
 * @return array The aggregated rows, each with 'scope_key' and 'value'.
 */
function slowlog_get_chart_object_live(int $logid, string $scope, string $measure, string $limit): array {
	$agg = ($measure == 'count') ? 'COUNT(*)' : "SUM($measure)";

	if ($scope == 'method') {
		return db_fetch_assoc_prepared("SELECT dm.method AS scope_key,
			$agg AS value
			FROM plugin_slowlog_details_methods AS dm
			INNER JOIN plugin_slowlog_details AS d
			ON dm.logid = d.logid
			AND dm.logentry = d.logentry
			WHERE d.logid = ?
			GROUP BY dm.method
			ORDER BY value DESC" . $limit,
			[$logid]);
	} else {
		$bucket_key = slowlog_others_bucket_key($logid);

		return db_fetch_assoc_prepared("SELECT *
			FROM (
				SELECT table_name AS scope_key,
					$agg AS value
				FROM plugin_slowlog_details_tables AS dt
				INNER JOIN plugin_slowlog_details AS d
				ON dt.logid=d.logid AND dt.logentry=d.logentry
				WHERE d.logid = ?
				GROUP BY table_name
				UNION ALL
				SELECT ? AS scope_key,
					$agg AS value
				FROM plugin_slowlog_details AS d
				LEFT JOIN plugin_slowlog_details_tables AS dt
				ON dt.logid=d.logid AND dt.logentry=d.logentry
				WHERE dt.table_name IS NULL
				AND d.logid = ?
				GROUP BY table_name
			) AS fish
			ORDER BY value DESC" . $limit,
			[$logid, $bucket_key, $logid]);
	}
}

/**
 * The valid chart_scope values for a given chart type/log - i.e. the option list the
 * scope multiselect renders, and also the allow-list chart_scope's post-validation
 * filters submitted values down to (see slowlog_request_charts_validation()).
 *
 * Reads scope_key values from plugin_slowlog_stats (scoped to this logid), not the master
 * method dictionary or the raw table-association tables directly, so the dropdown only ever
 * offers methods/tables this specific log actually has cached data for - a method that exists
 * in the global dictionary (or a table with zero matched rows) never appears as a selectable,
 * empty-chart-producing option. Falls back to the pre-cache raw tables for logs imported
 * before plugin_slowlog_stats existed (slowlog_has_stats_cache() returns false for them),
 * matching slowlog_get_chart_object()'s live-aggregation fallback.
 *
 * Called from slowlog_charts_filter() to populate the scope multiselect,
 * and from slowlog_request_charts_validation() to validate submitted
 * scope values.
 *
 * @param string $chart_type Which grouping to list items for: 'methods'
 *                           or 'tables'.
 * @param int    $id         The plugin_slowlog.logid to list available
 *                           scope items for.
 *
 * @return array<int, string> The available scope values for this log/
 *                            chart type.
 */
function slowlog_get_chart_scope_items(string $chart_type, int $id): array {
	$scope = ($chart_type == 'tables') ? 'table' : 'method';

	if (slowlog_has_stats_cache($id)) {
		return array_column(db_fetch_assoc_prepared('SELECT DISTINCT scope_key AS value
			FROM plugin_slowlog_stats
			WHERE logid = ?
			AND scope = ?
			ORDER BY scope_key',
			[$id, $scope]), 'value');
	}

	if ($chart_type == 'tables') {
		$scope_items = array_column(db_fetch_assoc_prepared('SELECT DISTINCT table_name AS value
			FROM plugin_slowlog_details_tables
			WHERE logid = ?
			ORDER BY table_name',
			[$id]), 'value');

		$scope_items[] = slowlog_others_bucket_key($id);
	} else {
		$scope_items = array_column(db_fetch_assoc_prepared('SELECT DISTINCT dm.method AS value
			FROM plugin_slowlog_details_methods AS dm
			WHERE dm.logid = ?
			ORDER BY dm.method',
			[$id]), 'value');
	}

	return $scope_items;
}

/**
 * Fetch a details page using explicit validated filters and a whitelisted sort.
 *
 * @param array $filters Detail filters, pagination and sorting values.
 *
 * @return array Results, matching association-row count, page size and page number.
 */
function slowlog_fetch_detail_page(array $filters): array {
	[$rows, $page] = slowlog_page_bounds($filters['rows'] ?? 20, $filters['page'] ?? 1);
	$sql_params  = [];
	$sql_where   = '';
	$sort_columns = ['table_name', 'method', 'date', 'user', 'host', 'query_time', 'lock_time', 'rows_sent', 'rows_examined', 'rows_affected', 'bytes_sent'];
	$sort = in_array($filters['sort_column'] ?? '', $sort_columns, true) ? $filters['sort_column'] : 'table_name';
	$direction = strtoupper($filters['sort_direction'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
	$sql_orderby = 'ORDER BY `' . $sort . '` ' . $direction;
	$sql_limit   = ' LIMIT ' . ($rows * ($page - 1)) . ',' . $rows;

	// form the 'where' clause for our main sql query
	if (($filters['filter'] ?? '') != '') {
		$sql_where = 'WHERE (sld.host LIKE ? OR sld.user LIKE ? OR sld.query LIKE ? OR sld.ip_address LIKE ?)';

		$sql_params[] = '%' . ($filters['filter'] ?? '') . '%';
		$sql_params[] = '%' . ($filters['filter'] ?? '') . '%';
		$sql_params[] = '%' . ($filters['filter'] ?? '') . '%';
		$sql_params[] = '%' . ($filters['filter'] ?? '') . '%';
	}

	if (($filters['logid'] ?? '-1') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sld.logid = ?';

		$sql_params[] = ($filters['logid'] ?? '-1');
	}

	if (($filters['user'] ?? '-1') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sld.user = ?';

		$sql_params[] = ($filters['user'] ?? '-1');
	}

	if (($filters['host'] ?? '-1') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sld.host = ?';

		$sql_params[] = ($filters['host'] ?? '-1');
	}

	$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' date BETWEEN ? AND ?';

	$sql_params[] = ($filters['date1'] ?? '');
	$sql_params[] = ($filters['date2'] ?? '');

	// Aggregation and down-select filters
	$agg_by_table  = false;
	$agg_by_method = false;
	$sql_join      = '';
	$method        = 'sldm.method';
	$table         = 'sldt.table_name';

	if (($filters['table'] ?? '-1') == '-3') { // Aggregate by table
		$agg_by_table = true;
	} elseif (($filters['table'] ?? '-1') == '-2') { // Others
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sldt.table_name IS NULL';
	} elseif (($filters['table'] ?? '-1') != '-1') { // Not All
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sldt.table_name = ?';

		$sql_params[] = ($filters['table'] ?? '-1');
	}

	// method_name (set by clicking a bar on the By Method chart) and the mmethod dropdown
	// both filter on the method name now stored directly in
	// plugin_slowlog_details_methods.method (sldm join below), so neither needs the old
	// plugin_slowlog_methods dictionary join.
	$has_method_name = (($filters['method_name'] ?? '') != '');

	if ($has_method_name) {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sldm.method = ?';

		$sql_params[] = ($filters['method_name'] ?? '');
	} elseif (($filters['mmethod'] ?? '-1') == '-2') { // Aggregate by method
		$agg_by_method = true;
	} elseif (($filters['mmethod'] ?? '-1') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . ' sldm.method = ?';

		$sql_params[] = ($filters['mmethod'] ?? '-1');
	}

	if (!$agg_by_table) {
		$sql_join = ' LEFT JOIN plugin_slowlog_details_tables AS sldt
			ON sld.logid = sldt.logid
			AND sld.logentry = sldt.logentry';
	} else {
		$table = '"N/A" AS table_name';
	}

	if (!$agg_by_method) {
		$sql_join .= ' LEFT JOIN plugin_slowlog_details_methods AS sldm
			ON sld.logid = sldm.logid
			AND sld.logentry = sldm.logentry';
	} else {
		$method = '"N/A" AS method';
	}

	// No DISTINCT: each detail/method/table join row is already unique (the association
	// tables' composite PKs), so it only forced a filesort/hash over the mediumtext query columns.
	$results = db_fetch_assoc_prepared("SELECT sld.*, $method, $table
		FROM plugin_slowlog_details AS sld
		$sql_join
		$sql_where
		$sql_orderby
		$sql_limit",
		$sql_params);


	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_slowlog_details AS sld
		$sql_join
		$sql_where",
		$sql_params);

	return ['results' => $results, 'total_rows' => (int) $total_rows, 'rows' => $rows, 'page' => $page];
}
