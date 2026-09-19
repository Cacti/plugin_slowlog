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

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
});

it('keeps a bare hostname unchanged', function () {
	expect(slowlog_strip_domain('localhost'))->toBe('localhost');
});

it('strips the domain suffix', function () {
	expect(slowlog_strip_domain('db1.example.com'))->toBe('db1');
});

it('strips a -new suffix used by internal host aliasing', function () {
	expect(slowlog_strip_domain('dbhost-new'))->toBe('dbhost');
});

it('strips both the domain suffix and the -new marker', function () {
	expect(slowlog_strip_domain('dbhost-new.example.com'))->toBe('dbhost');
});
