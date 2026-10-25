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


uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
	$this->savedRequest = $_REQUEST;
});

afterEach(function () {
	$_REQUEST = $this->savedRequest;
});

it('returns to the summary without retaining an import selection', function () {
	$_REQUEST = ['action' => 'select', 'logid' => 42];
	ob_start();
	slowlog_tabs();
	$html = ob_get_clean();
	expect($_REQUEST)->not->toHaveKey('logid');
	expect(substr_count($html, '<a '))->toBe(1);
	expect($html)->toContain("class='selected pic'", '?action=select', '>Summary</a>');
	expect($html)->not->toContain('logid=');
});

it('shows the import tabs and selects the active view', function () {
	$_REQUEST = ['action' => 'details', 'logid' => 42];
	ob_start();
	slowlog_tabs();
	$html = ob_get_clean();
	expect(substr_count($html, '<a '))->toBe(4);
	expect(substr_count($html, "class='selected pic'"))->toBe(1);
	expect($html)->toContain("class='selected pic' href='/cacti/plugins/slowlog/slowlog.php?action=details&amp;logid=42'");
	expect($html)->not->toContain('>Query</a>');
});

it('adds the query tab and retains the entry while navigating an import', function () {
	$_REQUEST = ['action' => 'query', 'logid' => 42, 'logentry' => 7];
	ob_start();
	slowlog_tabs();
	$html = ob_get_clean();
	expect(substr_count($html, '<a '))->toBe(5);
	expect($html)->toContain("class='selected pic' href='/cacti/plugins/slowlog/slowlog.php?action=query&amp;logid=42&amp;logentry=7'>Query</a>");
});

it('escapes values included in tab link attributes', function () {
	$_REQUEST = ['action' => 'details', 'logid' => "42' onclick='bad", 'logentry' => '7&extra="value"'];
	ob_start();
	slowlog_tabs();
	$html = ob_get_clean();
	expect($html)->not->toContain("logid=42' onclick='bad", 'extra="value"');
	expect($html)->toContain(html_escape("&logid=42' onclick='bad&logentry=7&extra=\"value\""));
});

it('keeps the legacy failure helper compatible with existing callers', function () {
	slowlog_import_failed(42);
	$call = $GLOBALS['__test_db_calls'][0];
	expect($call['fn'])->toBe('db_execute_prepared');
	expect($call['sql'])->toContain('import_status = 3', 'import_text_status = ?');
	expect($call['params'])->toBe(['Import Failed - Row Insert Error', 42]);
});
