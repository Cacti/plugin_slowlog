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

describe('slowlog setup.php structure', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../setup.php'));
	$info   = parse_ini_file(realpath(__DIR__ . '/../../INFO'), true)['info'];

	it('defines plugin_slowlog_install function', function () use ($source) {
		expect($source)->toContain('function plugin_slowlog_install');
	});

	it('defines plugin_slowlog_version function', function () use ($source) {
		expect($source)->toContain('function plugin_slowlog_version');
	});

	it('defines plugin_slowlog_uninstall function', function () use ($source) {
		expect($source)->toContain('function plugin_slowlog_uninstall');
	});

	it('declares a plugin name in INFO', function () use ($info) {
		expect($info)->toHaveKey('name');
	});

	it('declares a plugin version in INFO', function () use ($info) {
		expect($info)->toHaveKey('version');
	});

	it('registers hooks in install function', function () use ($source) {
		expect($source)->toContain('api_plugin_register_hook');
	});
});
