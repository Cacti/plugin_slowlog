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

describe('output escaping in slowlog', function () {
	it('does not interpolate raw variables into HTML attributes', function () {
		$uiFiles = array(
			'slowlog.php',
			'slowlog_functions.php',
		);

		foreach ($uiFiles as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);
			if ($path === false) continue;
			$contents = file_get_contents($path);
			if ($contents === false) continue;

			$lines = explode("\n", $contents);
			$dangerous = 0;

			foreach ($lines as $line) {
				$trimmed = ltrim($line);
				if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0) continue;

				// value/href/src/title/alt/placeholder="<?php echo|print $... without escaping
				if (preg_match('/(?:value|href|src|title|alt|placeholder)\s*=\s*["\']\s*<\?php\s+(?:echo|print)\s+\$(?!_|config)(\w+)/', $line, $matches)) {
					if (strpos($line, 'html_escape') !== false
						|| strpos($line, '__esc') !== false
						|| strpos($line, 'htmlspecialchars') !== false
						|| strpos($line, 'json_encode') !== false
						|| strpos($line, '(int)') !== false) {
						continue;
					}

					// Allow variables that are always assigned from an escaped/cast value elsewhere in the file
					$variable = $matches[1];
					if (preg_match('/\$' . preg_quote($variable, '/') . '\s*=\s*(?:__esc\s*\(|html_escape\s*\(|htmlspecialchars\s*\(|\(int\))/', $contents)) {
						continue;
					}

					// Allow variables that are never derived from request/user input (not tainted)
					$isTainted = preg_match(
						'/\$' . preg_quote($variable, '/') . '\s*=[^;]*(?:get_request_var|get_nfilter_request_var|get_filter_request_var|\$_GET|\$_POST|\$_REQUEST)/',
						$contents
					);

					if (!$isTainted) {
						continue;
					}

					$dangerous++;
				}
			}

			expect($dangerous)->toBe(0,
				"File {$relativeFile} has unescaped variables in HTML attributes"
			);
		}
	});

	it('uses html_escape or __esc for user-controlled output', function () {
		$uiFiles = array(
			'slowlog.php',
			'slowlog_functions.php',
		);

		$totalEscapeCalls = 0;

		foreach ($uiFiles as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);
			if ($path === false) continue;
			$contents = file_get_contents($path);
			if ($contents === false) continue;

			$totalEscapeCalls += preg_match_all('/html_escape|__esc\(|htmlspecialchars/', $contents);
		}

		// At least some escaping should be present in UI files
		expect($totalEscapeCalls)->toBeGreaterThan(0,
			'UI files should contain at least one html_escape/__esc call'
		);
	});

	it('JSON-encodes the chart title/description instead of concatenating it into inline JS', function () {
		// slowlog_get_chart_object()/slowlog_get_stats_chart_object() build the chart title
		// from plugin_slowlog.description, which is user-controlled at import time. It must
		// never be dropped into the inline <script> block via '"' . $x . '"' string
		// concatenation - only via json_encode(), which escapes quotes/tags/slashes so a
		// description containing '"' or '</script>' can't break out of the JS string/script.
		$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

		expect($source)->not->toMatch('/[\'"]\s*\.\s*\$(?:data|stats)\[\'title\'\]/');
		expect(preg_match_all('/json_encode\(\s*\$(?:data|stats)\[\'title\'\]/', $source))->toBeGreaterThanOrEqual(3);
	});
});
