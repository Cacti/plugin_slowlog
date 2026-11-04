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

/*
 * The filter forms build their request URL in JavaScript. A value containing &, # or + (a search
 * term, a table or user name, a date with a space) has to be encoded or it ends the parameter
 * early or changes it. slowlog.php cannot be loaded into the unit process, so the generated
 * script is checked from the source.
 */

it('encodes every free-text and name value appended to a filter URL', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	$fields = array(
		"'&filter=' + encodeURIComponent(\$('#filter').val())",
		"'&mmethod=' + encodeURIComponent(\$('#mmethod').val())",
		"'&table=' + encodeURIComponent(\$('#table').val())",
		"'&user=' + encodeURIComponent(\$('#myuser').val())",
		"'&host=' + encodeURIComponent(\$('#host').val())",
		"'&date1=' + encodeURIComponent(\$('#date1').val())",
		"'&date2=' + encodeURIComponent(\$('#date2').val())",
		"'&chart_scope=' + encodeURIComponent((\$('#chart_scope').val() || []).join(','))",
	);

	foreach ($fields as $field) {
		expect($source)->toContain($field);
	}
});

it('encodes the filter on the summary page and the dates on both time shift links', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->toContain("'?action=select&header=false&filter=' + encodeURIComponent(\$('#filter').val())");
	expect(substr_count($source, "'&date1='+encodeURIComponent(\$('#date1').val())"))->toBe(2);
	expect(substr_count($source, "'&date2='+encodeURIComponent(\$('#date2').val())"))->toBe(2);
});

it('has no remaining unencoded text input appended to a filter URL', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	foreach (array('#filter', '#date1', '#date2', '#table', '#myuser', '#host', '#mmethod') as $id) {
		expect(preg_match('/strURL\s*\+?=[^;\n]*\+\s*\$\(\'' . preg_quote($id, '/') . '\'\)\.val\(\)/', $source))->toBe(0);
	}
});
