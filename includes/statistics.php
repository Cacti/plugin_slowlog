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

const SLOWLOG_STATS_SAMPLE_CAP = 20000;
const SLOWLOG_STATS_TOTAL_SAMPLE_CAP = 200000;
const SLOWLOG_STATS_GROUP_CAP = 10000;

/**
 * Linear-interpolation percentile (matches numpy's default / Excel PERCENTILE.INC) over an
 * already-sorted array of numeric values. $p is 0-100. Computed in PHP rather than via a SQL
 * PERCENTILE_CONT window function since that isn't available on every MySQL/MariaDB version
 * this plugin still supports.
 *
 * Called from slowlog_summarize_values() to compute each metric's
 * p25/median/p75/p95 box-whisker values.
 *
 * @param array     $sorted An already ascending-sorted array of numeric
 *                          values.
 * @param int|float $p      The desired percentile, 0-100.
 *
 * @return float The interpolated percentile value, or 0.0 for an empty
 *               input array.
 */
function slowlog_percentile(array $sorted, $p): float {
	$n = cacti_sizeof($sorted);

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

/**
 * Reduces one metric's raw value list (any order) into the summary plugin_slowlog_stats
 * stores for it: sample count, sum, and the min/p25/median/p75/p95/max box-whisker points.
 * $exact_count/$exact_sum/$exact_min/$exact_max override the values derived from $values, for
 * callers (e.g. slowlog_compute_stats()) that only pass in a bounded sample of the real
 * population.
 *
 * @param array      $values      The (possibly sampled) raw metric values
 *                                to summarize.
 * @param int|null   $exact_count The true total count to report, overriding
 *                                count($values) when the caller only passed a
 *                                sample; defaults to null (use count($values)).
 * @param float|null $exact_sum   The true total sum to report, overriding
 *                                array_sum($values) when the caller only
 *                                passed a sample; defaults to null (use
 *                                array_sum($values)).
 * @param float|null $exact_min   The true minimum to report, overriding the
 *                                sample's minimum; defaults to null.
 * @param float|null $exact_max   The true maximum to report, overriding the
 *                                sample's maximum; defaults to null.
 *
 * @return array The summary: 'sample_count', 'total_value', 'min_value',
 *               'p25_value', 'median_value', 'p75_value', 'p95_value',
 *               'max_value'.
 */
function slowlog_summarize_values(array $values, ?int $exact_count = null, ?float $exact_sum = null, ?float $exact_min = null, ?float $exact_max = null): array {
	$count = ($exact_count !== null) ? $exact_count : cacti_sizeof($values);

	if ($count === 0 || !cacti_sizeof($values)) {
		return [
			'sample_count' => 0,
			'total_value'  => 0.0,
			'min_value'    => 0.0,
			'p25_value'    => 0.0,
			'median_value' => 0.0,
			'p75_value'    => 0.0,
			'p95_value'    => 0.0,
			'max_value'    => 0.0,
		];
	}

	sort($values, SORT_NUMERIC);

	return [
		'sample_count' => $count,
		'total_value'  => ($exact_sum !== null) ? $exact_sum : array_sum($values),
		'min_value'    => ($exact_min !== null) ? $exact_min : $values[0],
		'p25_value'    => slowlog_percentile($values, 25),
		'median_value' => slowlog_percentile($values, 50),
		'p75_value'    => slowlog_percentile($values, 75),
		'p95_value'    => slowlog_percentile($values, 95),
		'max_value'    => ($exact_max !== null) ? $exact_max : $values[cacti_sizeof($values) - 1],
	];
}

/**
 * Bound reservoirs across all metric groups, using power-of-two group tiers.
 *
 * @param int $groups Number of active groups.
 *
 * @return int Samples retained per group and metric.
 */
function slowlog_stats_sample_limit(int $groups): int {
	// Consecutive values usually belong to the same set of active groups.
	// Keep only the last pure calculation, without retaining import data.
	static $last_groups = 0;
	static $last_limit = SLOWLOG_STATS_SAMPLE_CAP;
	if ($groups !== $last_groups) {
		$tier = 1 << (int) ceil(log(max(1, $groups), 2));
		$last_limit = min(SLOWLOG_STATS_SAMPLE_CAP, max(1, intdiv(SLOWLOG_STATS_TOTAL_SAMPLE_CAP, $tier * cacti_sizeof(SLOWLOG_STATS_METRICS))));
		$last_groups = $groups;
	}
	return $last_limit;
}

/**
 * Records one metric value into the bounded reservoir $values[$scope_key][$metric] (capped at
 * SLOWLOG_STATS_SAMPLE_CAP elements via reservoir sampling) while $totals[$scope_key][$metric]
 * keeps an exact running count/sum/min/max, so the count, total, min and max never lose
 * precision even
 * once the reservoir is full and older samples start being probabilistically replaced.
 *
 * Called from slowlog_collect_stats_by_method(),
 * slowlog_collect_stats_by_matched_table(), and
 * slowlog_collect_stats_by_unmatched_table() for each detail row read.
 *
 * @param array  $values    Reference, the per-scope/metric bounded value
 *                          reservoirs being built up.
 * @param array  $totals    Reference, the per-scope/metric exact running
 *                          count/sum totals being built up.
 * @param string $scope_key The method or table name (or 'others' bucket)
 *                          this value belongs to.
 * @param string $metric    Which metric this value is for (e.g.
 *                          'query_time').
 * @param float  $value     The metric value to record.
 *
 * @return void
 */
function slowlog_accumulate_stat_value(array &$values, array &$totals, string $scope_key, string $metric, float $value): void {
	if (!isset($values[$scope_key])) {
		$groups = cacti_sizeof($values) + 1;
		if ($groups > SLOWLOG_STATS_GROUP_CAP) {
			throw new RuntimeException('Slowlog statistics group limit exceeded');
		}
		$limit = slowlog_stats_sample_limit($groups);
		// Shrink only as the group tier grows; uniformly sub-sampling a reservoir
		// preserves its sampling semantics while bounding all groups together.
		if ($limit < slowlog_stats_sample_limit($groups - 1)) {
			foreach ($values as &$group) {
				foreach ($group as &$sample) {
					if (cacti_sizeof($sample) > $limit) {
						$keys = (array) array_rand($sample, $limit);
						$sample = array_values(array_intersect_key($sample, array_flip($keys)));
					}
				}
				unset($sample);
			}
			unset($group);
		}
		$values[$scope_key] = [];
	}
	$limit = slowlog_stats_sample_limit(cacti_sizeof($values));
	if (!isset($totals[$scope_key][$metric])) {
		$totals[$scope_key][$metric] = ['count' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
		$values[$scope_key][$metric] = [];
	}
	$total = &$totals[$scope_key][$metric];
	$sample = &$values[$scope_key][$metric];
	$total['count']++;
	$total['sum'] += $value;
	$total['min'] = min($total['min'], $value);
	$total['max'] = max($total['max'], $value);
	if (cacti_sizeof($sample) < $limit) {
		$sample[] = $value;
	} else {
		$slot = mt_rand(0, $total['count'] - 1);
		if ($slot < $limit) {
			$sample[$slot] = $value;
		}
	}
}

/**
 * Bulk-inserts plugin_slowlog_stats rows, fully parameterized like the method/table bulk
 * inserters above. $rows is an array of the assoc arrays slowlog_summarize_values() returns,
 * each additionally carrying 'logid', 'scope', 'scope_key', and 'metric'.
 *
 * Called from slowlog_compute_stats() to persist the computed box-whisker
 * summaries for a log.
 *
 * @param array $rows The summary rows to insert (or update on duplicate
 *                    key).
 *
 * @return void
 */
function slowlog_bulk_insert_stats_rows(array $rows): void {
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

	foreach (array_chunk($rows, 200) as $chunk) {
		$placeholders = [];
		$params       = [];

		foreach ($chunk as $row) {
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

		slowlog_import_write($sql_prefix . implode(', ', $placeholders) . $sql_suffix, $params);
	}
}

/**
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
 *
 * Called from slowlog_compute_stats() to gather the per-method metric
 * values to summarize.
 *
 * @param int   $logid      The plugin_slowlog.logid to collect stats
 *                          for.
 * @param array $values     Reference, accumulates the per-method/metric
 *                          bounded value reservoirs.
 * @param int   $chunk_size The number of rows to fetch per query page;
 *                          defaults to 5000.
 * @param array $totals     Reference, accumulates the per-method/metric
 *                          exact running count/sum totals.
 *
 * @return void
 */
function slowlog_collect_stats_by_method(int $logid, array &$values, int $chunk_size = 5000, array &$totals = []): void {
	$last_id = 0;

	do {
		$rows = slowlog_import_rows('SELECT sldm.id, sldm.method AS scope_key,
			d.query_time, d.rows_sent, d.rows_examined, d.rows_affected, d.bytes_sent
			FROM plugin_slowlog_details_methods AS sldm
			INNER JOIN plugin_slowlog_details AS d ON d.logid = sldm.logid AND d.logentry = sldm.logentry
			WHERE sldm.logid = ?
			AND sldm.id > ?
			ORDER BY sldm.id
			LIMIT ' . (int) $chunk_size,
			[$logid, $last_id]);

		$batch_count = cacti_sizeof($rows);

		foreach ($rows as $row) {
			$last_id = $row['id'];

			foreach (SLOWLOG_STATS_METRICS as $metric) {
				slowlog_accumulate_stat_value($values, $totals, $row['scope_key'], $metric, (float) $row[$metric]);
			}
		}
	} while ($batch_count === $chunk_size);
}

/**
 * Same as slowlog_collect_stats_by_method(), but grouped by table_name - including an
 * 'others' bucket for entries with no recognized table, matching the label
 * slowlog_get_chart_object() already uses for that bucket in the raw-totals chart. Split into
 * two independently-paginated queries rather than one UNION ALL cursored on the shared (and,
 * for the matched branch, non-unique) logentry column: the matched branch pages on
 * plugin_slowlog_details_tables.tableid (unique - a logentry can have several table rows,
 * same pitfall as the method collector above), while the "others" branch pages on
 * plugin_slowlog_details.logentry, which - unlike the matched branch - really is unique there
 * (a logentry with no table match can only ever produce one row via the LEFT JOIN).
 *
 * Called from slowlog_compute_stats() to gather the per-table metric
 * values to summarize.
 *
 * @param int   $logid      The plugin_slowlog.logid to collect stats
 *                          for.
 * @param array $values     Reference, accumulates the per-table/metric
 *                          bounded value reservoirs.
 * @param int   $chunk_size The number of rows to fetch per query page;
 *                          defaults to 5000.
 * @param array $totals     Reference, accumulates the per-table/metric
 *                          exact running count/sum totals.
 *
 * @return void
 */
function slowlog_collect_stats_by_table(int $logid, array &$values, int $chunk_size = 5000, array &$totals = []): void {
	slowlog_collect_stats_by_matched_table($logid, $values, $chunk_size, $totals);
	slowlog_collect_stats_by_unmatched_table($logid, $values, $chunk_size, $totals);
}

/**
 * Streams the matched (recognized table_name) half of the per-table
 * stats collection, paginated by plugin_slowlog_details_tables.tableid.
 * Called from slowlog_collect_stats_by_table().
 *
 * @param int   $logid      The plugin_slowlog.logid to collect stats
 *                          for.
 * @param array $values     Reference, accumulates the per-table/metric
 *                          bounded value reservoirs.
 * @param int   $chunk_size The number of rows to fetch per query page;
 *                          defaults to 5000.
 * @param array $totals     Reference, accumulates the per-table/metric
 *                          exact running count/sum totals.
 *
 * @return void
 */
function slowlog_collect_stats_by_matched_table(int $logid, array &$values, int $chunk_size = 5000, array &$totals = []): void {
	$last_id = 0;

	do {
		$rows = slowlog_import_rows('SELECT sldt.tableid, sldt.table_name AS scope_key,
			d.query_time, d.rows_sent, d.rows_examined, d.rows_affected, d.bytes_sent
			FROM plugin_slowlog_details_tables AS sldt
			INNER JOIN plugin_slowlog_details AS d ON d.logid = sldt.logid AND d.logentry = sldt.logentry
			WHERE sldt.logid = ?
			AND sldt.tableid > ?
			ORDER BY sldt.tableid
			LIMIT ' . (int) $chunk_size,
			[$logid, $last_id]);

		$batch_count = cacti_sizeof($rows);

		foreach ($rows as $row) {
			$last_id = $row['tableid'];

			foreach (SLOWLOG_STATS_METRICS as $metric) {
				slowlog_accumulate_stat_value($values, $totals, $row['scope_key'], $metric, (float) $row[$metric]);
			}
		}
	} while ($batch_count === $chunk_size);
}

/**
 * Streams the unmatched (no recognized table_name) half of the per-table
 * stats collection, paginated by plugin_slowlog_details.logentry, and
 * accumulates their metric values under the 'others' bucket key. Called
 * from slowlog_collect_stats_by_table().
 *
 * @param int   $logid      The plugin_slowlog.logid to collect stats
 *                          for.
 * @param array $values     Reference, accumulates the 'others'
 *                          bucket's metric bounded value reservoirs.
 * @param int   $chunk_size The number of rows to fetch per query page;
 *                          defaults to 5000.
 * @param array $totals     Reference, accumulates the 'others' bucket's
 *                          metric exact running count/sum totals.
 *
 * @return void
 */
function slowlog_collect_stats_by_unmatched_table(int $logid, array &$values, int $chunk_size = 5000, array &$totals = []): void {
	$last_logentry = 0;
	$bucket_key    = slowlog_others_bucket_key($logid);

	do {
		$rows = slowlog_import_rows('SELECT d.logentry,
				d.query_time, d.rows_sent, d.rows_examined, d.rows_affected, d.bytes_sent
			FROM plugin_slowlog_details AS d
			LEFT JOIN plugin_slowlog_details_tables AS sldt ON sldt.logid = d.logid AND sldt.logentry = d.logentry
			WHERE d.logid = ?
			AND d.logentry > ?
			AND sldt.table_name IS NULL
			ORDER BY d.logentry
			LIMIT ' . (int) $chunk_size,
			[$logid, $last_logentry]);

		$batch_count = cacti_sizeof($rows);

		foreach ($rows as $row) {
			$last_logentry = $row['logentry'];

			foreach (SLOWLOG_STATS_METRICS as $metric) {
				slowlog_accumulate_stat_value($values, $totals, $bucket_key, $metric, (float) $row[$metric]);
			}
		}
	} while ($batch_count === $chunk_size);
}

/**
 * Computes and caches box-whisker + total statistics (plugin_slowlog_stats) for every method
 * and table $logid's queries were classified under, across the 5 metrics in
 * SLOWLOG_STATS_METRICS. Run once at the end of import_post_process()/slowlog_reprocess() so
 * the By Method/By Table chart pages are a single indexed lookup instead of a live aggregate
 * query over plugin_slowlog_details.
 *
 * @param int $logid The plugin_slowlog.logid to compute and cache stats
 *                   for.
 *
 * @return void
 */
function slowlog_compute_stats(int $logid): void {
	$start = microtime(true);
	foreach (['method', 'table'] as $scope) {
		$values = [];
		$totals = [];
		if ($scope === 'method') {
			slowlog_collect_stats_by_method($logid, $values, 5000, $totals);
		} else {
			slowlog_collect_stats_by_table($logid, $values, 5000, $totals);
		}
		$rows = [];
		foreach ($values as $scope_key => $metrics) {
			foreach ($metrics as $metric => $samples) {
				$total = $totals[$scope_key][$metric];
				$summary = slowlog_summarize_values($samples, $total['count'], $total['sum'], $total['min'], $total['max']);
				$rows[] = array_merge($summary, ['logid' => $logid, 'scope' => $scope, 'scope_key' => $scope_key, 'metric' => $metric]);
				if (cacti_sizeof($rows) >= 200) {
					slowlog_bulk_insert_stats_rows($rows);
					$rows = [];
				}
			}
			unset($values[$scope_key], $totals[$scope_key]);
		}
		slowlog_bulk_insert_stats_rows($rows);
		unset($metrics, $samples, $values, $totals);
	}
	cacti_log(sprintf('STATS: Time:%0.2f, Stats Cache Complete for %s', microtime(true) - $start, $logid), false, 'SLOWLOG');
}
