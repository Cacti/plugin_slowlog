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
 * form_actions() deletes the selected logs whenever it receives selected_items, and the
 * confirmation form always carries that field. Any control on the confirmation page that
 * submits the form therefore confirms the delete, so the No and Return controls must be
 * plain buttons that navigate away instead of submitting. form_actions() lives in
 * slowlog.php, which cannot be loaded into the unit process, so the form markup is checked
 * from the source. The full request flow is covered by tests/E2E/DeleteConfirmationTest.php.
 */

it('does not render the No or Return control as a submit button', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->not->toContain("<input type='submit' name='cancel'");
});

it('renders the No and Return controls as buttons that return to the previous page', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->toContain("<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='\" . __esc('No', 'slowlog')");
	expect($source)->toContain("<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='\" . __esc('Return', 'slowlog')");
});

it('keeps Yes as the only submit control that carries the selected items', function () {
	$source = file_get_contents(realpath(__DIR__ . '/../../slowlog.php'));

	expect($source)->toContain("\$save_html = \"<input type='submit' name='save' value='\" . __esc('Yes', 'slowlog') . \"'>\";");
});
