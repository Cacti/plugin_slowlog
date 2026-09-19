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

it('normalizes a bare table name', function () {
	expect(parseTable('users'))->toBe('users');
});

it('strips a trailing semicolon or close paren', function () {
	expect(parseTable('users;'))->toBe('users');
	expect(parseTable('users)'))->toBe('users');
});

it('strips backticks', function () {
	expect(parseTable('`users`'))->toBe('users');
});

it('strips single quotes', function () {
	expect(parseTable("'users'"))->toBe('users');
});

it('prunes a schema qualifier', function () {
	expect(parseTable('cacti.users'))->toBe('users');
});

it('prunes a backtick-qualified schema', function () {
	expect(parseTable('`cacti`.`users`'))->toBe('users');
});

it('strips everything from an opening paren onward', function () {
	expect(parseTable('users(id)'))->toBe('users');
	expect(parseTable('users('))->toBe('users');
});
