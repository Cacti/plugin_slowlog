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

describe('auth guard presence in slowlog', function () {
	it('includes auth.php or global.php in all UI entry points', function () {
		$uiFiles = array(
			'slowlog.php',
		);

		foreach ($uiFiles as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);
			if ($path === false) continue;
			$contents = file_get_contents($path);
			if ($contents === false) continue;

			$hasAuth = (
				strpos($contents, 'auth.php') !== false ||
				strpos($contents, 'global.php') !== false ||
				strpos($contents, 'global_arrays.php') !== false
			);

			expect($hasAuth)->toBeTrue(
				"File {$relativeFile} does not include auth.php or global.php"
			);
		}
	});

	it('validates numeric IDs from request variables before DB queries', function () {
		$uiFiles = array(
			'slowlog.php',
		);

		foreach ($uiFiles as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);
			if ($path === false) continue;
			$contents = file_get_contents($path);
			if ($contents === false) continue;

			// Check for get_filter_request_var usage for the logid identifier
			if (preg_match('/get_request_var\s*\(\s*[\'"]logid[\'"]/', $contents)) {
				// Should use get_filter_request_var for the 'logid' param
				$hasFilter = (
					strpos($contents, "get_filter_request_var('logid')") !== false ||
					strpos($contents, 'input_validate_input_number') !== false ||
					strpos($contents, 'form_input_validate') !== false
				);

				expect($hasFilter)->toBeTrue(
					"File {$relativeFile} uses get_request_var for logid without validation"
				);
			}
		}
	});
});
