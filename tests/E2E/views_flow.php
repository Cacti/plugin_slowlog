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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+

/*
 * Driver for ViewsFlowTest. Imports logs through the upload page and the CLI, then requests the
 * list, details, query and chart pages of the installed Cacti over HTTP as a signed-in administrator,
 * with every sort, filter, page size and page number. Prints one JSON object on the last line of output.
 */

/*
 * Test driver: inert unless a test process starts it from the command line with
 * SLOWLOG_E2E=1. tests/ ships inside the plugin directory, so a web server must never run
 * this. Every path comes from the file's own location or from environment variables set by
 * the test process, never from a request.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_boot.php';

$admin   = slowlog_e2e_user('e2e_views_admin', [slowlog_e2e_realm()]);
$web     = slowlog_e2e_web_start($tmp);
$made    = [];
$markers = ['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Undefined '];

/**
 * Requests a page and records what a test needs to judge it.
 *
 * @return array{code: int, body: string, errors: int, rows: int, entries: array<int, string>}
 */
function e2e_page(string $name, string $path): array {
	global $web, $out, $markers;

	$page = slowlog_e2e_request($web, 'GET', $path);
	$errs = 0;

	foreach ($markers as $marker) {
		$errs += substr_count($page['body'], $marker);
	}

	preg_match_all('/action=query&logid=\d+&logentry=(\d+)/', $page['body'], $m);

	$page['errors']  = $errs;
	$page['entries'] = array_values(array_unique($m[1]));
	$page['rows']    = count($m[1]);
	$out['pages'][$name] = ['code' => $page['code'], 'errors' => $errs, 'rows' => $page['rows'], 'first' => $page['entries'][0] ?? null, 'empty' => strpos($page['body'], 'No Slowlog Records') !== false, 'size' => strlen($page['body'])];

	return $page;
}

/**
 * Posts the upload form and waits for the background import to finish.
 *
 * @param array<string, mixed> $fields The form fields besides the token.
 *
 * @return array<string, mixed> The record the upload created, or ['created' => false].
 */
function e2e_upload(string $name, array $fields): array {
	global $web, $tag, $made, $out;

	$form = slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php?action=edit');

	slowlog_e2e_request($web, 'POST', 'plugins/slowlog/slowlog.php?action=import', array_merge(['save_component_import' => '1', 'description' => $tag . $name, 'length' => '-1', 'table_mode' => 'all', 'table_names' => '', '__csrf_magic' => slowlog_e2e_token($form['body'])], $fields));

	$deadline = time() + 90;

	do {
		$row = db_fetch_row_prepared('SELECT logid, import_status, import_text_status, import_lines FROM plugin_slowlog WHERE description = ?', [$tag . $name]);

		if ($row && $row['import_status'] != 0) {
			break;
		}

		usleep(300000);
	} while (time() < $deadline);

	if (!$row) {
		return $out['uploads'][$name] = ['created' => false];
	}

	$made[] = (int) $row['logid'];

	return $out['uploads'][$name] = ['created' => true, 'status' => (int) $row['import_status'], 'text' => $row['import_text_status'], 'lines' => (int) $row['import_lines'], 'rows' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_details WHERE logid = ?', [$row['logid']])];
}

/**
 * Imports a generated log through the CLI and returns its logid.
 */
function e2e_cli_log(string $name, string $file): int {
	global $plugin, $tag, $made;

	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/import_log.php') . ' --logfile=' . escapeshellarg($file) . ' --description=' . escapeshellarg($tag . $name) . ' --table-mode=all 2>&1', $ignore, $status);

	$logid = (int) db_fetch_cell_prepared('SELECT logid FROM plugin_slowlog WHERE description = ?', [$tag . $name]);

	$made[] = $logid;

	return $logid;
}

try {
	slowlog_e2e_login($web, $admin['name'], $admin['password']);
	slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php');

	// 1. the upload page: a good log, a missing file, a reference list that is required, a bad file, a mode that is not
	// one, and a length that is not a number
	$fixture = $plugin . '/tests/E2E/fixtures/sample-slow.log';
	$small   = $tmp . '/small.log';

	file_put_contents($small, implode("\n", ['# Time: 240115  1:02:03', '# User@Host: w[w] @ h [10.0.0.5]', '# Query_time: 1.0  Lock_time: 0.0  Rows_sent: 1  Rows_examined: 1', 'SET timestamp=1705280523;', 'SELECT 1 FROM host;', '# Time: 240115  1:02:09', '']));
	file_put_contents($tmp . '/notalog.log', "this is not a slow log\n");

	e2e_upload('up-good', ['import_file' => new CURLFile($fixture, 'text/plain', 'sample-slow.log')]);
	e2e_upload('up-nofile', []);
	e2e_upload('up-reference', ['table_mode' => 'reference', 'import_file' => new CURLFile($small, 'text/plain', 'small.log')]);
	e2e_upload('up-bad', ['import_file' => new CURLFile($tmp . '/notalog.log', 'text/plain', 'notalog.log')]);
	e2e_upload('up-mode', ['table_mode' => 'bogus', 'import_file' => new CURLFile($small, 'text/plain', 'small.log')]);
	e2e_upload('up-length', ['length' => 'abc', 'import_file' => new CURLFile($small, 'text/plain', 'small.log')]);
	e2e_upload('up-names', ['table_mode' => 'reference', 'table_names' => "host\ngraph_local", 'import_file' => new CURLFile($small, 'text/plain', 'small.log')]);

	// 2. a table name that would break out of an attribute, and a log of 1,200 entries
	file_put_contents($tmp . '/hostile.log', implode("\n", ['# Time: 240115  1:02:03', '# User@Host: x[x] @ h [10.0.0.6]', '# Query_time: 1.0  Lock_time: 0.0  Rows_sent: 1  Rows_examined: 1', 'SET timestamp=1705280523;', 'SELECT 1 FROM `a""><script src=//2130706433/x></script>`;', '# Time: 240115  1:02:09', '']));

	$hostile = e2e_cli_log('hostile', $tmp . '/hostile.log');

	slowlog_e2e_synthetic_log($tmp . '/big.log', 1200, ['SELECT * FROM host WHERE id = {i}', 'UPDATE graph_local SET x = {i} WHERE id = 1', 'SELECT a.id FROM settings AS a JOIN host ON host.id = a.id']);

	$big  = e2e_cli_log('big', $tmp . '/big.log');
	$base = 'plugins/slowlog/slowlog.php?action=details&logid=' . $big;

	// 3. the details list: sort every column both ways
	foreach (['table_name', 'method', 'date', 'user', 'host', 'query_time', 'lock_time', 'rows_sent', 'rows_examined', 'rows_affected', 'bytes_sent'] as $column) {
		foreach (['ASC', 'DESC'] as $direction) {
			e2e_page('sort-' . $column . '-' . $direction, $base . '&reset=true&rows=1000&sort_column=' . $column . '&sort_direction=' . $direction);
		}
	}

	$out['sort_max'] = [
		'asc'   => e2e_page('sort-check-asc', $base . '&reset=true&rows=10&sort_column=rows_examined&sort_direction=ASC')['entries'],
		'desc'  => e2e_page('sort-check-desc', $base . '&reset=true&rows=10&sort_column=rows_examined&sort_direction=DESC')['entries'],
		'order' => array_column(db_fetch_assoc_prepared('SELECT d.logentry FROM plugin_slowlog_details d WHERE d.logid = ? ORDER BY d.rows_examined DESC LIMIT 4', [$big]), 'logentry'),
		'low'   => array_column(db_fetch_assoc_prepared('SELECT d.logentry FROM plugin_slowlog_details d WHERE d.logid = ? ORDER BY d.rows_examined ASC LIMIT 4', [$big]), 'logentry'),
	];

	// 4. the details list: page size and page number, including values the request filter lets through
	foreach (['10' => 10, '0' => 0, '-5' => -5, '100000000' => 100000000, '-1' => -1] as $label => $rows) {
		e2e_page('rows-' . $label, $base . '&reset=true&rows=' . $rows);
	}

	foreach ([1, 2, 0, -3, 999999] as $page) {
		e2e_page('page-' . $page, $base . '&reset=true&rows=10&page=' . $page);
	}

	// 5. the details list: every filter, with the expected number of rows taken from the database
	$count = function (string $user = '', string $host = '', string $table = '', string $method = '', string $text = '') use ($big): int {
		return (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_slowlog_details sld
			LEFT JOIN plugin_slowlog_details_tables sldt ON sld.logid = sldt.logid AND sld.logentry = sldt.logentry
			LEFT JOIN plugin_slowlog_details_methods sldm ON sld.logid = sldm.logid AND sld.logentry = sldm.logentry
			WHERE sld.logid = ?
			AND (? = '' OR sld.user = ?)
			AND (? = '' OR sld.host = ?)
			AND (? = '' OR sldt.table_name = ?)
			AND (? = '' OR sldm.method = ?)
			AND (? = '' OR sld.host LIKE ? OR sld.user LIKE ? OR sld.query LIKE ? OR sld.ip_address LIKE ?)",
			[$big, $user, $user, $host, $host, $table, $table, $method, $method, $text, '%' . $text . '%', '%' . $text . '%', '%' . $text . '%', '%' . $text . '%']);
	};

	$out['expected'] = [
		'user'    => $count('poller'),
		'host'    => $count('', 'host3'),
		'table'   => $count('', '', 'graph_local'),
		'mmethod' => $count('', '', '', 'UPDATES'),
		'method'  => $count('', '', '', 'SELECTS'),
		'filter'  => $count('', '', '', '', 'graph_local'),
	];

	$all = $base . '&reset=true&rows=1000';

	e2e_page('f-user', $all . '&user=poller');
	e2e_page('f-host', $all . '&host=host3');
	e2e_page('f-table', $all . '&table=graph_local');
	e2e_page('f-table-others', $all . '&table=-2');
	e2e_page('f-table-aggregate', $all . '&table=-3');
	e2e_page('f-mmethod', $all . '&mmethod=UPDATES');
	e2e_page('f-mmethod-aggregate', $all . '&mmethod=-2');
	e2e_page('f-method-name', $all . '&method_name=SELECTS');
	e2e_page('f-filter', $all . '&filter=' . rawurlencode('graph_local'));
	e2e_page('f-filter-special', $all . '&filter=' . rawurlencode('a&b#c+d%'));
	e2e_page('f-dates-in', $all . '&date1=' . rawurlencode('2024-01-01 00:00') . '&date2=' . rawurlencode('2025-01-01 00:00'));
	e2e_page('f-dates-out', $all . '&date1=' . rawurlencode('2030-01-01 00:00') . '&date2=' . rawurlencode('2031-01-01 00:00'));
	e2e_page('f-combined', $all . '&user=poller&table=host&mmethod=SELECTS&sort_column=date&sort_direction=DESC');
	e2e_page('f-any-log', 'plugins/slowlog/slowlog.php?action=details&logid=-1&reset=true&rows=10');
	e2e_page('f-clear', $base . '&reset=true&clear=true');

	// the filter script encodes what it appends to the request
	$js = slowlog_e2e_request($web, 'GET', $base . '&reset=true')['body'];

	$out['js_encoded'] = [];

	foreach (['filter', 'mmethod', 'table', 'user', 'host', 'date1', 'date2'] as $field) {
		$out['js_encoded'][$field] = strpos($js, "encodeURIComponent($('#" . ($field === 'user' ? 'myuser' : $field) . "').val())") !== false;
	}

	// 6. a table name from the log is escaped in the filter
	$page = e2e_page('hostile', 'plugins/slowlog/slowlog.php?action=details&logid=' . $hostile . '&reset=true');

	$out['hostile'] = [
		'raw_tag'     => strpos($page['body'], '<script src=//2130706433') !== false,
		'raw_quote'   => strpos($page['body'], 'value="a""') !== false,
		'escaped'     => strpos($page['body'], 'value="a&quot;&quot;&gt;&lt;script src=//2130706433/x&gt;&lt;/script&gt;"') !== false,
	];

	// 7. the query view
	$entry = (int) db_fetch_cell_prepared('SELECT MIN(logentry) FROM plugin_slowlog_details WHERE logid = ?', [$big]);

	$q = e2e_page('query', 'plugins/slowlog/slowlog.php?action=query&logid=' . $big . '&logentry=' . $entry);

	$out['query_shows_text'] = strpos($q['body'], 'SELECT * FROM host WHERE id = 0') !== false;

	e2e_page('query-missing', 'plugins/slowlog/slowlog.php?action=query&logid=' . $big . '&logentry=99999999');
	e2e_page('query-bad', 'plugins/slowlog/slowlog.php?action=query&logid=' . $big . '&logentry=abc');

	// 8. the chart pages
	foreach (['methods', 'tables'] as $kind) {
		$chart = 'plugins/slowlog/slowlog.php?action=' . $kind . '&reset=true&logid=' . $big;

		$p = e2e_page($kind, $chart);

		$out['charts'][$kind] = ['render' => substr_count($p['body'], 'renderChart('), 'box' => substr_count($p['body'], 'renderBoxChart(')];

		e2e_page($kind . '-max', $chart . '&include_max=on&chart_top=15');
		e2e_page($kind . '-scope', $chart . '&chart_scope=' . rawurlencode($kind === 'methods' ? 'SELECTS,UPDATES' : 'host,settings'));
		e2e_page($kind . '-scope-bad', $chart . '&chart_scope=' . rawurlencode('nonsense,host'));
		e2e_page($kind . '-top', $chart . '&chart_top=2');
		e2e_page($kind . '-top-bad', $chart . '&chart_top=777');
		e2e_page('view' . $kind, 'plugins/slowlog/slowlog.php?action=view' . $kind . '&logid=' . $big);
	}

	e2e_page('charts-unknown-log', 'plugins/slowlog/slowlog.php?action=methods&reset=true&logid=999999');

	// 9. the list and the import form
	e2e_page('list', 'plugins/slowlog/slowlog.php?reset=true');
	e2e_page('list-filter', 'plugins/slowlog/slowlog.php?action=select&filter=' . rawurlencode($tag));

	foreach (['description', 'import_status', 'logid', 'import_date', 'import_lines', 'start_time'] as $column) {
		e2e_page('list-sort-' . $column, 'plugins/slowlog/slowlog.php?action=select&sort_column=' . $column . '&sort_direction=DESC');
	}

	$form = e2e_page('form', 'plugins/slowlog/slowlog.php?action=edit');

	$out['form_has_upload'] = strpos($form['body'], 'import_file') !== false;

	$out['chart_filter_encoded'] = strpos(slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php?action=methods&reset=true&logid=' . $big)['body'], "encodeURIComponent(($('#chart_scope').val() || []).join(','))") !== false;
	$out['list_filter_encoded']  = strpos(slowlog_e2e_request($web, 'GET', 'plugins/slowlog/slowlog.php?reset=true')['body'], "encodeURIComponent($('#filter').val())") !== false;
	$out['totals']          = ['big' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_slowlog_details WHERE logid = ?', [$big]), 'logid' => $big];
} finally {
	slowlog_e2e_web_stop($web);

	foreach ($made as $id) {
		api_slowlog_remove($id);
	}

	slowlog_e2e_drop_users([$admin]);
	slowlog_e2e_restore_log_setting($saved_log);
}

print json_encode($out) . PHP_EOL;
