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
 * The list, details, query and chart pages and the upload page against an installed Cacti, requested over
 * HTTP with every sort, filter, page size and page number. Needs SLOWLOG_E2E=1 and an installed Cacti with the
 * plugin enabled; the CI integration job provides both. Without them these tests are skipped.
 */

require_once __DIR__ . '/e2e_common.php';

/**
 * @return array<string, mixed> What the driver recorded for one request.
 */
function slowlog_e2e_page(string $name): array {
	return slowlog_e2e_observe('views_flow.php')['pages'][$name];
}

describe('slow log pages against a real Cacti', function () {
	beforeEach(function () {
		if (($reason = slowlog_e2e_skip_reason()) !== null) {
			$this->markTestSkipped($reason);
		}
	});

	it('runs the driver to completion', function () {
		expect(slowlog_e2e_observe('views_flow.php')['driver_failed'] ?? '')->toBe('');
	});

	it('answers every request with a page and no PHP error', function () {
		foreach (slowlog_e2e_observe('views_flow.php')['pages'] as $name => $page) {
			expect([$name, $page['code'], $page['errors']])->toBe([$name, 200, 0]);
		}
	});

	it('imports an uploaded log in the background and records the outcome', function ($name, $created, $status, $rows) {
		$o = slowlog_e2e_observe('views_flow.php')['uploads'][$name];

		expect($o['created'])->toBe($created);

		if ($created) {
			expect([$o['status'], $o['rows']])->toBe([$status, $rows]);
		}
	})->with([
		'good log'                     => ['up-good', true, 2, 3],
		'no file selected'             => ['up-nofile', false, 0, 0],
		'reference mode needs names'   => ['up-reference', false, 0, 0],
		'file that is not a log'       => ['up-bad', true, 3, 0],
		'unknown table mode'           => ['up-mode', true, 2, 1],
		'length that is not a number'  => ['up-length', true, 2, 1],
		'reference mode with names'    => ['up-names', true, 2, 1],
	]);

	it('says a file that is not a log has a bad format', function () {
		expect(slowlog_e2e_observe('views_flow.php')['uploads']['up-bad']['text'])->toStartWith('Bad File Format');
	});

	it('sorts the details list by every column in both directions', function ($column) {
		foreach (['ASC', 'DESC'] as $direction) {
			expect(slowlog_e2e_page('sort-' . $column . '-' . $direction)['rows'])->toBe(1000);
		}
	})->with(['table_name', 'method', 'date', 'user', 'host', 'query_time', 'lock_time', 'rows_sent', 'rows_examined', 'rows_affected', 'bytes_sent']);

	it('orders the details list as the database does', function () {
		$o = slowlog_e2e_observe('views_flow.php')['sort_max'];

		expect(array_map('intval', array_slice($o['desc'], 0, 4)))->toBe(array_map('intval', $o['order']));
		expect(array_map('intval', array_slice($o['asc'], 0, 4)))->toBe(array_map('intval', $o['low']));
	});

	it('uses the requested page size, and the default for sizes that are not usable', function ($name, $rows) {
		expect(slowlog_e2e_page($name)['rows'])->toBe($rows);
	})->with([
		'ten'        => ['rows-10', 10],
		'zero'       => ['rows-0', 30],
		'negative'   => ['rows--5', 30],
		'default'    => ['rows--1', 30],
		'huge'       => ['rows-100000000', 1000],
	]);

	it('pages through the list, clamping page numbers below 1 and showing no rows past the end', function () {
		$first = slowlog_e2e_page('page-1')['first'];

		expect(slowlog_e2e_page('page-2')['first'])->not->toBe($first);
		expect(slowlog_e2e_page('page-0')['first'])->toBe($first);
		expect(slowlog_e2e_page('page--3')['first'])->toBe($first);
		expect(slowlog_e2e_page('page-999999')['rows'])->toBe(0);
		expect(slowlog_e2e_page('page-999999')['empty'])->toBeTrue();
	});

	it('filters by user, host, table and method as the database does', function ($name, $key) {
		expect(slowlog_e2e_page($name)['rows'])->toBe(slowlog_e2e_observe('views_flow.php')['expected'][$key]);
	})->with([
		'user'    => ['f-user', 'user'],
		'host'    => ['f-host', 'host'],
		'table'   => ['f-table', 'table'],
		'mmethod' => ['f-mmethod', 'mmethod'],
		'text'    => ['f-filter', 'filter'],
	]);

	it('aggregates by table and by method, and filters by method name', function ($name) {
		expect(slowlog_e2e_page($name)['rows'])->toBeGreaterThan(0);
	})->with(['f-table-aggregate', 'f-mmethod-aggregate', 'f-method-name', 'f-combined', 'f-dates-in', 'f-clear']);

	it('shows no rows for entries outside the log, the date range or the search', function ($name) {
		expect(slowlog_e2e_page($name)['rows'])->toBe(0);
		expect(slowlog_e2e_page($name)['empty'])->toBeTrue();
	})->with(['f-table-others', 'f-dates-out', 'f-filter-special', 'f-any-log']);

	it('escapes a table name from the log in the table filter', function () {
		$o = slowlog_e2e_observe('views_flow.php')['hostile'];

		expect($o['escaped'])->toBeTrue();
		expect($o['raw_tag'])->toBeFalse();
		expect($o['raw_quote'])->toBeFalse();
	});

	it('shows the text of one query, and a page without rows for a missing or invalid entry', function () {
		expect(slowlog_e2e_observe('views_flow.php')['query_shows_text'])->toBeTrue();
		expect(slowlog_e2e_page('query-missing')['code'])->toBe(200);
		expect(slowlog_e2e_page('query-bad')['code'])->toBe(200);
	});

	it('draws the method and table charts with every option', function ($kind) {
		$o = slowlog_e2e_observe('views_flow.php')['charts'][$kind];

		expect($o['render'])->toBeGreaterThan(1);
		expect($o['box'])->toBeGreaterThan(1);

		foreach (['', '-max', '-scope', '-scope-bad', '-top', '-top-bad'] as $suffix) {
			expect(slowlog_e2e_page($kind . $suffix)['size'])->toBeGreaterThan(20000);
		}

		expect(slowlog_e2e_page('view' . $kind)['size'])->toBeGreaterThan(5000);
	})->with(['methods', 'tables']);

	it('lists the imported logs, sorted and filtered', function () {
		foreach (['list', 'list-filter', 'list-sort-description', 'list-sort-import_status', 'list-sort-logid', 'list-sort-import_date', 'list-sort-import_lines', 'list-sort-start_time'] as $name) {
			expect(slowlog_e2e_page($name)['size'])->toBeGreaterThan(20000);
		}
	});

	it('encodes the values the filter scripts add to the request', function () {
		$o = slowlog_e2e_observe('views_flow.php');

		expect($o['js_encoded'])->each->toBeTrue();
		expect($o['chart_filter_encoded'])->toBeTrue();
		expect($o['list_filter_encoded'])->toBeTrue();
	});

	it('offers the file upload form', function () {
		expect(slowlog_e2e_observe('views_flow.php')['form_has_upload'])->toBeTrue();
	});
});
