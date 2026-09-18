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

describe('PHP 7.4 compatibility in slowlog', function () {
	$files = array(
		'setup.php',
		'slowlog.php',
		'slowlog_functions.php',
	);

	it('does not use str_contains (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			expect(preg_match('/\bstr_contains\s*\(/', $c))->toBe(0, "{$f} uses str_contains");
		}
	});

	it('does not use str_starts_with (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			expect(preg_match('/\bstr_starts_with\s*\(/', $c))->toBe(0, "{$f} uses str_starts_with");
		}
	});

	it('does not use str_ends_with (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			expect(preg_match('/\bstr_ends_with\s*\(/', $c))->toBe(0, "{$f} uses str_ends_with");
		}
	});

	it('does not use nullsafe operator (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			expect(preg_match('/\?->/', $c))->toBe(0, "{$f} uses nullsafe operator");
		}
	});

	it('does not use match expression (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			// Avoid false positive on preg_match etc
			$c2 = preg_replace('/preg_match|preg_match_all|fnmatch/', '', $c);
			expect(preg_match('/\bmatch\s*\(/', $c2))->toBe(0, "{$f} uses match expression");
		}
	});

	it('does not use union type declarations (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			// Match function params/return with union types like string|false
			$hits = preg_match_all('/function\s+\w+\s*\([^)]*\w+\s*\|\s*\w+/', $c);
			expect($hits)->toBe(0, "{$f} uses union types in function signatures");
		}
	});

	it('does not use constructor property promotion (PHP 8.0)', function () use ($files) {
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;
			expect(preg_match('/function\s+__construct\s*\([^)]*\b(public|private|protected|readonly)\s/', $c))->toBe(0,
				"{$f} uses constructor promotion"
			);
		}
	});

	it('does not mix array() and short [] array syntax', function () use ($files) {
		// Flag files that mix both styles
		foreach ($files as $f) {
			$p = realpath(__DIR__ . '/../../' . $f);
			if ($p === false) continue;
			$c = file_get_contents($p);
			if ($c === false) continue;

			$hasArrayFunc = preg_match('/\barray\s*\(/', $c);
			$hasShortArray = preg_match('/=\s*\[/', $c);

			expect($hasArrayFunc && $hasShortArray)->toBeFalse(
				"{$f} mixes array() and short [] array syntax"
			);
		}
	});
});
