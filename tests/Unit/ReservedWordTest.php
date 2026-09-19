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
 * is_reserved_word() reads its dictionary from the $reserved_words global,
 * which load_reserved_words() normally populates from the database. Seeding
 * that global directly keeps these cases pure-unit (no mock database needed).
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');

	$GLOBALS['reserved_words'] = array(
		'SELECT' => 'SELECT',
		'FROM'   => 'FROM',
		'WHERE'  => 'WHERE',
		'COUNT'  => 'COUNT',
	);
});

it('matches a reserved word case-insensitively', function () {
	expect(is_reserved_word('select'))->toBeTrue();
	expect(is_reserved_word('SELECT'))->toBeTrue();
});

it('rejects a word that is not reserved', function () {
	expect(is_reserved_word('users'))->toBeFalse();
});

it('resolves the left side of an assignment-style token', function () {
	expect(is_reserved_word('where=5'))->toBeTrue();
	expect(is_reserved_word('id=5'))->toBeFalse();
});

it('resolves the function name of a call-style token', function () {
	expect(is_reserved_word('count(*)'))->toBeTrue();
	expect(is_reserved_word('users(id)'))->toBeFalse();
});

it('trims surrounding parens and semicolons before matching', function () {
	expect(is_reserved_word('(from)'))->toBeTrue();
	expect(is_reserved_word('from;'))->toBeTrue();
});
