<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Verify setup.php defines required plugin hooks and info function.
 */

$setupPath = realpath(__DIR__ . '/../../setup.php');

if ($setupPath === false) {
	throw new RuntimeException('Failed to resolve setup.php');
}

$source = file_get_contents($setupPath);

if ($source === false) {
	throw new RuntimeException('Failed to read setup.php');
}

$infoPath = realpath(__DIR__ . '/../../INFO');

if ($infoPath === false) {
	throw new RuntimeException('Failed to resolve INFO file');
}

$info = file_get_contents($infoPath);

if ($info === false) {
	throw new RuntimeException('Failed to read INFO file');
}

it('defines plugin_slowlog_install function', function () use ($source) {
	expect($source)->toContain('function plugin_slowlog_install');
});

it('defines plugin_slowlog_version function', function () use ($source) {
	expect($source)->toContain('function plugin_slowlog_version');
});

it('defines plugin_slowlog_uninstall function', function () use ($source) {
	expect($source)->toContain('function plugin_slowlog_uninstall');
});

it('declares a name in the INFO file', function () use ($info) {
	expect($info)->toMatch('/^name\s*=/m');
});

it('declares a version in the INFO file', function () use ($info) {
	expect($info)->toMatch('/^version\s*=/m');
});
