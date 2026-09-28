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

/*
 * Regression coverage for two fixes: form_actions() previously indexed its bulk-action
 * menu with, and echoed back into a hidden field, a completely unvalidated and unescaped
 * raw $_POST['drp_action'] value; and slowlog_view_query() printed an imported (untrusted)
 * slow-query string straight into a <pre> block with no escaping. header()/exit make the
 * invalid-action redirect path itself impractical to exercise behaviorally in this
 * source-only Pest harness (no process isolation), so - consistent with this repo's other
 * Security/ tests (RedirectSafetyTest, OutputEscapingTest) - these assert on the source
 * shape of the fix rather than executing the page functions.
 */

describe('bulk-action validation and query output escaping in slowlog.php', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	it('validates drp_action against the known action set before using it', function () use ($source) {
		expect($source)->toContain("if (!array_key_exists(\$drp_action, \$actions)) {");
	});

	it('no longer indexes the actions menu with raw, unvalidated $_POST data', function () use ($source) {
		expect($source)->not->toContain('$actions[$_POST[\'drp_action\']]');
	});

	it('escapes the drp_action value before writing it back into the hidden field', function () use ($source) {
		expect($source)->toContain("html_escape(\$drp_action)");
		expect($source)->not->toContain('. $_POST[\'drp_action\'] . "\'>"');
	});

	it('escapes the imported query text before rendering it in the query-detail page', function () use ($source) {
		expect($source)->toContain('html_escape($oquery)');
	});
});
