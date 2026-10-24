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
 * slowlog.php used to raise PHP's execution time and memory limits to unlimited for every request. It now
 * does so only for the import form, an upload and a delete, and leaves the limits of the web server alone
 * for the pages that only read.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('raises the limits only for the requests that need them', function ($action, $raised, $time, $memory) {
	ini_set('max_execution_time', '30');
	ini_set('memory_limit', '256M');

	expect(slowlog_apply_request_limits($action))->toBe($raised);
	expect([ini_get('max_execution_time'), ini_get('memory_limit')])->toBe(array($time, $memory));

	ini_set('memory_limit', '-1');
})->with(array(
	'import form'    => array('edit', true, '0', '-1'),
	'upload'         => array('save', true, '0', '-1'),
	'import'         => array('import', true, '0', '-1'),
	'delete'         => array('actions', true, '0', '256M'),
	'list'           => array('select', false, '30', '256M'),
	'details'        => array('details', false, '30', '256M'),
	'charts'         => array('methods', false, '30', '256M'),
	'query'          => array('query', false, '30', '256M'),
));

it('no longer raises the limits for every request', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->not->toContain("ini_set('memory_limit', '-1');");
	expect($source)->toContain('slowlog_apply_request_limits(get_request_var(\'action\'));');
});
