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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * slowlog_apex_theme()/slowlog_normalize_hex_color() drive the per-theme ApexCharts
 * skinning for the By Method/By Table charts and the import upload donut. They read the
 * active Cacti theme's include/themes/<theme>/rrdtheme.php, so these tests sandbox
 * $config['base_path'] to a temporary themes tree to exercise the require, the
 * CactiColorMode variant selection, the luminance-based light/dark mode, the path
 * containment fallback, and the color normalization branches without a real Cacti tree.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');

	$this->orig_base = $GLOBALS['config']['base_path'];
	$this->sandbox   = sys_get_temp_dir() . '/slowlog_theme_' . bin2hex(random_bytes(4));

	$themes = $this->sandbox . '/include/themes';

	foreach (array('lightx', 'darkx', 'alphax', 'variantx', 'canvasx') as $theme) {
		mkdir($themes . '/' . $theme, 0777, true);
	}

	file_put_contents($themes . '/lightx/rrdtheme.php', "<?php\n\$rrdcolors['font'] = '000000';\n\$rrdcolors['grid'] = 'cccccc';\n");
	file_put_contents($themes . '/darkx/rrdtheme.php', "<?php\n\$rrdcolors['font'] = 'FFFFFF';\n\$rrdcolors['grid'] = '545454';\n\$rrdcolors['back'] = '11161D';\n");
	file_put_contents($themes . '/alphax/rrdtheme.php', "<?php\n\$rrdcolors['font'] = 'FFFFffaa';\n\$rrdcolors['grid'] = '21212400';\n");
	file_put_contents($themes . '/variantx/rrdtheme.php', "<?php\n\$rrdcolors['font'] = '000000';\n\$rrdcolors_dark['font'] = 'FFFFFF';\n\$rrdcolors_light['font'] = '000000';\n");
	file_put_contents($themes . '/canvasx/rrdtheme.php', "<?php\n\$rrdcolors['font'] = 'FFFFFF';\n\$rrdcolors['canvas'] = '0B0E13';\n");

	$GLOBALS['config']['base_path'] = $this->sandbox;
});

afterEach(function () {
	$GLOBALS['config']['base_path'] = $this->orig_base;

	unset($GLOBALS['__test_selected_theme']);
	unset($_COOKIE['CactiColorMode']);

	$themes = $this->sandbox . '/include/themes';

	foreach (array('lightx', 'darkx', 'alphax', 'variantx', 'canvasx') as $theme) {
		@unlink($themes . '/' . $theme . '/rrdtheme.php');
		@rmdir($themes . '/' . $theme);
	}

	@rmdir($themes);
	@rmdir($this->sandbox . '/include');
	@rmdir($this->sandbox);
});

it('expands 3-digit, keeps 6-digit and truncates alpha-suffixed colors', function () {
	expect(slowlog_normalize_hex_color('abc', '000000'))->toBe('aabbcc');
	expect(slowlog_normalize_hex_color('FFFFFF', '000000'))->toBe('ffffff');
	expect(slowlog_normalize_hex_color('FFFFffaa', '000000'))->toBe('ffffff');
	expect(slowlog_normalize_hex_color('21212400', '000000'))->toBe('212124');
});

it('falls back when a color has no usable hex', function () {
	// Empty, a too-short remainder, and a value that strips to nothing all fall back.
	expect(slowlog_normalize_hex_color('', 'cccccc'))->toBe('cccccc');
	expect(slowlog_normalize_hex_color('zz', 'abcdef'))->toBe('abcdef');
	expect(slowlog_normalize_hex_color('xx!!', '123456'))->toBe('123456');
});

it('resolves a light theme to light mode with its font/grid colors', function () {
	$GLOBALS['__test_selected_theme'] = 'lightx';

	$apex = slowlog_apex_theme();

	expect($apex['mode'])->toBe('light');
	expect($apex['foreColor'])->toBe('#000000');
	expect($apex['gridColor'])->toBe('#cccccc');
	expect($apex['background'])->toBe('#ffffff');
});

it('resolves a dark theme to dark mode from its light font luminance', function () {
	$GLOBALS['__test_selected_theme'] = 'darkx';

	$apex = slowlog_apex_theme();

	expect($apex['mode'])->toBe('dark');
	expect($apex['foreColor'])->toBe('#ffffff');
	expect($apex['gridColor'])->toBe('#545454');
	expect($apex['background'])->toBe('#11161d');
});

it('uses the plotting canvas color as the background when back is absent', function () {
	$GLOBALS['__test_selected_theme'] = 'canvasx';

	$apex = slowlog_apex_theme();

	expect($apex['background'])->toBe('#0b0e13');
});

it('strips RRDtool alpha suffixes from the resolved theme colors', function () {
	$GLOBALS['__test_selected_theme'] = 'alphax';

	$apex = slowlog_apex_theme();

	expect($apex['foreColor'])->toBe('#ffffff');
	expect($apex['gridColor'])->toBe('#212124');
	expect($apex['mode'])->toBe('dark');
});

it('prefers the CactiColorMode dark variant when a theme defines one', function () {
	$GLOBALS['__test_selected_theme'] = 'variantx';
	$_COOKIE['CactiColorMode']        = 'dark';

	$apex = slowlog_apex_theme();

	expect($apex['mode'])->toBe('dark');
	expect($apex['foreColor'])->toBe('#ffffff');
});

it('uses the light variant when CactiColorMode selects light', function () {
	$GLOBALS['__test_selected_theme'] = 'variantx';
	$_COOKIE['CactiColorMode']        = 'light';

	$apex = slowlog_apex_theme();

	expect($apex['mode'])->toBe('light');
	expect($apex['foreColor'])->toBe('#000000');
});

it('falls back to default colors when the theme file is missing', function () {
	$GLOBALS['__test_selected_theme'] = 'does_not_exist';

	$apex = slowlog_apex_theme();

	expect($apex['mode'])->toBe('light');
	expect($apex['foreColor'])->toBe('#000000');
	expect($apex['gridColor'])->toBe('#cccccc');
	expect($apex['background'])->toBe('#ffffff');
});
