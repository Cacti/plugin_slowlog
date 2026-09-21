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

chdir('../../');
include('./include/auth.php');
include_once('./lib/utility.php');
include_once('./lib/poller.php');
include_once('./lib/timespan_settings.php');
include_once('./plugins/slowlog/slowlog_functions.php');

ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');

$actions = array(
	1 => __('Delete', 'slowlog')
);

set_default_action('select');

switch (get_request_var('action')) {
	case 'save':
	case 'import':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'viewmethods':
		slowlog_view_charts('methods');

		break;
	case 'viewtables':
		slowlog_view_charts('tables');

		break;
	case 'edit':
		slowlog_render_with_layout('slowlog_import');

		break;
	case 'methods':
		slowlog_render_with_layout(function () {
			slowlog_view_charts('methods');
		});

		break;
	case 'tables':
		slowlog_render_with_layout(function () {
			slowlog_view_charts('tables');
		});

		break;
	case 'details':
		slowlog_render_with_layout('slowlog_view_details');

		break;
	case 'query':
		slowlog_render_with_layout('slowlog_view_query');

		break;
	default:
		slowlog_render_with_layout('slowlog_view');

		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save(): void {
	global $config;

	if (isset($_POST['save_component_slowlog'])) {
		$logid = api_slowlog_save($_POST['logid'], $_POST['description'], $_POST['length']);

		header('Location: slowlog.php?action=methods&logid=' . (empty($logid) ? $_POST['logid'] : $logid));

		exit;
	}

	if (isset($_POST['save_component_import'])) {
		if (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
			$table_mode = get_nfilter_request_var('table_mode');

			if (!in_array($table_mode, array('cacti', 'reference', 'all'), true)) {
				$table_mode = 'all';
			}

			if ($table_mode == 'reference' && trim(get_nfilter_request_var('table_names')) == '') {
				raise_message('slowlog_reference_names_required', __('ERROR: \'Detect all tables; group ... as OTHER TABLES\' requires a list of Tables of Interest.', 'slowlog'), MESSAGE_LEVEL_ERROR);

				header('Location: slowlog.php');
				exit;
			}

			// Stage the upload outside of PHP's request-scoped tmp handling - the
			// heavy ingest below runs in a background worker after this request ends,
			// and the original tmp_name is deleted as soon as we return.
			$import_path = sys_get_temp_dir() . '/slowlog_upload_' . uniqid('', true) . '.log';

			if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $import_path)) {
				raise_message('slowlog_upload_failed', __('ERROR: Unable to stage the uploaded Slowlog file for import.', 'slowlog'), MESSAGE_LEVEL_ERROR);

				header('Location: slowlog.php');
				exit;
			}

			// Insert the parent record up front (import_status defaults to 0/Pre-Processing
			// so it's visible in the list immediately), then hand the entire ingest - not
			// just post-processing - to a background worker. Reading/parsing/inserting a
			// multi-hundred-MB log inline in this request can easily exceed a front-end
			// reverse proxy's own read timeout, which PHP's max_execution_time can't override.
			$save['logid']         = 0;
			$save['description']   = get_nfilter_request_var('description');
			$save['import_date']   = date('Y-m-d H:i:s');
			$save['import_lines']  = 0;
			$save['import_tables'] = get_nfilter_request_var('table_names');
			$save['start_time']    = date('Y-m-d H:i:s');
			$save['end_time']      = date('Y-m-d H:i:s');

			$logid = sql_save($save, 'plugin_slowlog', 'logid');

			if (empty($logid)) {
				raise_message('slowlog_upload_failed', __('ERROR: Unable to create the Slowlog import record.', 'slowlog'), MESSAGE_LEVEL_ERROR);

				header('Location: slowlog.php');
				exit;
			}

			$php = cacti_escapeshellcmd(read_config_option('path_php_binary'));

			$cmd  = $config['base_path'] . '/plugins/slowlog/import_log.php';
			$cmd .= ' --logid=' . $logid;
			$cmd .= ' --logfile=' . cacti_escapeshellarg($import_path);
			$cmd .= ' --description=' . cacti_escapeshellarg(get_nfilter_request_var('description'));
			$cmd .= ' --length=' . cacti_escapeshellarg(get_nfilter_request_var('length'));
			$cmd .= $table_mode == 'cacti' ? ' --usecacti' : '';
			$cmd .= ' --table-mode=' . cacti_escapeshellarg($table_mode);
			$cmd .= trim(get_nfilter_request_var('table_names')) !== '' ? ' --table-names=' . cacti_escapeshellarg(trim(get_nfilter_request_var('table_names'))) : '';
			$cmd .= ' --delete-after';

			exec_background($php, $cmd);

			raise_message('import_pre', __('The Slowlog has been queued and will be imported in the background. Refresh this page to track progress.', 'slowlog'), MESSAGE_LEVEL_INFO);
		} else {
			header('Location: slowlog.php');
			exit;
		}

		header('Location: slowlog.php');
		exit;
	}
}

function form_actions(): void {
	global $config, $actions;

	/* if we are to save this form, instead of display it */
	if (isset($_POST['selected_items'])) {
		$selected_items = sanitize_unserialize_selected_items(get_request_var('selected_items')) ?: array();

		if ($_POST['drp_action'] == '1') { /* delete */
			for ($i=0; $i<cacti_sizeof($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_slowlog_remove($selected_items[$i]);
			}
		}

		header('Location: slowlog.php');
		exit;
	}

	/* setup some variables */
	$slowlog_list = ''; $slowlog_array = array();

	/* loop through each of the host templates selected on the previous page and get more info about them */
	foreach($_POST AS $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/i', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$slowlog_info = db_fetch_cell_prepared("SELECT CONCAT_WS('', description, ' - ', import_date, '')
				FROM plugin_slowlog
				WHERE logid = ?",
				array($matches[1]));

			$slowlog_list   .= '<li>' . html_escape($slowlog_info) . '</li>';
			$slowlog_array[] = $matches[1];
		}
	}

	general_header();

	form_start('slowlog.php');

	html_start_box($actions[$_POST['drp_action']], '60%', '', 3, 'center', '');

	if ($_POST['drp_action'] == '1') { /* delete */
		print "<tr class='even'>
			<td class='textArea'>
				<p>Are you sure you want to delete the following MySQL Slow Log entries?</p>
				<ul class='itemlist'>$slowlog_list</ul>
			</td>
		</tr>";
	}

	if (!cacti_sizeof($slowlog_array)) {
		print "<tr><td><span class='textError'>" . __esc('You must select at least one Slowlog record.', 'slowlog') . "</span></td></tr>";
		$save_html = '';
	} else {
		$save_html = "<input type='submit' name='save' value='" . __esc('Yes', 'slowlog') . "'>";
	}

	print "	<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (cacti_sizeof($slowlog_array) ? serialize($slowlog_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . $_POST['drp_action'] . "'>" . (strlen($save_html) ? "
			<input type='submit' name='cancel' value='" . __esc('No', 'slowlog') . "'>
			$save_html" : "<input type='submit' name='cancel' value='" . __esc('Return', 'slowlog') . "'>") . "
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

/**
 * NOTE: this save path is currently unreachable from the UI - no form on this page posts
 * save_component_slowlog - and was already unconditionally short-circuited by an early
 * `return true;` before any of the save logic ran. Preserved as a no-op stub (removing the
 * dead code below doesn't change behavior, since it never executed) rather than resurrecting
 * untested save logic as part of a typing pass.
 *
 * @param mixed $logid
 * @param mixed $description
 * @param mixed $length
 */
function api_slowlog_save($logid, $description, $length): bool {
	return true;
}

function slowlog_import(): void {
	global $config;

	$selected_theme = get_selected_theme();

	print '<script type="text/javascript" src="' . $config['url_path'] . 'plugins/slowlog/js/apexcharts.js"></script>';

	if (file_exists($config['base_path'] . "/plugins/slowlog/themes/$selected_theme/apexcharts.css")) {
		print '<link href="' . html_escape($config['url_path'] . "plugins/slowlog/themes/$selected_theme/apexcharts.css") . '" type="text/css" rel="stylesheet">';
	} else {
		print '<link href="' . html_escape($config['url_path'] . "plugins/slowlog/js/apexcharts.css") . '" type="text/css" rel="stylesheet">';
	}

	$upload_max_filesize = ini_get('upload_max_filesize') . 'Bytes';
	$post_max_size       = ini_get('post_max_size') . 'Bytes';

	$upload_env = slowlog_upload_environment_status();

	$import_form = array(
		'import_file' => array(
			'method' => 'file',
			'friendly_name' => __('Import Template from Local File', 'slowlog'),
			'description' => __('If the XML file containing template data is located on your local machine, select it here.', 'slowlog'),
			'accept' => '.log',
		),
		'description' => array(
			'method' => 'textbox',
			'friendly_name' => __('LogFile Description', 'slowlog'),
			'description' => __('Please provide a description for this MySQL Slowlog file to be imported.', 'package'),
			'value' => __('New Slow Log', 'slowlog'),
			'size' => '40',
			'max_length' => '40'
		),
		'length' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Max Query Length', 'slowlog'),
			'description' => __('Only import the first X characters of the SQL Query from the MySQL Slow Query log.', 'package'),
			'value' => -1,
			'array' => array(
				-1     => __('All', 'slowlog'),
				1024   => __('%d Chars', 1024, 'slowlog'),
				2048   => __('%d Chars', 2048, 'slowlog'),
				4096   => __('%d Chars', 4096, 'slowlog'),
				8192   => __('%d Chars', 8192, 'slowlog'),
				16384  => __('%d Chars', 16384, 'slowlog'),
			)
		),
		'spacer1' => array(
			'method' => 'spacer',
			'friendly_name' => __('Slowlog Table Names [ optional ]', 'slowlog'),
			'description' => __('Leave Blank for Auto Detection which runs must faster.', 'slowlog'),
		),
		'table_mode' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Cacti Table Detection', 'slowlog'),
			'description' => __('How to tell Cacti-native tables apart from other tables referenced in the slow query log. Defaults to detecting every table with no grouping.', 'slowlog'),
			'value' => 'all',
			'array' => array(
				'all'       => __('Detect all tables (no \'OTHER TABLES\' grouping)', 'slowlog'),
				'cacti'     => __('Use this Cacti Database; group other tables as \'OTHER TABLES\'', 'slowlog'),
				'reference' => __('Detect all tables; group tables not in the list below as \'OTHER TABLES\'', 'slowlog'),
			),
		),
		'table_names' => array(
			'method' => 'textarea',
			'friendly_name' => __('Tables of Interest'),
			'description' => __('Only used by the \'Detect all tables; group ... as OTHER TABLES\' option above. Please provide a space delimited list of known tables to compare against. In Linux/UNIX, you may obtain a list of tables by using the following command:<br><br> print `mysql -u<i><b>user</b></i> -p<i><b>password</b></i> -e "show tables" <i><b>database</b></i> | grep -v Tables_in` | tr \'\n\' \' \'<br><br>The values of \'<i><b>user</b></i>\', \'<i><b>password</b></i>\', and \'<i><b>database</b></i>\' are replaced with your values.'),
			'class' => 'textAreaNotes',
			'value' => '',
			'textarea_rows' => '5',
			'textarea_cols' => '50'
		),
		'spacer2' => array(
			'method' => 'spacer',
			'friendly_name' => __('Upload Limits', 'slowlog'),
			'description' => __('File Size upload limits in Cacti.', 'slowlog'),
		),
		'novalue0' => array(
			'method' => 'other',
			'friendly_name' => __('Max Upload Filesize', 'slowlog'),
			'description' => __('The maximum filesize your Apache server will allow to be uploaded is set to the value on the right.  Currently, you can not upload a file larger than this.  If you have MySQL Slow logs larger than this, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>upload_max_filesize</i></b> and increase the value.  After which you must restart Apache.', 'slowlog'),
			'value'  => $upload_max_filesize
		),
		'novalue1' => array(
			'method' => 'other',
			'friendly_name' => __('Max Post Size', 'slowlog'),
			'description' => __('The maximum size you can post to the Apache server is set to the value on the right.  If you have MySQL Slow logs larger than this value, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>post_max_size</i></b> and increase its value.  After which you must restart Apache.', 'slowlog'),
			'value'  => $post_max_size
		),
		'novalue2' => array(
			'method' => 'other',
			'friendly_name' => __('Max Execution Time', 'slowlog'),
			'description' => __('How long (in seconds) this request is allowed to run for; 0 means unlimited. This plugin already raises it to unlimited for its own requests, but a web server or reverse proxy in front of PHP (Apache Timeout, Nginx fastcgi_read_timeout/proxy_read_timeout, PHP-FPM request_terminate_timeout) can still cut the request off independently - that cannot be detected or overridden from here.', 'slowlog'),
			'value'  => $upload_env['max_execution_time'] == 0 ? __('Unlimited', 'slowlog') : $upload_env['max_execution_time'] . ' ' . __('Seconds', 'slowlog')
		),
		'novalue3' => array(
			'method' => 'other',
			'friendly_name' => __('Memory Limit', 'slowlog'),
			'description' => __('How much memory this request is allowed to use; -1 means unlimited. This plugin already raises it to unlimited for its own requests. A very large slow query log being read/imported in a single request can exceed a limited value.', 'slowlog'),
			'value'  => $upload_env['memory_limit'] == '-1' ? __('Unlimited', 'slowlog') : $upload_env['memory_limit']
		)
	);

	form_start('slowlog.php?action=import', 'import', true);

	if (cacti_sizeof($upload_env['warnings'])) {
		print "<div class='textArea' style='border:1px solid #d8b100;background:#fff8e1;padding:8px;margin-bottom:10px;'>";
		print "<strong>" . __esc('WARNING', 'slowlog') . ":</strong> " . __esc('The current server configuration may cause a large import to fail:', 'slowlog');
		print '<ul style="margin:4px 0 0 20px;">';

		foreach($upload_env['warnings'] as $warning) {
			print '<li>' . html_escape($warning) . '</li>';
		}

		print '</ul></div>';
	}

	html_start_box(__('Import MariaDB/MySQL Slowlog', 'slowlog'), '100%', '', 3, 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => $import_form
		)
	);

	html_end_box(true, true);

	print '<div id="contents"></div>';

	form_hidden_box('save_component_import','1','');

	form_save_button('', 'import', 'import', false);

	?>
	<div id="slowlog_upload_progress" style="display:none;margin-top:10px;text-align:center;">
		<div id="slowlog_upload_donut" style="width:160px;height:160px;margin:0 auto;"></div>
		<div id="slowlog_upload_progress_text" style="margin-top:4px;"></div>
	</div>
	<script type="text/javascript">
	(function() {
		var xhrInFlight = null;
		var donutChart  = null;

		function formatBytes(bytes) {
			var units = new Array('B', 'KB', 'MB', 'GB', 'TB');
			var i     = 0;

			while (bytes >= 1024 && i < units.length - 1) {
				bytes /= 1024;
				i++;
			}

			return bytes.toFixed(1) + ' ' + units[i];
		}

		// Pace's own top-of-page bar only ever shows a heuristic "something is loading"
		// animation - it has no API for rendering an arbitrary caller-supplied percentage as
		// a number, so the byte-accurate readout is a separate ApexCharts radialBar (already
		// bundled for the By Method/By Table charts) updated from the same xhr.upload
		// progress event Pace can't see into.
		function initDonut() {
			if (donutChart !== null) {
				donutChart.updateSeries([0]);
				return;
			}

			donutChart = new ApexCharts(document.querySelector('#slowlog_upload_donut'), {
				chart: {
					type: 'radialBar',
					height: 160,
					width: 160,
					sparkline: { enabled: true }
				},
				series: [0],
				labels: ['<?php print __esc('Uploaded', 'slowlog');?>'],
				colors: ['#4caf50'],
				plotOptions: {
					radialBar: {
						hollow: { size: '65%' },
						track: { background: '#e0e0e0' },
						dataLabels: {
							name: { show: false },
							value: {
								fontSize: '22px',
								formatter: function(val) {
									return Math.round(val) + '%';
								}
							}
						}
					}
				}
			});

			donutChart.render();
		}

		function setDonut(pct) {
			if (donutChart !== null) {
				donutChart.updateSeries([pct]);
			}
		}

		// Delegated so this doesn't depend on knowing Cacti's internal form/id markup -
		// just checks whether whatever form was submitted contains a populated file input
		// named import_file, and takes over that submit with a FormData/XHR upload so the
		// browser's native xhr.upload.progress event can drive a real byte-accurate donut -
		// no php.ini session.upload_progress setting or web server buffering config needed.
		// Namespaced and unbound first because this inline script re-runs every time Cacti's
		// AJAX page navigation reloads this content - without this, each reload would stack
		// another duplicate handler, firing the upload (and the import behind it) once per
		// stacked handler for a single submit.
		$(document).off('submit.slowlogUpload').on('submit.slowlogUpload', 'form', function(event) {
			var form      = this;
			var fileField = $(form).find('input[name="import_file"]')[0];

			if (!fileField || !fileField.files || !fileField.files.length) {
				return;
			}

			event.preventDefault();

			if (xhrInFlight !== null) {
				return;
			}

			// Cacti's own applySkin() debounce deliberately excludes import/export
			// submit buttons (.not('.import, .export') in include/layout.js), so a
			// fast double-click/Enter+click isn't blocked there - disable it ourselves
			// so a second submit can't slip through and queue a duplicate import.
			$(form).find('input[type="submit"], button[type="submit"]').prop('disabled', true);

			initDonut();

			$('#slowlog_upload_progress').show();
			$('#slowlog_upload_progress_text').text('0% (0 B / ' + formatBytes(fileField.files[0].size) + ')');

			if (typeof Pace !== 'undefined') {
				Pace.start();
			}

			xhrInFlight = $.ajax({
				type: 'POST',
				// form.action would return the <input name="action"> element instead of the
				// URL string - it shadows the form's action property, so read the attribute.
				url: form.getAttribute('action'),
				data: new FormData(form),
				processData: false,
				contentType: false,
				cache: false,
				xhr: function() {
					var xhr = $.ajaxSettings.xhr();

					if (xhr.upload) {
						xhr.upload.addEventListener('progress', function(e) {
							if (!e.lengthComputable) {
								return;
							}

							var pct = Math.min(100, Math.round((e.loaded / e.total) * 100));

							setDonut(pct);
							$('#slowlog_upload_progress_text').text(pct + '% (' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total) + ')');
						});
					}

					return xhr;
				}
			}).done(function() {
				setDonut(100);
				$('#slowlog_upload_progress_text').text('<?php print __esc('Processing, please wait...', 'slowlog');?>');

				// The server-side response already followed the same redirect a native
				// submit would have (Location: slowlog.php) - $.ajax just hands us the
				// destination page's body rather than navigating, so reload via Cacti's
				// own AJAX page navigation (include/layout.js) instead of a hard page
				// reload. force=true skips the unsaved-changes prompt, since the form
				// was already legitimately submitted above.
				if (typeof loadPage === 'function') {
					loadPage('<?php print html_escape($config['url_path'] . 'plugins/slowlog/slowlog.php');?>', true);
				} else {
					window.location.href = 'slowlog.php';
				}
			}).fail(function() {
				$('#slowlog_upload_progress_text').text('<?php print __esc('Upload failed - please try again.', 'slowlog');?>');
				$(form).find('input[type="submit"], button[type="submit"]').prop('disabled', false);
			}).always(function() {
				xhrInFlight = null;

				if (typeof Pace !== 'undefined') {
					Pace.stop();
				}
			});
		});
	})();
	</script>
	<?php
}

function slowlog_request_validation(): void {
	$logid = get_filter_request_var('logid');

	$logdata = db_fetch_row_prepared('SELECT *
		FROM plugin_slowlog
		WHERE logid = ?',
		array($logid));

	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
		),
		'logid' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'mmethod' => array(
			'filter' => FILTER_CALLBACK,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
		),
		'host' => array(
			'filter' => FILTER_CALLBACK,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
		),
		'user' => array(
			'filter' => FILTER_CALLBACK,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
		),
		'source' => array(
			'filter' => FILTER_CALLBACK,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'table' => array(
			'filter' => FILTER_CALLBACK,
			'default' => '-1',
			'options' => array('options' => 'sanitize_search_string')
		),
		'index' => array(
			'filter' => FILTER_CALLBACK,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		),
		'date1' => array(
			'filter' => FILTER_CALLBACK,
			'default' => cacti_sizeof($logdata) ? $logdata['start_time']:'',
			'options' => array('options' => 'sanitize_search_string')
		),
		'date2' => array(
			'filter' => FILTER_CALLBACK,
			'default' => cacti_sizeof($logdata) ? $logdata['end_time']:'',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'table_name',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
		),
	);

	validate_store_request_vars($filters, 'sess_sl_det');
	/* ================= input validation ================= */

	$_SESSION['sess_start_time'] = $_SESSION['sess_sl_det_date1'];
	$_SESSION['sess_end_time']   = $_SESSION['sess_sl_det_date2'];
}

function slowlog_view_details(): void {
	global $config;

	slowlog_request_validation();

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	$sql_params  = array();
	$sql_where   = '';
	$sql_orderby = get_order_string();
	$sql_limit   = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = 'WHERE (sld.host LIKE ? OR sld.user LIKE ? OR sld.query LIKE ? OR sld.ip_address LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	if (get_request_var('logid') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sld.logid = ?';

		$sql_params[] = get_request_var('logid');
	}

	if (get_request_var('user') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sld.user = ?';

		$sql_params[] = get_request_var('user');
	}

	if (get_request_var('host') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sld.host = ?';

		$sql_params[] = get_request_var('host');
	}

	$sql_where   .= ($sql_where != '' ? ' AND':'WHERE') . ' date BETWEEN ? AND ?';

	$sql_params[] = get_request_var('date1');
	$sql_params[] = get_request_var('date2');

	// Aggregation and down-select filters
	$agg_by_table  = false;
	$agg_by_method = false;
	$sql_join      = '';
	$method        = 'slm.method';
	$table         = 'sldt.table_name';

	if (get_request_var('table') == '-3') { // Aggregate by table
		$agg_by_table = true;
	} elseif (get_request_var('table') == '-2') { // Others
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sldt.table_name IS NULL';
	} elseif (get_request_var('table') != '-1') { // Not All
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sldt.table_name = ?';

		$sql_params[] = get_request_var('table');
	}

	if (get_request_var('mmethod') == '-2') { // Aggregate by method
		$agg_by_method = true;
	} elseif (get_request_var('mmethod') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' slm.methodid = ?';

		$sql_params[] = get_request_var('mmethod');
	}

	if (!$agg_by_table) {
		$sql_join = ' LEFT JOIN plugin_slowlog_details_tables AS sldt
			ON sld.logid = sldt.logid
			AND sld.logentry = sldt.logentry';
	} else {
		$table = '"N/A" AS table_name';
	}

	if (!$agg_by_method) {
		$sql_join .= ' LEFT JOIN plugin_slowlog_details_methods AS sldm
			ON sld.logid = sldm.logid
			AND sld.logentry = sldm.logentry
			LEFT JOIN plugin_slowlog_methods AS slm
			ON sldm.methodid = slm.methodid';
	} else {
		$method = '"N/A" AS method';
	}

	$results = db_fetch_assoc_prepared("SELECT DISTINCT sld.*, $method, $table
		FROM plugin_slowlog_details AS sld
		$sql_join
		$sql_where
		$sql_orderby
		$sql_limit",
		$sql_params);

	//cacti_log(vsprintf(str_replace('?', "'%s'", "SELECT DISTINCT sld.*, $method, $table
	//	FROM plugin_slowlog_details AS sld
	//	$sql_join
	//	$sql_where
	//	$sql_orderby
	//	$sql_limit"),
	//	$sql_params));

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_slowlog_details AS sld
		$sql_join
		$sql_where",
		$sql_params);

	slowlog_tabs();

	$display_text = array(
		'nosort0' => array(
			'display' => __('Actions', 'slowlog')
		),
		'table_name' => array(
			'display' => __('Table Name', 'slowlog'),
			'sort' => 'ASC'
		),
		'method' => array(
			'display' => __('Method', 'slowlog'),
			'sort' => 'ASC'
		),
		'date' => array(
			'display' => __('Date', 'slowlog'),
			'sort' => 'ASC'
		),
		'user' => array(
			'display' => __('User', 'slowlog'),
			'sort' => 'ASC'
		),
		'host' => array(
			'display' => __('Host', 'slowlog'),
			'sort' => 'ASC'
		),
		'query_time' => array(
			'display' => __('Query Time', 'slowlog'),
			'sort' => 'DESC',
			'align' => 'right'
		),
		'lock_time' => array(
			'display' => __('Lock Time', 'slowlog'),
			'sort' => 'DESC',
			'align' => 'right'
		),
		'rows_sent' => array(
			'display' => __('Sent', 'slowlog'),
			'sort' => 'DESC',
			'align' => 'right'
		),
		'rows_examined' => array(
			'display' => __('Examined', 'slowlog'),
			'sort' => 'DESC',
			'align' => 'right'
		),
		'rows_affected' => array(
			'display' => __('Affected', 'slowlog'),
			'sort' => 'DESC',
			'align' => 'right'
		),
		'bytes_sent' => array(
			'display' => __('Bytes Sent', 'slowlog'),
			'sort' => 'DESC',
			'align' => 'right'
		)
	);

	$jsprefix = 'slowlog.php?action=details&logid=' . get_request_var('logid');

	$nav = html_nav_bar('slowlog.php?action=details&logid=' . get_request_var('logid'), MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), __('Log Entries', 'slowlog'), 'page', 'main');

	html_start_box(__('MariaDB/MySQL SlowLog Details', 'slowlog'), '100%', '', 3, 'center', '');
	slowlog_details_filter();
	html_end_box();

	print $nav;

	html_start_box('', '100%', '', 3, 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), 0, $jsprefix);

	$i = 0;
	if (cacti_sizeof($results)) {
		foreach ($results as $r) {
			$table = $r['table_name'] != '' ? $r['table_name']:'others';

			form_alternate_row();

			?>
			<td style='width:1%'>
				<a class='pic' href='slowlog.php?action=query&logid=<?php print $r['logid'];?>&logentry=<?php print $r['logentry'];?>'><i class='fas fa-search-plus pic' title='<?php print __esc('View Details', 'slowlog');?>'></i></a>
			</td>
			<td>
				<a class='pic' class='linkEditMain' href='<?php print html_escape('slowlog.php?action=details&table=' . $table . '&logid=' . $r['logid']);?>'><?php print html_escape($table);?></a>
			</td>
			<td><?php print $r['method'];?></td>
			<td><?php print $r['date'];?></td>
			<td><?php print filter_value($r['user'], get_request_var('filter'));?></td>
			<td><?php print filter_value($r['host'], get_request_var('filter'));?></td>
			<td class='right'><?php print number_format_i18n($r['query_time']);?></td>
			<td class='right'><?php print number_format_i18n($r['lock_time']);?></td>
			<td class='right'><?php print number_format_i18n($r['rows_sent']);?></td>
			<td class='right'><?php print number_format_i18n($r['rows_examined']);?></td>
			<td class='right'><?php print number_format_i18n($r['rows_affected']);?></td>
			<td class='right'><?php print number_format_i18n($r['bytes_sent']);?></td>

			<?php
			form_end_row();
		}
	} else {
		print '<tr><td><em>No Slowlog Records</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($results)) {
		print $nav;
	}
}

function slowlog_view_charts(string $method): void {
	global $config;

	$selected_theme = get_selected_theme();

	switch($selected_theme) {
		case 'classic':
		case 'modern':
		case 'paw':
		case 'paper-plane':
			$mode = 'light';
			break;
		case 'dark':
		case 'sunrise':
		case 'midwinter':
			$mode = 'dark';
			break;
		default:
			$mode = 'light';
	}

	print '<script type="text/javascript" src="' . $config['url_path'] . 'plugins/slowlog/js/apexcharts.js"></script>';

	if (file_exists($config['base_path'] . "/plugins/slowlog/themes/$selected_theme/apexcharts.css")) {
		print '<link href="' . html_escape($config['url_path'] . "plugins/slowlog/themes/$selected_theme/apexcharts.css") . '" type="text/css" rel="stylesheet">';
	} else {
		print '<link href="' . html_escape($config['url_path'] . "plugins/slowlog/js/apexcharts.css") . '" type="text/css" rel="stylesheet">';
	}

	$id = get_filter_request_var('logid');

	slowlog_request_charts_validation($method);

	$scope_filter = slowlog_get_chart_scope_filter();
	$hide_max     = (get_request_var('hide_max') == 'on');

	slowlog_tabs();

	html_start_box(__('Chart Filters', 'slowlog'), '100%', '', 3, 'center', '');

	slowlog_charts_filter($method, $id);

	html_end_box();

	html_start_box(__('MariaDB/MySQL SlowLog Results - By %s', ucfirst($method), 'slowlog'), '100%', '', 3, 'center', '');

	print '<div style="width:100%;box-sizing:border-box;padding:8px" id="raw_count"></div>';

	$rate_metrics = array(
		'query'    => 'query_time',
		'examined' => 'rows_examined',
		'sent'     => 'rows_sent',
		'affected' => 'rows_affected',
		'bytes'    => 'bytes_sent'
	);

	foreach($rate_metrics as $key => $measure) {
		print '<div style="width:100%;box-sizing:border-box;padding:8px" id="raw_' . $key . '"></div>';
		print '<div style="width:100%;box-sizing:border-box;padding:8px" id="box_' . $key . '"></div>';
	}

	$width  = '100%';
	$height = 400;

	// Chart titles are built from the user-supplied import description, so every dynamic
	// value dropped into this inline <script> block must go through json_encode() with the
	// HEX flags (not raw string concatenation) - otherwise a description containing '"',
	// '</script>', or similar can break out of the JS string/script context.
	$json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

	$output = '';

	$data = slowlog_get_chart_object($method, 'count', $scope_filter);

	$output .= 'renderChart(' .
		'"raw_count",' .
		json_encode($data['title'], $json_flags)       . ','  .
		json_encode($data['yaxislabel'], $json_flags)  . ','  .
		json_encode($data['categories'], $json_flags)  . ','  .
		json_encode($data['values'], $json_flags)      . ','  .
		json_encode($mode, $json_flags)                . ','  .
		json_encode($height, $json_flags)              . ','  .
		json_encode($width, $json_flags)                . ');' . PHP_EOL;

	foreach($rate_metrics as $key => $measure) {
		$data = slowlog_get_chart_object($method, $measure, $scope_filter);

		$output .= 'renderChart(' .
			'"raw_' . $key . '",' .
			json_encode($data['title'], $json_flags)       . ','  .
			json_encode($data['yaxislabel'], $json_flags)  . ','  .
			json_encode($data['categories'], $json_flags)  . ','  .
			json_encode($data['values'], $json_flags)      . ','  .
			json_encode($mode, $json_flags)                . ','  .
			json_encode($height, $json_flags)              . ','  .
			json_encode($width, $json_flags)                . ');' . PHP_EOL;

		$stats = slowlog_get_stats_chart_object($method, $measure, $scope_filter, $hide_max);

		$output .= 'renderBoxChart(' .
			'"box_' . $key . '",' .
			json_encode($stats['title'], $json_flags)       . ','  .
			json_encode($stats['yaxislabel'], $json_flags)  . ','  .
			json_encode($stats['categories'], $json_flags)  . ','  .
			json_encode($stats['box_data'], $json_flags)    . ','  .
			json_encode($stats['p95_data'], $json_flags)    . ','  .
			json_encode($mode, $json_flags)                 . ','  .
			json_encode($height, $json_flags)               . ','  .
			json_encode($width, $json_flags)                 . ');' . PHP_EOL;
	}

	html_end_box(false);

	?>
	<script type="text/javascript">

	function convertLabel(value) {
		var suffix = '';

		if (value >= 1000) {
			value /= 1000;
			suffix = ' K';
		}

		if (value >= 1000) {
			value /= 1000;
			suffix = ' M';
		}

		if (value >= 1000) {
			value /= 1000;
			suffix = ' G';
		}

		if (value >= 1000) {
			value /= 1000;
			suffix = ' T';
		}

		if (value >= 1000) {
			value /= 1000;
			suffix = ' P';
		}

		return value.toFixed(0) + suffix;
	}

	function renderChart(chartid, title, yaxislabel, categories, data, mode, height, width) {
		var options = {
			id: chartid,
			title: {
				text: title,
				align: 'center',
				margin: 10
			},
			theme: {
				mode: mode,
				palette: 'palette7'
			},
			chart: {
				type:    'bar',
				height:  height,
				width:   width,
				redrawOnParentResize: true,
				redrawOnWindowResize: true
			},
			plotOptions: {
				bar: {
					columnWidth: '70%',
					distributed: true
				}
			},
			dropShadow: {
				enabled: true,
				top: 0,
				left: 0,
				blur: 3,
				color: '#222',
				opacity: 0.5
			},
			dataLabels: {
				enabled: false
			},
			legend: {
				show: false,
				position: 'bottom',
				offsetY: -50
			},
			series: [{
				name: '',
				data: data
			}],
			yaxis: {
				show: true,
				minWidth: 40,
				maxWidth: 160,
				title: {
					text: yaxislabel,
                    rotate: -90,
                    offsetX: 5,
                    offsetY: 0,
				},
				labels: {
					formatter: convertLabel
				},
				axisTicks: {
					width: 20
				}
			},
			grid: {
				padding: {
					left: 5,
					right: 5
				}
			},
			xaxis: {
				categories: categories,
				labels: {
					style: {
						fontSize: '12px'
					}
				}
			}
		};

		var chart = new ApexCharts(document.querySelector('#'+chartid), options);

		chart.render();
	}

	function renderBoxChart(chartid, title, yaxislabel, categories, boxData, p95Data, mode, height, width) {
		var options = {
			id: chartid,
			title: {
				text: title,
				align: 'center',
				margin: 10
			},
			theme: {
				mode: mode,
				palette: 'palette7'
			},
			chart: {
				type:    'boxPlot',
				height:  height,
				width:   width,
				redrawOnParentResize: true,
				redrawOnWindowResize: true
			},
			plotOptions: {
				boxPlot: {
					colors: {
						upper: '#5c6bc0',
						lower: '#26a69a'
					}
				}
			},
			dataLabels: {
				enabled: false
			},
			legend: {
				show: true,
				position: 'bottom'
			},
			series: [
				{
					name: 'Distribution',
					type: 'boxPlot',
					data: boxData
				},
				{
					name: 'p95',
					type: 'scatter',
					data: p95Data
				}
			],
			markers: {
				size: [0, 5]
			},
			yaxis: {
				show: true,
				minWidth: 40,
				maxWidth: 160,
				title: {
					text: yaxislabel,
					rotate: -90,
					offsetX: 5,
					offsetY: 0,
				},
				labels: {
					formatter: convertLabel
				},
				axisTicks: {
					width: 20
				}
			},
			grid: {
				padding: {
					left: 5,
					right: 5
				}
			},
			xaxis: {
				type: 'category',
				categories: categories,
				labels: {
					style: {
						fontSize: '12px'
					}
				}
			}
		};

		var chart = new ApexCharts(document.querySelector('#'+chartid), options);

		chart.render();
	}

	$(function() {
		<?php print $output;?>
	});

	</script>
	<?php
}

/*
 * Input validation and session storage for the chart filters (method/table scope
 * multiselect + hide-max checkbox + Top N selectmenu), persisted per chart type (methods
 * vs tables have different scope_key value sets, so they're kept in separate session
 * buckets).
 */
function slowlog_request_charts_validation(string $chart_type): void {
	$filters = array(
		'chart_scope' => array(
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'hide_max' => array(
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'chart_top' => array(
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '10',
			'options' => array('options' => 'slowlog_sanitize_chart_top')
		),
	);

	validate_store_request_vars($filters, 'sess_sl_chart_' . $chart_type);
}

/**
 * splits the persisted comma-separated 'chart_scope' request var into a clean array
 *
 * @return array<int, string>
 */
function slowlog_get_chart_scope_filter(): array {
	$raw = get_request_var('chart_scope');

	if (trim($raw) == '') {
		return array();
	}

	return array_values(array_filter(array_map('trim', explode(',', $raw)), function($value) {
		return $value !== '';
	}));
}

/*
 * One-line filter row above the By Method/By Table charts: a multiselect of the
 * available methods (or tables) to restrict which categories are charted, a
 * checkbox to swap the box-whisker's max value for its p95 (the true max is often
 * a rare outlier that flattens the rest of the box on the chart's Y axis), and the
 * usual Go/Clear buttons. Filter state is persisted via
 * slowlog_request_charts_validation()/validate_store_request_vars() same as every
 * other filter in this plugin.
 */
function slowlog_charts_filter(string $method, int $id): void {
	$selected = slowlog_get_chart_scope_filter();

	if ($method == 'tables') {
		$scope_label = __('Select Table', 'slowlog');

		$scope_items = array_column(db_fetch_assoc_prepared('SELECT DISTINCT table_name AS value
			FROM plugin_slowlog_details_tables
			WHERE logid = ?
			ORDER BY table_name',
			array($id)), 'value');

		$scope_items[] = 'others';
	} else {
		$scope_label = __('Select Method', 'slowlog');

		$scope_items = array_column(db_fetch_assoc_prepared('SELECT method AS value
			FROM plugin_slowlog_methods
			ORDER BY method',
			array()), 'value');
	}

	?>
	<tr class='even'>
		<td>
			<form id='chartsfilter'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print html_escape($scope_label);?>
						</td>
						<td>
							<select id='chart_scope' multiple size='6'>
								<?php
								foreach ($scope_items as $value) {
									print '<option value="' . html_escape($value) . '"' . (in_array($value, $selected, true) ? ' selected' : '') . '>' . html_escape($value) . '</option>';
								}
								?>
							</select>
						</td>
						<td>
							<label>
								<input type='checkbox' id='hide_max'<?php print (get_request_var('hide_max') == 'on' ? ' checked' : '');?>>
								<?php print __('Hide Max (use p95 instead)', 'slowlog');?>
							</label>
						</td>
						<td>
							<?php print __('Top', 'slowlog');?>
						</td>
						<td>
							<select id='chart_top'>
								<?php
								foreach (SLOWLOG_CHART_TOP_OPTIONS as $top_option) {
									print '<option value="' . html_escape($top_option) . '"' . (get_request_var('chart_top') == $top_option ? ' selected' : '') . '>' . html_escape($top_option) . '</option>';
								}
								?>
							</select>
						</td>
						<td>
							<span>
								<input class='button_go' type='submit' onClick='applyChartsFilter()' name='go' value='<?php print __('Go', 'slowlog');?>'>
								<input class='button_clear' type='button' onClick='clearChartsFilter()' name='clear' value='<?php print __('Clear', 'slowlog');?>'>
							</span>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript'>
			function applyChartsFilter() {
				var strURL = 'slowlog.php?action=<?php print $method == 'tables' ? 'tables' : 'methods';?>&header=false&logid=<?php print (int) $id;?>';

				strURL += '&chart_scope=' + ($('#chart_scope').val() || []).join(',');
				strURL += '&hide_max=' + ($('#hide_max').is(':checked') ? 'on' : '');
				strURL += '&chart_top=' + $('#chart_top').val();

				loadPageNoHeader(strURL);
			}

			function clearChartsFilter() {
				var strURL = 'slowlog.php?header=false&reset=true&action=<?php print $method == 'tables' ? 'tables' : 'methods';?>&logid=<?php print (int) $id;?>';

				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#chart_scope').multiselect({
					menuHeight: $(window).height() * .7,
					menuWidth: 'auto',
					noneSelectedText: '<?php print html_escape($scope_label);?>',
					selectedText: function(numChecked, numTotal, checkedItems) {
						return numChecked + ' <?php print __esc('Selected', 'slowlog');?>';
					},
					checkAllText: '<?php print __esc('All', 'slowlog');?>',
					uncheckAllText: '<?php print __esc('None', 'slowlog');?>',
					close: function(event, ui) {
						applyChartsFilter();
					},
					open: function(event, ui) {
						$("input[type='search']:first").focus();
					}
				}).multiselectfilter({
					label: '<?php print __esc('Search', 'slowlog');?>',
					placeholder: '<?php print __esc('Enter keyword', 'slowlog');?>',
					width: 200
				});

				$('#hide_max').on('change', function() {
					applyChartsFilter();
				});

				$('#chart_top').on('change', function() {
					applyChartsFilter();
				});

				$('#chartsfilter').submit(function(event) {
					event.preventDefault();
					applyChartsFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php
}

function slowlog_view_query(): void {
	global $config;

	$entry = db_fetch_row_prepared('SELECT *
		FROM plugin_slowlog_details AS sld
		WHERE logid = ?
		AND logentry = ?',
		array(get_filter_request_var('logid'), get_filter_request_var('logentry')));

	slowlog_tabs();

	html_start_box(__('MariaDB/MySQL SlowLog Query Details', 'slowlog'), '100%', '', 3, 'center', '');

	form_alternate_row();

	print '<td>' . __('Date', 'slowlog') . '</td><td>'  . html_escape($entry['date'])       . '</td>';
	print '<td>' . __('User', 'slowlog') . '</td><td>'  . html_escape($entry['user'])       . '</td>';
	print '<td>' . __('Host', 'slowlog') . '</td><td>'  . html_escape($entry['host'])       . '</td>';
	print '<td>' . __('IP', 'slowlog') . '</td><td>'    . html_escape($entry['ip_address']) . '</td>';

	form_end_row();

	form_alternate_row();

	print '<td>' . __('Query Time', 'slowlog') . '</td><td>'    . number_format_i18n($entry['query_time'])    . '</td>';
	print '<td>' . __('Lock Time', 'slowlog') . '</td><td>'     . number_format_i18n($entry['lock_time'])     . '</td>';
	print '<td>' . __('Rows Sent', 'slowlog') . '</td><td>'     . number_format_i18n($entry['rows_sent'])     . '</td>';
	print '<td>' . __('Rows Examined', 'slowlog') . '</td><td>' . number_format_i18n($entry['rows_examined']) . '</td>';

	form_end_row();

	form_alternate_row();

	print '<td>' . __('Rows Affected', 'slowlog') . '</td><td>' . number_format_i18n($entry['rows_affected']) . '</td>';
	print '<td>' . __('Bytes Sent', 'slowlog') . '</td><td>'    . number_format_i18n($entry['bytes_sent'])    . '</td>';
	print '<td colspan="4"></td>';

	form_end_row();
	html_end_box(false);

	html_start_box(__('Original Query', 'slowlog'), '100%', '', 3, 'center', '');

	form_alternate_row();

	$oquery = str_replace('","', '", "', (string) $entry['oquery']);
	$oquery = str_replace("','", "', '", $oquery);

	print "<td><pre style='white-space:pre-wrap'>" . $oquery . '</pre></td>';

	form_end_row();

	html_end_box(false);
}

function slowlog_request_summary_validation(): void {
	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
		),
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'description',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
		),
	);

	validate_store_request_vars($filters, 'sess_sl_sum');
	/* ================= input validation ================= */
}

function slowlog_view(): void {
	global $config, $actions;

	slowlog_request_summary_validation();

	$sql_where   = '';
	$sql_params  = array();
	$sql_orderby = get_order_string();

	if (get_request_var('filter') != '') {
		// $sql_where is always '' here (this is the only WHERE-building clause in this
		// function), so the previous ternary's "AND" branch was always unreachable dead
		// logic - simplified to the one condition that ever actually ran.
		$sql_where = 'WHERE (description LIKE ?)';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	$entries = db_fetch_assoc_prepared("SELECT *, UNIX_TIMESTAMP(end_time)-UNIX_TIMESTAMP(start_time) AS duration
		FROM plugin_slowlog
		$sql_where
		$sql_orderby",
		$sql_params);

	slowlog_tabs();

	html_start_box(__('MariaDB/MySQL SlowLog File Filters', 'slowlog'), '100%', '', 3, 'center', 'slowlog.php?action=edit');
	filter();
	html_end_box();

	form_start('slowlog.php', 'chk');

	html_start_box('', '100%', '', 3, 'center', '');

	$display_text = array(
		'nosort' => array(
			'display' => __('Actions', 'slowlog'),
		),
		'description' => array(
			'display' => __('Description', 'slowlog'),
			'sort'    => 'ASC'
		),
		'import_status' => array(
			'display' => __('Import Status', 'slowlog'),
			'sort'    => 'DESC'
		),
		'import_text_status' => array(
			'display' => __('Status String', 'slowlog'),
			'sort'    => 'DESC'
		),
		'logid' => array(
			'display' => __('ID', 'slowlog'),
			'sort'    => 'ASC',
			'align'   => 'right'
		),
		'import_date' => array(
			'display' => __('Imported', 'slowlog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'import_lines' => array(
			'display' => __('Lines', 'slowlog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'duration' => array(
			'display' => __('Duration', 'slowlog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'start_time' => array(
			'display' => __('Start Date', 'slowlog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'end_time' => array(
			'display' => __('End Date', 'slowlog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		)
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'slowlog.php?action=select');

	$has_pending = false;

	if (cacti_sizeof($entries)) {
		foreach ($entries as $entry) {
			if (empty($entry['import_status'])) {
				$has_pending = true;
			}

			$html = "<a class='pic' href='slowlog.php?action=methods&reset=true&logid=" . $entry['logid'] . "'>
				<i class='fas fa-poll deviceUp' title='View Methods'></i></a>
				<a class='pic' href='" . html_escape($config['url_path'] . 'plugins/slowlog/slowlog.php?action=tables&reset=true&logid=' . $entry["logid"]) . "'>
				<i class='fas fa-poll deviceRecovering' title='View Tables'></i></a>
				<a class='pic' href='" . html_escape($config['url_path'] . 'plugins/slowlog/slowlog.php?action=details&reset=true&logid=' . $entry["logid"]) . "'>
				<i class='fas fa-search-plus' title='View Details'></i></a>";

			form_alternate_row('line_' . $entry['logid']);

			if (empty($entry['import_status'])) {
				$status = '<span class="deviceRecovering">' . __('Pre-Processing', 'slowlog') . '</span>';
			} elseif ($entry['import_status'] == 1) {
				$status = '<span class="deviceRecovering">' . __('Post-Processing', 'slowlog') . '</span>';
			} elseif ($entry['import_status'] == 2) {
				$status = '<span class="deviceUp">' . __('Complete', 'slowlog') . '</span>';
			} elseif ($entry['import_status'] == 3) {
				$status = '<span class="deviceDown">' . __('Bad File Format', 'slowlog') . '</span>';
			} else {
				$status = '<span class="deviceDown">' . __('Unknown', 'slowlog') . '</span>';
			}

			form_selectable_cell($html, $entry['logid'], '1%');
			form_selectable_cell(filter_value($entry['description'], get_request_var('filter')), $entry['logid']);
			form_selectable_cell($status, $entry['logid']);
			form_selectable_cell($entry['import_text_status'], $entry['logid']);
			form_selectable_cell($entry['logid'], $entry['logid'], '', 'right');
			form_selectable_cell($entry['import_date'], $entry['logid'], '', 'right');
			form_selectable_cell(number_format($entry['import_lines']), $entry['logid'], '', 'right');
			form_selectable_cell(get_daysfromtime($entry['duration']), $entry['logid'], '', 'right');
			form_selectable_cell($entry['start_time'], $entry['logid'], '', 'right');
			form_selectable_cell($entry['end_time'], $entry['logid'], '', 'right');
			form_checkbox_cell($entry['description'], $entry['logid']);

			form_end_row();
		}
	} else {
		print '<tr><td colspan="' . (cacti_sizeof($display_text) + 1) . '"><em>No MySQL/MariaDB Imported Slow Logs</em></td></tr>';
	}

	html_end_box(false);

	// At least one import is still Pre-Processing (the background ingest worker hasn't
	// finished) - use Cacti's built-in page refresh timer so the status column updates
	// without a manual refresh. setupPageTimeout() clears/recreates this timer on every
	// AJAX navigation, so it can't stack or fire against a page the user has since left.
	if ($has_pending) {
		?>
		<script type="text/javascript">
		refreshIsLogout = false;
		refreshPage     = <?php print json_encode(sanitize_uri($_SERVER['REQUEST_URI'])); ?>;
		refreshMSeconds = 5000;
		refreshFunction = '';
		setupPageTimeout();
		</script>
		<?php
	}

	draw_actions_dropdown($actions);

	form_end();
}

/**
 * slowlog_save_button - draws a (save|create) and cancel button at the bottom of
 * an html edit form
 * @param $force_type - if specified, will force the 'action' button to be either
 *                      'save' or 'create'. otherwise this field should be
 *                      properly auto-detected.
 */
function slowlog_save_button(string $cancel_action = '', string $action = 'save', string $force_type = '', string $key_field = 'id'): void {
	global $config;

	if (substr_count($cancel_action, '.php')) {
		$caction = $cancel_action;
		$calt  = __esc('Return', 'slowlog');
		$sname = 'save';
		$salt  = __esc('Save', 'slowlog');
	} else {
		$caction = 'slowlog.php';
		$calt    = __esc('Cancel', 'slowlog');

		if ((empty($force_type)) || ($cancel_action == 'return')) {
			if ($action == 'import') {
				$sname = 'import';
				$salt  = __esc('Import', 'slowlog');
			} elseif (empty($_GET[$key_field])) {
				$sname = 'create';
				$salt  = __esc('Create', 'slowlog');
			} else {
				$sname = 'save';
				$salt  = __esc('Save', 'slowlog');
			}

			if ($cancel_action == 'return') {
				$calt   = __esc('Return', 'slowlog');
				$action = 'save';
			} else {
				$calt   = __esc('Cancel', 'slowlog');
			}
		} elseif ($force_type == 'save') {
			$sname = 'save';
			$salt  = __esc('Save', 'slowlog');
		} elseif ($force_type == 'create') {
			$sname = 'create';
			$salt  = __esc('Create', 'slowlog');
		} elseif ($force_type == 'import') {
			$sname = 'import';
			$salt  = __esc('Import', 'slowlog');
		} else {
			// $force_type was passed a value other than 'save'/'create'/'import' - fall back
			// to the same default the main branch above uses, rather than leaving $sname/$salt
			// undefined (this was a latent "might not be defined" bug for any unrecognized
			// $force_type value).
			$sname = 'save';
			$salt  = __esc('Save', 'slowlog');
		}
	}

	?>
	<table>
		<tr>
			<td class='saveRow'>
				<input type='hidden' name='action' value='<?php print $action;?>'>
				<input type='button' value='<?php print $calt;?>' onClick='window.location.assign('<?php print htmlspecialchars($caction);?>')' name='cancel'>
				<input type='submit' value='<?php print $salt;?>' name='<?php print $sname;?>'>
			</td>
		</tr>
	</table>
	</form>
	<?php
}

function filter(): void {
	global $config;

	?>
	<tr class='even'>
		<td>
			<form id='summary'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'slowlog');?>
						</td>
						<td>
							<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
						</td>
						<td>
							<span>
								<input class='button_go' type='submit' onClick='applyFilter()' name='go' value='Go'>
								<input class='button_clear' type='button' onClick='clearFilter()' name='clear' value='Clear'>
							</span>
						</td>
					</tr>
				</table>
			</td>
			<td>
				<script type='text/javascript'>
				function applyFilter() {
					var strURL = '?action=select&header=false&filter=' + $('#filter').val();
					loadPageNoHeader(strURL);
				}

				function clearFilter() {
					strURL = '?header=false&action=select&clear=true';
					loadPageNoHeader(strURL);
				}

				$(function() {
					$('#summary').submit(function(event) {
						event.preventDefault();
						applyFilter();
					});
				});
				</script>
			</td>
		</form>
	</tr>
	<?php
}

function slowlog_details_filter(): void {
	global $config, $item_rows, $graph_timespans, $graph_timeshifts;

	?>
	<tr class='even'>
		<td>
			<form id='details'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('LogFile', 'slowlog');?>
						</td>
						<td>
							<select id='logid' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var('logid') == '-1') {?> selected<?php }?>><?php print __('Any', 'slowlog');?></option>
								<?php
								$logids = db_fetch_assoc_prepared('SELECT logid, CONCAT(description) AS name
									FROM plugin_slowlog
									ORDER BY name',
									array());

								if (cacti_sizeof($logids)) {
									foreach ($logids as $l) {
										print '<option value="' . $l['logid'] . '"' . (get_request_var('logid') == $l['logid'] ? ' selected':'') . '>' . html_escape($l['name']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Method', 'slowlog');?>
						</td>
						<td>
							<select id='mmethod' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var('mmethod') == '-1') {?> selected<?php }?>><?php print __('Any', 'slowlog');?></option>
								<option value='-2'<?php if (get_request_var('mmethod') == '-2') {?> selected<?php }?>><?php print __('N/A', 'slowlog');?></option>
								<?php
								$methods = db_fetch_assoc_prepared('SELECT *
									FROM plugin_slowlog_methods
									ORDER BY method',
									array());

								if (cacti_sizeof($methods)) {
									foreach ($methods as $m) {
										print '<option value="' . $m['methodid'] . '"' . (get_request_var('mmethod') == $m['methodid'] ? ' selected':'') . '>' . html_escape($m['method']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Tables', 'slowlog');?>
						</td>
						<td>
							<select id='table' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var('table') == '-1') {?> selected<?php }?>><?php print __('Any', 'slowlog');?></option>
								<option value='-2'<?php if (get_request_var('table') == '-2') {?> selected<?php }?>><?php print __('Others', 'slowlog');?></option>
								<option value='-3'<?php if (get_request_var('table') == '-3') {?> selected<?php }?>><?php print __('N/A', 'slowlog');?></option>
								<?php
								if (get_request_var('logid') > 0) {
									$tables = db_fetch_assoc_prepared('SELECT DISTINCT table_name
										FROM plugin_slowlog_details_tables
										WHERE logid = ?
										ORDER BY table_name',
										array(get_request_var('logid')));
								} else {
									$tables = db_fetch_assoc_prepared('SELECT DISTINCT table_name
										FROM plugin_slowlog_details_tables
										ORDER BY table_name',
										array());
								}

								if (cacti_sizeof($tables)) {
									foreach ($tables as $t) {
										print '<option value="' . $t['table_name'] . '"' . (get_request_var('table') == $t['table_name'] ? ' selected':'') . '>' . html_escape($t['table_name']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Rows', 'slowlog');?>
						</td>
						<td>
							<select id='rows' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var_request('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'slowlog');?></option>
								<?php
								if (cacti_sizeof($item_rows)) {
									foreach ($item_rows as $key => $value) {
										print "<option value='" . $key . "'" . (get_request_var_request('rows') == $key ? ' selected':'') . '>' . $value . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<span>
								<input class='button_go' type='submit' onClick='applyFilter()' name='go' value='<?php print __('Go', 'slowlog');?>'>
								<input class='button_clear' type='button' onClick='clearFilter()' name='clear' value='<?php print __('Clear', 'slowlog');?>'>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'slowlog');?>
						</td>
						<td>
							<input type='text' id='filter' size='40' value='<?php print html_escape_request_var('filter');?>'>
						</td>
						<td>
							<?php print __('User', 'slowlog');?>
						</td>
						<td>
							<select id='myuser' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var('user') == '-1') {?> selected<?php }?>><?php print __('Any', 'slowlog');?></option>
								<?php
								if (get_request_var('logid') > 0) {
									$users = db_fetch_assoc_prepared('SELECT DISTINCT user
										FROM plugin_slowlog_details
										WHERE logid = ?
										ORDER BY user',
										array(get_request_var('logid')));
								} else {
									$users = db_fetch_assoc_prepared('SELECT DISTINCT user
										FROM plugin_slowlog_details
										ORDER BY user',
										array());
								}

								if (cacti_sizeof($users)) {
									foreach ($users as $u) {
										print '<option value="' . html_escape($u['user']) . '"' . (get_request_var('user') == $u['user'] ? ' selected':'') . '>' . html_escape($u['user']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Host', 'slowlog');?>
						</td>
						<td>
							<select id='host' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var('host') == '-1') {?> selected<?php }?>><?php print __('Any', 'slowlog');?></option>
								<?php
								if (get_request_var('logid') > 0) {
									$hosts = db_fetch_assoc_prepared('SELECT DISTINCT host
										FROM plugin_slowlog_details
										WHERE logid = ?
										ORDER BY host',
										array(get_request_var('logid')));
								} else {
									$hosts = db_fetch_assoc_prepared('SELECT DISTINCT host
										FROM plugin_slowlog_details
										ORDER BY host',
										array());
								}

								if (cacti_sizeof($hosts)) {
									foreach ($hosts as $h) {
										print '<option value="' . html_escape($h['host']) . '"' . (get_request_var('host') == $h['host'] ? ' selected':'') . '>' . html_escape($h['host']) . '</option>';
									}
								}
								?>
							</select>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr id='timespan'>
						<td>
							<?php print __('From', 'slowlog');?>
						</td>
						<td>
							<span>
								<input type='text' class='ui-state-default ui-corner-all' id='date1' size='18' value='<?php print html_escape_request_var('date1');?>'>
								<i id='startDate' class='calendar fa fa-calendar-alt' title='<?php print __esc('Start Date Selector', 'slowlog');?>'></i>
							</span>
						</td>
						<td>
							<?php print __('To', 'slowlog');?>
						</td>
						<td>
							<span>
								<input type='text' class='ui-state-default ui-corner-all' id='date2' size='18' value='<?php print html_escape_request_var('date2');?>'>
								<i id='endDate' class='calendar fa fa-calendar-alt' title='<?php print __esc('End Date Selector', 'slowlog');?>'></i>
							</span>
						</td>
						<td>
							<span>
								<i class='shiftArrow fa fa-backward' onClick='timeshiftGraphFilterLeft()' title='<?php print __esc('Shift Time Backward', 'slowlog');?>'></i>
								<select id='predefined_timeshift' title='<?php print __esc('Define Shifting Interval', 'slowlog');?>'>
									<?php
									$start_val = 1;
									$end_val = cacti_sizeof($graph_timeshifts)+1;
									if (cacti_sizeof($graph_timeshifts)) {
										for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
											print "<option value='$shift_value'"; if ($_SESSION['sess_current_timeshift'] == $shift_value) { print ' selected'; } print '>' . html_escape($graph_timeshifts[$shift_value]) . '</option>';
										}
									}
									?>
								</select>
								<i class='shiftArrow fa fa-forward' onClick='timeshiftGraphFilterRight()' title='<?php print __esc('Shift Time Forward', 'slowlog');?>'></i>
							</span>
						</td>
						<td style='display:none;'>
							<select id='predefined_timespan' onChange='applyGraphTimespan()'>
								<?php
								$graph_timespans = array_merge(array(GT_CUSTOM => __('Custom', 'slowlog')), $graph_timespans);

								$start_val = 0;
								$end_val   = cacti_sizeof($graph_timespans);

								if (cacti_sizeof($graph_timespans)) {
									foreach($graph_timespans as $value => $text) {
										print "<option value='$value'"; if ($_SESSION['sess_current_timespan'] == $value) { print ' selected'; } print '>' . html_escape($text) . '</option>';
									}
								}
								?>
							</select>
						</td>
				</table>
			</form>
			<script type='text/javascript'>
			var date1Open = false;
			var date2Open = false;
			var pageTab   = <?php print json_encode(get_request_var('tab'));?>;
			var logid     = <?php print (int)get_request_var('logid');?>;

			function applyFilter() {
				var strURL = 'slowlog.php?action=details&logid=' + logid;

				strURL += '&header=false';
				strURL += '&filter=' + $('#filter').val();
				strURL += '&rows=' + $('#rows').val();
				strURL += '&logid=' + $('#logid').val();
				strURL += '&mmethod=' + $('#mmethod').val();
				strURL += '&table=' + $('#table').val();
				strURL += '&user=' + $('#myuser').val();
				strURL += '&host=' + $('#host').val();
				strURL += '&date1=' + $('#date1').val();
				strURL += '&date2=' + $('#date2').val();

				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				var strURL = 'slowlog.php?header=false&reset=true&action=details&logid=' + logid;

				loadPageNoHeader(strURL);
			}

			function timeshiftFilterLeft() {
				var strURL = 'slowlog.php?tab='+pageTab+'&header=false&logid=' + logid;

				strURL += '&shift_left=true';
				strURL += '&date1='+$('#date1').val();
				strURL += '&date2='+$('#date2').val();
				strURL += '&predefined_timeshift='+$('#predefined_timeshift').val();

				loadPageNoHeader(strURL);
			}

			function timeshiftFilterRight() {
				var strURL  = 'slowlog.php?tab='+pageTab+'&header=false&logid=' + logid;

				strURL += '&shift_right=true';
				strURL += '&date1='+$('#date1').val();
				strURL += '&date2='+$('#date2').val();
				strURL += '&predefined_timeshift='+$('#predefined_timeshift').val();

				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#details').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});

				$('#startDate').click(function() {
					if (date1Open) {
						date1Open = false;
						$('#date1').datetimepicker('hide');
					} else {
						date1Open = true;
						$('#date1').datetimepicker('show');
					}
				});

				$('#endDate').click(function() {
					if (date2Open) {
						date2Open = false;
						$('#date2').datetimepicker('hide');
					} else {
						date2Open = true;
						$('#date2').datetimepicker('show');
					}
				});

				$('#date1').datetimepicker({
					minuteGrid: 10,
					stepMinute: 1,
					showAnim: 'slideDown',
					numberOfMonths: 1,
					timeFormat: 'HH:mm',
					dateFormat: 'yy-mm-dd',
					showButtonPanel: false
				});

				$('#date2').datetimepicker({
					minuteGrid: 10,
					stepMinute: 1,
					showAnim: 'slideDown',
					numberOfMonths: 1,
					timeFormat: 'HH:mm',
					dateFormat: 'yy-mm-dd',
					showButtonPanel: false
				});
			});
			</script>
		</td>
	</tr>
	<?php
}
