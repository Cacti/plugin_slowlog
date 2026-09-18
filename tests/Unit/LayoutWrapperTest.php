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
*/

uses(TestCase::class);

if (!function_exists('general_header')) {
	function general_header() {
		$GLOBALS['slowlog_events'][] = 'header';
	}
}

if (!function_exists('bottom_footer')) {
	function bottom_footer() {
		$GLOBALS['slowlog_events'][] = 'footer';
	}
}

if (!function_exists('slowlog_test_renderer')) {
	function slowlog_test_renderer() {
		$GLOBALS['slowlog_events'][] = 'named-render';
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
	$GLOBALS['slowlog_events'] = array();
});

it('runs header, callback, then footer in order', function () {
	$executions = 0;

	slowlog_render_with_layout(function () use (&$executions) {
		$GLOBALS['slowlog_events'][] = 'render';
		$executions++;
	});

	expect($GLOBALS['slowlog_events'])->toBe(array('header', 'render', 'footer'));
	expect($executions)->toBe(1);
});

it('supports named function callbacks', function () {
	slowlog_render_with_layout('slowlog_test_renderer');

	expect($GLOBALS['slowlog_events'])->toBe(array('header', 'named-render', 'footer'));
});
