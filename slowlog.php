<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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
include_once('./plugins/slowlog/slowlog_functions.php');

ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');

$actions = array(
	1 => 'Delete'
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
		slowlog_viewchart('methods');

		break;
	case 'viewtables':
		slowlog_viewchart('tables');

		break;
	case 'edit':
		general_header();
		slowlog_import();
		bottom_footer();

		break;
	case 'methods':
		general_header();
		slowlog_view_charts('methods');
		bottom_footer();

		break;
	case 'tables':
		general_header();
		slowlog_view_charts('tables');
		bottom_footer();

		break;
	case 'details':
		general_header();
		slowlog_view_details();
		bottom_footer();

		break;
	case 'query':
		general_header();
		slowlog_view_query();
		bottom_footer();

		break;
	default:
		general_header();
		slowlog_view();
		bottom_footer();

		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if (isset($_POST['save_component_slowlog'])) {
		$logid = api_slowlog_save($_POST['logid'], $_POST['description'], $_POST['length']);

		header('Location: slowlog.php?action=methods&logid=' . (empty($logid) ? $_POST['logid'] : $logid));

		exit;
	}

	if (isset($_POST['save_component_import'])) {
		if (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
			/* file upload */
			$csv_data = file($_FILES['import_file']['tmp_name']);

			/* obtain debug information if it's set */
			$debug_data = import_logfile(
				$_FILES['import_file']['tmp_name'],
				get_nfilter_request_var('description'),
				get_nfilter_request_var('length'),
				get_nfilter_request_var('table_names'),
				get_nfilter_request_var('usecacti')
			);

			if (cacti_sizeof($debug_data) > 0) {
				$_SESSION['import_debug_info'] = $debug_data;
			}
		} else {
			header('Location: slowlog.php');
			exit;
		}

		header('Location: slowlog.php');
		exit;
	}
}

function form_actions() {
	global $config, $actions;

	/* if we are to save this form, instead of display it */
	if (isset($_POST['selected_items'])) {
		$selected_items = unserialize(stripslashes($_POST['selected_items']));

		if ($_POST['drp_action'] == '1') { /* delete */
			for ($i=0; $i<count($selected_items); $i++) {
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

			$slowlog_list   .= '<li>' . $slowlog_info . '</li>';
			$slowlog_array[] = $matches[1];
		}
	}

	general_header();

	form_start('slowlog.php');

	html_start_box($actions[$_POST['drp_action']], '60%', '', '3', 'center', '');

	if ($_POST['drp_action'] == '1') { /* delete */
		print "<tr class='even'>
			<td class='textArea'>
				<p>Are you sure you want to delete the following MySQL Slow Log entries?</p>
				<ul>$slowlog_list</ul>";
				print "</td></tr>
			</td>
		</tr>";
	}

	if (!isset($slowlog_array)) {
		print "<tr><td><span class='textError'>You must select at least one Slowlog record.</span></td></tr>\n";
		$save_html = '';
	} else {
		$save_html = "<input type='submit' name='save' value='Yes'>";
	}

	print "	<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($slowlog_array) ? serialize($slowlog_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . $_POST['drp_action'] . "'>" . (strlen($save_html) ? "
			<input type='submit' name='cancel' value='No'>
			$save_html" : "<input type='submit' name='cancel' value='Return'>") . "
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function api_slowlog_save($logid, $description, $length) {
	return true;

	$save['logid']            = $logid;
	$save['description']      = form_input_validate($description, 'description', '', false, 3);

	$logid = 0;
	if (!is_error_message()) {
		$logid = sql_save($save, 'slowlog', 'logid');

		if ($logid) {
			raise_message(1);
		} else {
			raise_message(2);
		}
	}

	return $logid;
}

function api_slowlog_remove($logid) {
	db_execute_prepared('DELETE FROM plugin_slowlog WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details_tables WHERE logid = ?', array($logid));
	db_execute_prepared('DELETE FROM plugin_slowlog_details_methods WHERE logid = ?', array($logid));
}

function slowlog_import() {
	global $config;

	$upload_max_filesize = ini_get('upload_max_filesize') . 'Bytes';
	$post_max_size       = ini_get('post_max_size') . 'Bytes';

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
		'usecacti' => array(
			'method' => 'checkbox',
			'friendly_name' => __('Use This Cacti Database', 'slowlog'),
			'description' => __('Slowlog needs to know the tables names used in your slowlog.  If it\'s the Cacti database on this system, simply check the checkbox.  Otherwise, you will have to paste the output as show below under \'Tables of Interest\', or leave blank to have the Post-process detect the Tables.', 'slowlog'),
			'value' => '',
		),
		'table_names' => array(
			'method' => 'textarea',
			'friendly_name' => __('Tables of Interest'),
			'description' => __('Please provide a space delimited list of tables that you are interested in.  If you provide this list of tables the MySQL Slow Query Log will be scanned for these entries and more details statistics will be provided.  In Linux/UNIX, you may obtain a list of tables by using the following command:<br><br> print `mysql -u<i><b>user</b></i> -p<i><b>password</b></i> -e "show tables" <i><b>database</b></i> | grep -v Tables_in` | tr \'\n\' \' \'<br><br>The values of \'<i><b>user</b></i>\', \'<i><b>password</b></i>\', and \'<i><b>database</b></i>\' are replaced with your values.'),
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
			'description' => __(' The maximum size you can post to the Apache server is set to the value on the right.  If you have MySQL Slow logs larger than this value, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>post_max_size</i></b> and increase its value.  After which you must restart Apache.', 'slowlog'),
			'value'  => $post_max_size
		)
	);

	form_start('slowlog.php?action=import', 'import', true);

	html_start_box('Import MySQL Slowlog', '100%', '', '3', 'center', '');

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
}

function slowlog_get_chart_object($chart_type, $measure) {
	global $config;

	$id = get_filter_request_var('logid');

	$description = db_fetch_cell_prepared('SELECT description
		FROM plugin_slowlog
		WHERE logid = ?',
		array($id));

	if ($chart_type != 'tables') {
		$details = db_fetch_assoc_prepared("SELECT
			sm.method AS type,
			COUNT(*) AS count,
			SUM(query_time) AS query_time,
			SUM(lock_time) AS lock_time,
			SUM(rows_examined) AS rows_examined,
			SUM(rows_sent) AS rows_sent,
			SUM(rows_affected) AS rows_affected,
			SUM(bytes_sent) AS bytes_sent
			FROM plugin_slowlog_details_methods AS dm
			INNER JOIN plugin_slowlog_details AS d
			ON dm.logid = d.logid
			AND dm.logentry = d.logentry
			INNER JOIN plugin_slowlog_methods AS sm
			ON dm.methodid = sm.methodid
			WHERE d.logid = ?
			GROUP BY sm.methodid
			ORDER BY $measure DESC",
			array($id));
	} else {
		$details = db_fetch_assoc_prepared("SELECT *
			FROM (
				SELECT table_name AS type,
					COUNT(*) AS count,
					SUM(query_time) AS query_time,
					SUM(lock_time) AS lock_time,
					SUM(rows_examined) AS rows_examined,
					SUM(rows_sent) AS rows_sent,
					SUM(rows_affected) AS rows_affected,
					SUM(bytes_sent) AS bytes_sent
				FROM plugin_slowlog_details_tables AS dt
				INNER JOIN plugin_slowlog_details AS d
				ON dt.logid=d.logid AND dt.logentry=d.logentry
				WHERE d.logid = ?
				GROUP BY table_name
				UNION ALL
				SELECT 'others' AS type,
					COUNT(*) AS count,
					SUM(query_time) AS query_time,
					SUM(lock_time) AS lock_time,
					SUM(rows_examined) AS rows_examined,
					SUM(rows_sent) AS rows_sent,
					SUM(rows_affected) AS rows_affected,
					SUM(bytes_sent) AS bytes_sent
				FROM plugin_slowlog_details AS d
				LEFT JOIN plugin_slowlog_details_tables AS dt
				ON dt.logid=d.logid AND dt.logentry=d.logentry
				WHERE dt.table_name IS NULL
				AND d.logid = ?
				GROUP BY table_name
			) AS fish
			ORDER BY $measure DESC LIMIT 10",
			array($id, $id));
	}

	$measures = array(
		'count' => array(
			'unit'   => __esc('Queries', 'slowlog'),
			'suffix' => __esc('Total Queries', 'slowlog')
		),
		'rows_sent' => array(
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Returned', 'slowlog')
		),
		'rows_examined' => array(
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Examined', 'slowlog')
		),
		'lock_time' => array(
			'unit'   => __esc('Seconds', 'slowlog'),
			'suffix' => __esc('Lock Seconds', 'slowlog')
		),
		'query_time' => array(
			'unit'   => __esc('Seconds', 'slowlog'),
			'suffix' => __esc('Query Seconds', 'slowlog')
		),
		'rows_affected' => array(
			'unit'   => __esc('Rows', 'slowlog'),
			'suffix' => __esc('Rows Affected', 'slowlog')
		),
		'bytes_sent' => array(
			'unit'   => __esc('Bytes', 'slowlog'),
			'suffix' => __esc('Bytes Sent', 'slowlog')
		)
	);

	// ApexCharts
	if (cacti_sizeof($details)) {
		$categories = array();
		$values     = array();

		foreach($details as $entry) {
			$categories[] = $entry['type'];
			$values[]     = $entry[$measure];
		}

		$title = $description . ' [ ' . $measures[$measure]['suffix'] . ' ]';

		return array(
			'title'      => $title,
			'categories' => $categories,
			'values'     => $values,
			'yaxislabel' => $measures[$measure]['unit']
		);
	} else {
		return array();
	}
}

function slowlog_request_validation() {
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
}

function slowlog_view_details() {
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

	if (get_request_var('mmethod') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' slm.methodid = ?';

		$sql_params[] = get_request_var('mmethod');
	}

	if (get_request_var('user') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sld.user = ?';

		$sql_params[] = get_request_var('user');
	}

	if (get_request_var('host') != '-1') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sld.host = ?';

		$sql_params[] = get_request_var('host');
	}

	if (get_request_var('table') != '-1' && get_request_var('table') != '-2') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sldt.table_name = ?';

		$sql_params[] = get_request_var('table');
	} elseif (get_request_var('table') == '-2') {
		$sql_where .= ($sql_where != '' ? ' AND':'WHERE') . ' sldt.table_name IS NULL';
	}

	$results = db_fetch_assoc_prepared("SELECT DISTINCT sld.*, slm.method, sldt.table_name
		FROM plugin_slowlog_details AS sld
		LEFT JOIN plugin_slowlog_details_tables AS sldt
		ON sld.logid = sldt.logid
		AND sld.logentry = sldt.logentry
		LEFT JOIN plugin_slowlog_details_methods AS sldm
		ON sld.logid = sldm.logid
		AND sld.logentry = sldm.logentry
		LEFT JOIN plugin_slowlog_methods AS slm
		ON sldm.methodid = slm.methodid
		$sql_where
		$sql_orderby
		$sql_limit",
		$sql_params);

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_slowlog_details AS sld
		LEFT JOIN plugin_slowlog_details_tables AS sldt
		ON sld.logid = sldt.logid
		AND sld.logentry = sldt.logentry
		LEFT JOIN plugin_slowlog_details_methods AS sldm
		ON sld.logid = sldm.logid
		AND sld.logentry = sldm.logentry
		LEFT JOIN plugin_slowlog_methods AS slm
		ON sldm.methodid = slm.methodid
		$sql_where",
		$sql_params);

	slowlog_tabs();

	$display_text = array(
		'nosort0' => array(
			'display' => 'Actions'
		),
		'table_name' => array(
			'display' => 'Table Name',
			'sort' => 'ASC'
		),
		'method' => array(
			'display' => 'Method',
			'sort' => 'ASC'
		),
		'date' => array(
			'display' => 'Date',
			'sort' => 'ASC'
		),
		'user' => array(
			'display' => 'User',
			'sort' => 'ASC'
		),
		'host' => array(
			'display' => 'Host',
			'sort' => 'ASC'
		),
		'query_time' => array(
			'display' => 'Query Time',
			'sort' => 'DESC',
			'align' => 'right'
		),
		'lock_time' => array(
			'display' => 'Lock Time',
			'sort' => 'DESC',
			'align' => 'right'
		),
		'rows_sent' => array(
			'display' => 'Send',
			'sort' => 'DESC',
			'align' => 'right'
		),
		'rows_examined' => array(
			'display' => 'Examined',
			'sort' => 'DESC',
			'align' => 'right'
		),
		'rows_affected' => array(
			'display' => 'Affected',
			'sort' => 'DESC',
			'align' => 'right'
		),
		'bytes_sent' => array(
			'display' => 'Bytes Sent',
			'sort' => 'DESC',
			'align' => 'right'
		)
	);

	$jsprefix = 'slowlog.php?action=details&logid=' . get_request_var('logid');

	$nav = html_nav_bar('slowlog.php?action=details&logid=' . get_request_var('logid'), MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), 'Log Entries', 'page', 'main');

	html_start_box('MySQL SlowLog Details', '100%', '', '3', 'center', '');
	slowlog_details_filter();
	html_end_box();

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, $jsprefix);

	$i = 0;
	if (cacti_sizeof($results)) {
		foreach ($results as $r) {
			$table = $r['table_name'] != '' ? $r['table_name']:'others';

			form_alternate_row();

			?>
			<td style='width:60px'>
				<a class='pic' href='slowlog.php?action=query&logid=<?php print $r['logid'];?>&logentry=<?php print $r['logentry'];?>'><i class='fas fa-search-plus pic' title='View Details'></i></a>
			</td>
			<td>
				<a class='pic' class='linkEditMain' href='slowlog.php?action=details&table=<?php print $table;?>&logid=<?php print $r['logid'];?>'><?php print $table;?></a>
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

function slowlog_view_charts($method) {
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

	slowlog_tabs();

	html_start_box('MySQL SlowLog Results - By ' . ucfirst($method), '100%', '', '3', 'center', '');

	print '<div class="flexContainer" style="width:100%;justify-content:space-evenly">';

	print '<div style="flex-basis:40%" id="my_count"></div>';
	print '<div style="flex-basis:40%" id="my_query"></div>';
	print '<div style="flex-basis:40%" id="my_examined"></div>';
	print '<div style="flex-basis:40%" id="my_sent"></div>';
	print '<div style="flex-basis:40%" id="my_affected"></div>';
	print '<div style="flex-basis:40%" id="my_bytes"></div>';

	print '</div>';


	$width  = 700;
	$height = 400;

	$charts = array(
		'my_count'    => 'count',
		'my_query'    => 'query_time',
		'my_examined' => 'rows_examined',
		'my_sent'     => 'rows_sent',
		'my_affected' => 'rows_affected',
		'my_bytes'    => 'bytes_sent'
	);

	$output = '';

	foreach($charts as $bindto => $measure) {
		$data = slowlog_get_chart_object($method, $measure);

		$output .= 'renderChart(' .
			'"' . $bindto                    . '",' .
			'"' . $data['title']             . '",' .
			'"' . $data['yaxislabel']        . '",' .
			json_encode($data['categories']) . ','  .
			json_encode($data['values'])     . ','  .
			'"' . $mode                      . '",' .
			$height                          . ','  .
			$width                           . ');' . PHP_EOL;
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
				floating: false,
				minWidth: 40,
				maxWidth: 160,
				title: {
					text: yaxislabel,
                    rotate: -90,
                    offsetX: 0,
                    offsetY: 0,
				},
				labels: {
					formatter: convertLabel
				},
				axisBorder: {
					show: true,
					offsetX: 00,
					offsetY: 00
				},
				axisTicks: {
					witdh: 20
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

	$(function() {
		<?php print $output;?>
	});

	</script>
	<?php
}

function slowlog_view_query() {
	global $config;

	$entry = db_fetch_row_prepared('SELECT *
		FROM plugin_slowlog_details AS sld
		WHERE logid = ?
		AND logentry = ?',
		array(get_filter_request_var('logid'), get_filter_request_var('logentry')));

	slowlog_tabs();

	html_start_box('MySQL SlowLog Query Details', '100%', '', '3', 'center', '');

	form_alternate_row();

	print '<td>Date</td><td>'  . html_escape($entry['date'])       . '</td>';
	print '<td>User</td><td>'  . html_escape($entry['user'])       . '</td>';
	print '<td>Host</td><td>'  . html_escape($entry['host'])       . '</td>';
	print '<td>IP</td><td>'    . html_escape($entry['ip_address']) . '</td>';

	form_end_row();

	form_alternate_row();

	print "<td>Query Time</td><td>"    . number_format_i18n($entry['query_time'])    . '</td>';
	print "<td>Lock Time</td><td>"     . number_format_i18n($entry['lock_time'])     . '</td>';
	print "<td>Rows Sent</td><td>"     . number_format_i18n($entry['rows_sent'])     . '</td>';
	print "<td>Rows Examined</td><td>" . number_format_i18n($entry['rows_examined']) . '</td>';

	form_end_row();

	form_alternate_row();

	print "<td>Rows Affected</td><td>" . number_format_i18n($entry['rows_affected']) . '</td>';
	print "<td>Bytes Sent</td><td>"    . number_format_i18n($entry['bytes_sent'])    . '</td>';
	print "<td colspan='4'></td>";

	form_end_row();
	html_end_box(false);

	html_start_box('Original Query', '100%', '', '3', 'center', '');

	form_alternate_row();

	$oquery = str_replace('","', '", "', $entry['oquery']);
	$oquery = str_replace("','", "', '", $oquery);

	print "<td><pre style='white-space:pre-wrap'>" . $oquery . '</pre></td>';

	form_end_row();

	html_end_box(false);
}

function slowlog_request_summary_validation() {
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

function slowlog_view() {
	global $config, $actions;

	slowlog_request_summary_validation();

	$sql_where   = '';
	$sql_params  = array();
	$sql_orderby = get_order_string();

	if (get_request_var('filter') != '') {
		$sql_where = ($sql_where != '' ? ' AND ': 'WHERE ') . "(description LIKE '%%" . $_REQUEST["filter"] . "%%')";
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	$entries = db_fetch_assoc_prepared("SELECT *, UNIX_TIMESTAMP(end_time)-UNIX_TIMESTAMP(start_time) AS duration
		FROM plugin_slowlog
		$sql_where
		$sql_orderby",
		$sql_params);

	slowlog_tabs();

	html_start_box('MySQL SlowLog File Filters', '100%', '', '3', 'center', 'slowlog.php?action=edit');
	filter();
	html_end_box();

	form_start('slowlog.php', 'chk');

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'nosort' => array(
			'display' => 'Actions'
		),
		'description' => array(
			'display' => 'Description',
			'sort'    => 'ASC'
		),
		'import_status' => array(
			'display' => 'Import Status',
			'sort'    => 'DESC'
		),
		'import_text_status' => array(
			'display' => 'Status String',
			'sort'    => 'DESC'
		),
		'logid' => array(
			'display' => 'ID',
			'sort'    => 'ASC',
			'align'   => 'right'
		),
		'import_date' => array(
			'display' => 'Imported',
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'import_lines' => array(
			'display' => 'Lines',
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'duration' => array(
			'display' => 'Duration',
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'start_time' => array(
			'display' => 'Start Date',
			'sort'    => 'DESC',
			'align'   => 'right'
		),
		'end_time' => array(
			'display' => 'End Date',
			'sort'    => 'DESC',
			'align'   => 'right'
		)
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false, 'slowlog.php?action=select');

	if (cacti_sizeof($entries)) {
		foreach ($entries as $entry) {
			$html = "<a class='pic' href='slowlog.php?action=methods&reset=true&logid=" . $entry["logid"] . "'>
				<i class='fas fa-poll deviceUp' title='View Methods'></i></a>
				<a class='pic' href='" . html_escape($config['url_path'] . 'plugins/slowlog/slowlog.php?action=tables&reset=true&logid=' . $entry["logid"]) . "'>
				<i class='fas fa-poll deviceRecovering' title='View Tables'></i></a>
				<a class='pic' href='" . html_escape($config['url_path'] . 'plugins/slowlog/slowlog.php?action=details&reset=true&logid=' . $entry["logid"]) . "'>
				<i class='fas fa-search-plus' title='View Details'></i></a>";

			form_alternate_row('line_' . $entry['logid']);

			if (empty($entry['import_status'])) {
				$status = '<span class="deviceUnknown">' . __('Pre-Processing', 'slowlog') . '</span>';
			} elseif ($entry['import_status'] == 1) {
				$status = '<span class="deviceRecovering">' . __('Post-Processing', 'slowlog') . '</span>';
			} elseif ($entry['import_status'] == 2) {
				$status = '<span class="deviceUp">' . __('Complete', 'slowlog') . '</span>';
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
			form_checkbox_cell($entry['description'], $entry['logid'], '', 'right');

			form_end_row();
		}
	} else {
		print '<tr><td colspan="' . (cacti_sizeof($display_text) + 1) . '"><em>No MySQL/MariaDB Imported Slow Logs</em></td></tr>';
	}

	html_end_box(false);

	draw_actions_dropdown($actions);

	form_end();
}

/* slowlog_save_button - draws a (save|create) and cancel button at the bottom of
     an html edit form
   @arg $force_type - if specified, will force the 'action' button to be either
     'save' or 'create'. otherwise this field should be properly auto-detected */
function slowlog_save_button($cancel_action = '', $action = 'save', $force_type = '', $key_field = 'id') {
	global $config;

	if (substr_count($cancel_action, '.php')) {
		$caction = $cancel_action;
		$calt = 'Return';
		$sname = 'save';
		$salt = 'Save';
	} else {
		$caction = 'slowlog.php';
		$calt = 'Cancel';
		if ((empty($force_type)) || ($cancel_action == 'return')) {
			if ($action == 'import') {
				$sname = 'import';
				$salt  = 'Import';
			} elseif (empty($_GET[$key_field])) {
				$sname = 'create';
				$salt  = 'Create';
			} else {
				$sname = 'save';
				$salt  = 'Save';
			}

			if ($cancel_action == 'return') {
				$calt   = 'Return';
				$action = 'save';
			} else {
				$calt   = 'Cancel';
			}
		} elseif ($force_type == 'save') {
			$sname = 'save';
			$salt  = 'Save';
		} elseif ($force_type == 'create') {
			$sname = 'create';
			$salt  = 'Create';
		} elseif ($force_type == 'import') {
			$sname = 'import';
			$salt  = 'Import';
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

function filter() {
	global $config;

	?>
	<tr class='even'>
		<td>
			<form id='summary'>
				<table class='filterTable'>
					<tr>
						<td>
							Search
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

function slowlog_details_filter() {
	global $config, $item_rows;

	?>
	<tr class='even'>
		<td>
			<form id='details'>
				<table class='filterTable'>
					<tr>
						<td>
							LogFile
						</td>
						<td>
							<select id='logid' onChange='applyFilter()'>
								<option value='-1'<?php if ($_REQUEST['logid'] == '-1') {?> selected<?php }?>>Any</option>
								<?php
								$logids = db_fetch_assoc('SELECT logid, CONCAT(description, " [ ", import_date, " ]") AS name
									FROM plugin_slowlog ORDER BY name');

								if (cacti_sizeof($logids)) {
									foreach ($logids as $l) {
										print '<option value="' . $l['logid'] . '"' . ($_REQUEST['logid'] == $l['logid'] ? ' selected':'') . '>' . html_escape($l['name']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							Method
						</td>
						<td>
							<select id='mmethod' onChange='applyFilter()'>
								<option value='-1'<?php if ($_REQUEST['mmethod'] == '-1') {?> selected<?php }?>>Any</option>
								<?php
								$methods = db_fetch_assoc('SELECT * FROM plugin_slowlog_methods ORDER BY method');

								if (cacti_sizeof($methods)) {
									foreach ($methods as $m) {
										print '<option value="' . $m['methodid'] . '"' . ($_REQUEST['mmethod'] == $m['methodid'] ? ' selected':'') . '>' . html_escape($m['method']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							Tables
						</td>
						<td>
							<select id='table' onChange='applyFilter()'>
								<option value='-1'<?php if ($_REQUEST['table'] == '-1') {?> selected<?php }?>>Any</option>
								<option value='-2'<?php if ($_REQUEST['table'] == '-2') {?> selected<?php }?>>Others</option>
								<?php
								if (get_request_var('logid') > 0) {
									$tables = db_fetch_assoc_prepared('SELECT DISTINCT table_name
										FROM plugin_slowlog_details_tables
										WHERE logid = ?
										ORDER BY table_name',
										array(get_request_var('logid')));
								} else {
									$tables = db_fetch_assoc('SELECT DISTINCT table_name
										FROM plugin_slowlog_details_tables
										ORDER BY table_name');
								}

								if (cacti_sizeof($tables)) {
									foreach ($tables as $t) {
										print '<option value="' . $t['table_name'] . '"' . ($_REQUEST['table'] == $t['table_name'] ? ' selected':'') . '>' . html_escape($t['table_name']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							Rows
						</td>
						<td>
							<select id='rows' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var_request('rows') == '-1') {?> selected<?php }?>>Default</option>
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
								<input class='button_go' type='submit' onClick='applyFilter()' name='go' value='Go'>
								<input class='button_clear' type='button' onClick='clearFilter()' name='clear' value='Clear'>
							</span>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							Search
						</td>
						<td>
							<input type='text' id='filter' size='40' value='<?php print html_escape_request_var('filter');?>'>
						</td>
						<td>
							User
						</td>
						<td>
							<select id='myuser' onChange='applyFilter()'>
								<option value='-1'<?php if (get_request_var('user') == '-1') {?> selected<?php }?>>Any</option>
								<?php
								if (get_request_var('logid') > 0) {
									$users = db_fetch_assoc_prepared('SELECT DISTINCT user
										FROM plugin_slowlog_details
										WHERE logid = ?
										ORDER BY user',
										array(get_request_var('logid')));
								} else {
									$users = db_fetch_assoc('SELECT DISTINCT user
										FROM plugin_slowlog_details
										ORDER BY user');
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
							Host
						</td>
						<td>
							<select id='host' onChange='applyFilter()'>
								<option value='-1'<?php if ($_REQUEST['host'] == '-1') {?> selected<?php }?>>Any</option>
								<?php
								if (get_request_var('logid') > 0) {
									$hosts = db_fetch_assoc_prepared('SELECT DISTINCT host
										FROM plugin_slowlog_details
										WHERE logid = ?
										ORDER BY host',
										array(get_request_var('logid')));
								} else {
									$hosts = db_fetch_assoc('SELECT DISTINCT host
										FROM plugin_slowlog_details
										ORDER BY host');
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
			</form>
			<script type='text/javascript'>
			function applyFilter() {
				var strURL = '?action=details&logid=<?php print $_REQUEST['logid'];?>';
				strURL += '&header=false';
				strURL += '&filter=' + $('#filter').val();
				strURL += '&rows=' + $('#rows').val();
				strURL += '&logid=' + $('#logid').val();
				strURL += '&mmethod=' + $('#mmethod').val();
				strURL += '&table=' + $('#table').val();
				strURL += '&user=' + $('#myuser').val();
				strURL += '&host=' + $('#host').val();

				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				var strURL = 'slowlog.php?header=false&reset=true&action=details&logid=<?php print get_request_var('logid');?>';

				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#details').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php
}

