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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * slowlog_parse_ini_bytes()/slowlog_upload_environment_status() back the "Upload Limits" panel
 * on the import form - both are pure (no database), so exercised directly against known
 * php.ini values via ini_set().
 */

uses(TestCase::class);

if (!function_exists('slowlog_test_warnings_matching')) {
	function slowlog_test_warnings_matching(array $warnings, string $needle): array {
		return array_values(array_filter($warnings, function ($warning) use ($needle) {
			return strpos($warning, $needle) !== false;
		}));
	}
}

beforeEach(function () {
	TestCase::loadPluginSource('slowlog_functions.php');
});

afterEach(function () {
	// Restore to the unlimited defaults this plugin sets at the top of slowlog.php, so these
	// overrides don't leak into other test files that also read max_execution_time/memory_limit.
	ini_set('max_execution_time', '0');
	ini_set('memory_limit', '-1');
});

it('parses megabyte/gigabyte/kilobyte php.ini shorthand into bytes', function () {
	expect(slowlog_parse_ini_bytes('8M'))->toBe(8 * 1024 * 1024);
	expect(slowlog_parse_ini_bytes('2G'))->toBe(2 * 1024 * 1024 * 1024);
	expect(slowlog_parse_ini_bytes('512K'))->toBe(512 * 1024);
});

it('treats -1 as unlimited (null) and a bare number as raw bytes', function () {
	expect(slowlog_parse_ini_bytes('-1'))->toBeNull();
	expect(slowlog_parse_ini_bytes('100'))->toBe(100);
	expect(slowlog_parse_ini_bytes('0'))->toBe(0);
});

it('reports no execution-time/memory warnings when both are unlimited', function () {
	ini_set('max_execution_time', '0');
	ini_set('memory_limit', '-1');

	$status = slowlog_upload_environment_status();

	expect($status['max_execution_time'])->toBe('0');
	expect($status['memory_limit'])->toBe('-1');
	expect(slowlog_test_warnings_matching($status['warnings'], 'max_execution_time'))->toBeEmpty();
	expect(slowlog_test_warnings_matching($status['warnings'], 'memory_limit'))->toBeEmpty();
});

it('warns when max_execution_time is not unlimited', function () {
	ini_set('max_execution_time', '30');
	ini_set('memory_limit', '-1');

	$status = slowlog_upload_environment_status();

	expect(slowlog_test_warnings_matching($status['warnings'], 'max_execution_time'))->not->toBeEmpty();
});

it('warns when memory_limit is not unlimited', function () {
	ini_set('max_execution_time', '0');
	ini_set('memory_limit', '128M');

	$status = slowlog_upload_environment_status();

	expect(slowlog_test_warnings_matching($status['warnings'], 'memory_limit'))->not->toBeEmpty();
});

it('maps every recognized $_FILES upload error code to a translated message, sharing the file-too-large message between the two size-limit codes', function () {
	// UPLOAD_ERR_INI_SIZE (php.ini's upload_max_filesize) and UPLOAD_ERR_FORM_SIZE (a form's
	// MAX_FILE_SIZE) both mean "file too large" from the user's perspective, so they
	// intentionally share one message - every other code gets its own distinct message.
	$sizeCodes  = array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE);
	$otherCodes = array(UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION);

	$sizeMessages  = array_map('slowlog_upload_error_message', $sizeCodes);
	$otherMessages = array_map('slowlog_upload_error_message', $otherCodes);

	expect(array_unique($sizeMessages))->toHaveCount(1);
	expect(array_unique($otherMessages))->toHaveCount(count($otherMessages));
	expect($otherMessages)->not->toContain($sizeMessages[0]);

	foreach (array_merge($sizeMessages, $otherMessages) as $message) {
		expect($message)->toContain('ERROR:');
	}
});

it('falls back to a generic message for an unrecognized upload error', function () {
	// The test bootstrap's __() stub returns the raw, untranslated text verbatim (no sprintf
	// substitution - see the stub's own comment), so assert against the literal placeholder
	// rather than a substituted error code.
	expect(slowlog_upload_error_message(999))->toContain('error code %d');
});
