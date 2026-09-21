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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * PHPStan-only stubs for Cacti core (host application) functions/constants this plugin
 * calls but does not define itself. Signatures are copied from Cacti's own source
 * (Cacti/cacti, develop branch: lib/html.php, lib/html_form.php, lib/html_utility.php,
 * include/global_languages.php, cli/sqltable_to_php.php) where the real definition was
 * found; a few (marked below) are inferred from many real call sites across Cacti core
 * where the literal definition wasn't located in the time available - flagged in the PR
 * description as a follow-up item. This file is excluded from analysis (phpstan.neon's
 * bootstrapFiles, not paths), so its intentionally-empty bodies never get flagged as
 * unused/dead code.
 *
 * __()/__esc() are NOT re-declared here: tests/bootstrap-unit.php already declares them
 * (guarded by function_exists(), scanned by PHPStan since tests/ is analyseAndScan-excluded
 * only from analysis, not scanning) and its old 2-param signature was widened there instead,
 * so there's exactly one definition PHPStan can resolve rather than two competing ones.
 */

// --- Constants -------------------------------------------------------------------------

/** cli/sqltable_to_php.php: print "... Version $version, " . COPYRIGHT_YEARS */
define('COPYRIGHT_YEARS', '2004-2026');

/** lib/html.php: default nav-bar page-count cap, e.g. html_nav_bar(..., MAX_DISPLAY_PAGES, ...) */
define('MAX_DISPLAY_PAGES', 25);

/** include/global_constants.php: sentinel timespan value meaning "custom range" */
define('GT_CUSTOM', -1);

// --- Plugin API --------------------------------------------------------------------------

/**
 * lib/plugins.php
 *
 * @return void
 */
function api_plugin_register_hook(string $plugin, string $hook, string $function, string $file, ?string $context = null): void {
}

/**
 * lib/plugins.php
 *
 * @return void
 */
function api_plugin_register_realm(string $plugin, string $files, string $name, int $enabled = 0): void {
}

// --- Request/session validation ------------------------------------------------------------

/**
 * lib/html_utility.php
 *
 * @param array<string, array<string, mixed>> $filters
 *
 * @return void
 */
function validate_store_request_vars(array $filters, string $sess_prefix = ''): void {
}

/**
 * lib/html_utility.php - alias family; this plugin's own code and Cacti core both use
 * get_request_var_request() as an alias of get_request_var() bound to a specific session
 * bucket (inferred from call sites, e.g. links.php's get_request_var_request('page')).
 *
 * @return mixed
 */
function get_request_var_request(string $name, mixed $default = ''): mixed {
	return $default;
}

/**
 * lib/html_utility.php - html_escape()'s the current value of a request variable.
 *
 * @return string
 */
function html_escape_request_var(string $name): string {
	return '';
}

/**
 * include/global.php - sets $_REQUEST['action'] to $action when not already present.
 *
 * @return void
 */
function set_default_action(string $action = ''): void {
}

// --- Form/HTML rendering (lib/html.php, lib/html_form.php, lib/html_utility.php) -----------

/**
 * lib/html_form.php
 *
 * @return void
 */
function form_start(mixed $action, string $id = '', bool $multipart = false): void {
}

/**
 * lib/html_form.php
 *
 * @return void
 */
function form_end(bool $ajax = true): void {
}

/**
 * lib/html_form.php
 *
 * @return void
 */
function form_hidden_box(string $form_name, mixed $prev_val, mixed $default_val, mixed $in_form = false): void {
}

/**
 * lib/html_form.php
 *
 * @return void
 */
function form_save_button(mixed $cancel_url, mixed $force_type = '', string $key_field = 'id', bool $ajax = true): void {
}

/**
 * lib/html_form.php
 *
 * @param array<string, mixed> $array
 *
 * @return void
 */
function draw_edit_form(array $array): void {
}

/**
 * lib/html.php
 *
 * @return void
 */
function html_start_box(string $title, string $width, mixed $div, int $cell_padding, string $align, mixed $url_or_buttons): void {
}

/**
 * lib/html.php
 *
 * @return void
 */
function html_end_box(bool $trailing_br = true, bool $div = false): void {
}

/**
 * lib/html.php
 *
 * @return string
 */
function html_nav_bar(string $base_url, int $max_pages, int $current_page, int $rows_per_page, int $total_rows,
	int $colspan = 30, string $object = '', string $page_var = 'page', string $return_to = '', bool $page_count = true): string {
	return '';
}

/**
 * lib/html.php
 *
 * @param array<int|string, mixed> $header_items
 *
 * @return void
 */
function html_header_sort(array $header_items, string $sort_column, string $sort_direction,
	int $last_item_colspan = 1, string $url = '', string $return_to = ''): void {
}

/**
 * lib/html.php
 *
 * @param array<int|string, mixed> $header_items
 *
 * @return void
 */
function html_header_sort_checkbox(array $header_items, string $sort_column, string $sort_direction,
	bool $include_form = true, string $form_action = '', string $return_to = '', string $prefix = 'chk'): void {
}

/**
 * lib/html.php - inferred from call sites (form_alternate_row('line'.$id, true, $disabled)):
 * alternates row background class and optionally marks a row disabled.
 *
 * @return void
 */
function form_alternate_row(string $id = '', bool $new_row = false, bool $disabled = false): void {
}

/**
 * lib/html_utility.php
 *
 * @return void
 */
function form_end_row(): void {
}

/**
 * lib/html_utility.php - inferred from call sites (form_selectable_cell($contents, $id, $width, $align_or_style)).
 *
 * @return void
 */
function form_selectable_cell(mixed $contents, mixed $id, string $width = '', string $align = ''): void {
}

/**
 * lib/html_utility.php
 *
 * @return void
 */
function form_checkbox_cell(string $title, string $id, bool $disabled = false, bool $checked = false): void {
}

/**
 * lib/html.php
 *
 * @param array<int, mixed> $actions_array
 *
 * @return void
 */
function draw_actions_dropdown(array $actions_array, int $delete_action = 1): void {
}

// --- Misc helpers (inferred from many real call sites; literal definitions not located) ----

/**
 * lib/html_utility.php - highlights $needle inside $value and optionally wraps it as a link
 * to $link with $title, used throughout Cacti's list pages, e.g.
 * filter_value($row['name'], grv('filter'), 'page.php?action=edit&id=' . $row['id']).
 *
 * @return string
 */
function filter_value(mixed $value, string $filter, string $link = '', string $title = ''): string {
	return '';
}

/**
 * lib/functions.php - locale-aware number formatting, e.g. number_format_i18n($n, -1).
 *
 * @return string
 */
function number_format_i18n(mixed $value, ?int $precision = null): string {
	return '';
}

/**
 * lib/functions.php - converts a seconds duration into a "1d 02:03:04"-style string, e.g.
 * get_daysfromtime($timeout_time, true).
 *
 * @return string
 */
function get_daysfromtime(mixed $seconds, bool $extended = false): string {
	return '';
}

/**
 * lib/html_utility.php - builds the current page's "ORDER BY ..." clause from the
 * sort_column/sort_direction request vars.
 *
 * @return string
 */
function get_order_string(): string {
	return '';
}

/**
 * lib/utility.php - name of the currently-selected theme.
 *
 * @return string
 */
function get_selected_theme(): string {
	return '';
}

/**
 * lib/functions.php - unserializes the 'selected_items' request var used by bulk actions.
 *
 * @return array<int, mixed>|false
 */
function sanitize_unserialize_selected_items(mixed $selected_items): array|false {
	if (is_array($selected_items)) {
		return $selected_items;
	}

	return false;
}

/**
 * lib/functions.php - dies with an input-validation error if $value isn't numeric.
 *
 * @return void
 */
function input_validate_input_number(mixed $value, string $name = ''): void {
}

/**
 * cli/sqltable_to_php.php
 *
 * @return string
 */
function get_cacti_cli_version(): string {
	return '';
}
