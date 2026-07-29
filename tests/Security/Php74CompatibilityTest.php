<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Verify plugin source files do not use PHP 8.0+ syntax.
 * Cacti 1.2.x plugins must remain compatible with PHP 7.4.
 */

$files = array(
	'import_log.php',
	'setup.php',
	'slowlog.php',
	'slowlog_functions.php',
);

$readDeclaredFile = function (string $relativeFile): string {
	$path = realpath(__DIR__ . '/../../' . $relativeFile);

	if ($path === false) {
		throw new RuntimeException("Failed to resolve declared file: {$relativeFile}");
	}

	$contents = file_get_contents($path);

	if ($contents === false) {
		throw new RuntimeException("Failed to read declared file: {$relativeFile}");
	}

	return $contents;
};

it('does not use str_contains (PHP 8.0)', function () use ($files, $readDeclaredFile) {
	foreach ($files as $relativeFile) {
		$contents = $readDeclaredFile($relativeFile);

		expect(preg_match('/\bstr_contains\s*\(/', $contents))->toBe(0,
			"{$relativeFile} uses str_contains() which requires PHP 8.0"
		);
	}
});

it('does not use str_starts_with (PHP 8.0)', function () use ($files, $readDeclaredFile) {
	foreach ($files as $relativeFile) {
		$contents = $readDeclaredFile($relativeFile);

		expect(preg_match('/\bstr_starts_with\s*\(/', $contents))->toBe(0,
			"{$relativeFile} uses str_starts_with() which requires PHP 8.0"
		);
	}
});

it('does not use str_ends_with (PHP 8.0)', function () use ($files, $readDeclaredFile) {
	foreach ($files as $relativeFile) {
		$contents = $readDeclaredFile($relativeFile);

		expect(preg_match('/\bstr_ends_with\s*\(/', $contents))->toBe(0,
			"{$relativeFile} uses str_ends_with() which requires PHP 8.0"
		);
	}
});

it('does not use nullsafe operator (PHP 8.0)', function () use ($files, $readDeclaredFile) {
	foreach ($files as $relativeFile) {
		$contents = $readDeclaredFile($relativeFile);

		expect(preg_match('/\?->/', $contents))->toBe(0,
			"{$relativeFile} uses nullsafe operator which requires PHP 8.0"
		);
	}
});
