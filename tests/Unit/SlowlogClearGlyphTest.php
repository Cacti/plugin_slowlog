<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for slowlog_details_filter_clear_glyph() in
 * includes/functions.php. The clear-filter glyph uses a CSP-safe
 * slowlogClearFilter class and data-url attribute (bound via a delegated
 * handler) instead of an inline onclick.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('renders the clear-filter glyph with CSP-safe markup and no inline handler', function () {
	// The unit harness' get_request_var() returns '', which differs from the
	// 'table' filter default ('-1'), so the filter counts as active and the
	// glyph (the changed line) is rendered.
	$output = slowlog_details_filter_clear_glyph('table');

	expect($output)->toContain('slowlogClearFilter');
	expect($output)->toContain('data-url=');
	expect($output)->not->toContain('onclick');
});
