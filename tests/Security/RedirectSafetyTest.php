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

describe('redirect safety in slowlog', function () {
	it('calls exit or die after header Location redirects', function () {
		$files = array(
			'setup.php',
			'slowlog.php',
			'slowlog_functions.php',
		);

		foreach ($files as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);
			if ($path === false) continue;
			$contents = file_get_contents($path);
			if ($contents === false) continue;

			$lines = explode("\n", $contents);
			$missingExit = 0;

			for ($i = 0; $i < count($lines); $i++) {
				if (preg_match('/header\s*\(\s*[\'"]Location/', $lines[$i])) {
					// Next non-empty line should contain exit, die, or return
					$foundExit = false;
					for ($j = $i + 1; $j < min($i + 4, count($lines)); $j++) {
						$next = trim($lines[$j]);
						if ($next === '') continue;
						if (preg_match('/\b(exit|die|return)\b/', $next)) {
							$foundExit = true;
						}
						break;
					}

					if (!$foundExit) {
						$missingExit++;
					}
				}
			}

			expect($missingExit)->toBe(0,
				"File {$relativeFile} has header(Location) without exit/die"
			);
		}
	});
});
