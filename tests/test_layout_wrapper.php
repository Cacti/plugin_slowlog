<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Standalone regression tests for slowlog layout wrapper                  |
 |                                                                         |
 | Run: php tests/test_layout_wrapper.php                                  |
 +-------------------------------------------------------------------------+
 */

$slowlog_events = [];
$pass = 0;
$fail = 0;

if (!function_exists('general_header')) {
	function general_header() {
		global $slowlog_events;
		$slowlog_events[] = 'header';
	}
}

if (!function_exists('bottom_footer')) {
	function bottom_footer() {
		global $slowlog_events;
		$slowlog_events[] = 'footer';
	}
}

require_once __DIR__ . '/../slowlog_functions.php';

function assert_equal($label, $expected, $actual) {
	global $pass, $fail;

	if ($expected === $actual) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		echo '      expected: ' . var_export($expected, true) . "\n";
		echo '      actual:   ' . var_export($actual, true) . "\n";
		$fail++;
	}
}

/* ------------------------------------------------------------------ */
/* Wrapper order and callback execution                                */
/* ------------------------------------------------------------------ */

$slowlog_events = [];
$executions = 0;

slowlog_render_with_layout(function () use (&$executions) {
	global $slowlog_events;
	$slowlog_events[] = 'render';
	$executions++;
});

assert_equal('layout wrapper call order', ['header', 'render', 'footer'], $slowlog_events);
assert_equal('layout callback executes once', 1, $executions);

/* ------------------------------------------------------------------ */
/* Wrapper supports named callbacks                                    */
/* ------------------------------------------------------------------ */

function slowlog_test_renderer() {
	global $slowlog_events;
	$slowlog_events[] = 'named-render';
}

$slowlog_events = [];
slowlog_render_with_layout('slowlog_test_renderer');

assert_equal('named callback call order', ['header', 'named-render', 'footer'], $slowlog_events);

echo "\n";
echo "Results: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
