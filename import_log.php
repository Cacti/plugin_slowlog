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

include(__DIR__ . '/../../include/cli_check.php');
include(__DIR__ . '/slowlog_functions.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$logfile      = false;
$logid        = false;
$reprocess    = false;
$usecacti     = false;
$table_mode   = null;
$table_names  = '';
$description  = 'Imported using import_log.php';
$length       = -1;
$delete_after = false;

if (cacti_sizeof($parms)) {
	$shortopts = 'VvHh';

	$longopts = array(
		'logfile:',
		'logid:',
		'reprocess:',
		'usecacti',
		'table-mode:',
		'table-names:',
		'description:',
		'length:',
		'delete-after',
		'version',
		'help'
	);

	$options = getopt($shortopts, $longopts);

	foreach($options as $arg => $value) {
		// getopt() returns an array instead of a scalar when an option is repeated more than
		// once on the command line - none of the options below are meant to be repeatable, so
		// normalize to the last occurrence (a defensive fallback; a real invocation will only
		// ever pass each of these once).
		if (is_array($value)) {
			$value = end($value);
		}

		switch($arg) {
			case 'logfile':
				$logfile = $value;

				break;
			case 'logid':
				$logid = $value;

				break;
			case 'reprocess':
				$reprocess = $value;

				break;
			case 'usecacti':
				$usecacti = true;

				break;
			case 'table-mode':
				if (in_array($value, array('cacti', 'reference', 'all'), true)) {
					$table_mode = $value;
				} else {
					print "ERROR: Invalid --table-mode value '$value', must be cacti, reference, or all" . PHP_EOL . PHP_EOL;
					display_help();
					exit(1);
				}

				break;
			case 'table-names':
				$table_names = $value;

				break;
			case 'description':
				$description = $value;

				break;
			case 'length':
				$length = (int) $value;

				break;
			case 'delete-after':
				$delete_after = true;

				break;
			case 'version':
			case 'V':
			case 'v':
				display_version();
				exit(0);
			case 'help':
			case 'H':
			case 'h':
				display_help();
				exit(0);
			default:
				print "ERROR: Invalid Argument: ($arg)" . PHP_EOL . PHP_EOL;
				display_help();
				exit(1);
		}
	}
}

if ($logid !== false && !preg_match('/^[1-9][0-9]*$/', trim((string) $logid))) {
	print __('ERROR: Invalid --logid value \'%s\', must be a positive integer', $logid, 'slowlog') . PHP_EOL . PHP_EOL;
	display_help();
	exit(1);
}

if ($logfile !== false) {
	if ($table_mode === 'reference' && trim($table_names) === '') {
		print 'ERROR: --table-mode=reference requires --table-names="..." for a --logfile import' . PHP_EOL . PHP_EOL;
		display_help();
		exit(1);
	}

	// --logid alongside --logfile means the caller (the web upload handler) already
	// inserted the parent record so its status is visible immediately - reuse it
	// instead of creating a second one.
	import_logfile($logfile, $description, $length, $table_names, $usecacti, false, $table_mode, $logid !== false ? (int) $logid : null);

	if ($delete_after) {
		@unlink($logfile);
	}
} elseif ($logid !== false) {
	import_post_process((int) $logid, $table_names, $usecacti, $table_mode);
} elseif ($reprocess !== false) {
	if (strtolower($reprocess) == 'all') {
		slowlog_reprocess_all($table_names, $usecacti, $table_mode);
	} elseif (preg_match('/^[1-9][0-9]*$/', trim((string) $reprocess))) {
		slowlog_reprocess((int) $reprocess, $table_names, $usecacti, $table_mode);
	} else {
		print "ERROR: Invalid --reprocess value '$reprocess', must be a positive integer logid or 'all'" . PHP_EOL . PHP_EOL;
		display_help();
		exit(1);
	}
}

/*  display_version - displays version information */
function display_version(): void {
	$version = get_cacti_cli_version();
	print "Cacti Import Slowlog, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

function display_help(): void {
	display_version();

	print PHP_EOL . 'usage: import_log.php [ --usecacti | --table-mode=cacti|reference|all ] --logid=N | --logfile=S | --reprocess=N|all' . PHP_EOL . PHP_EOL;
	print 'Cacti utility for auditing the MySQL/MariaDB slow log file.' . PHP_EOL;
	print 'Options:' . PHP_EOL;
	print '    --usecacti   - The logid when performing batch operations' . PHP_EOL;
	print '    --logid=N    - The logid when performing batch operations' . PHP_EOL;
	print '    --logfile=S  - The logfile assuming the current Cacti database' . PHP_EOL;
	print '    --reprocess=N|all - Re-run method/table/timeout classification for an existing' . PHP_EOL;
	print '                        logid (or every logid), e.g. after new methods/tables are' . PHP_EOL;
	print '                        added. Does not need the original logfile.' . PHP_EOL;
	print '    --table-mode=cacti|reference|all - How to distinguish Cacti tables from other' . PHP_EOL;
	print '                        tables. cacti: use this Cacti DB (same as --usecacti);' . PHP_EOL;
	print '                        reference: compare against the table list saved with the' . PHP_EOL;
	print '                        import; all: detect everything, no OTHER TABLES grouping' . PHP_EOL;
	print '                        (default).' . PHP_EOL;
	print '    --table-names="t1 t2" - Space-separated reference table list for a --logfile' . PHP_EOL;
	print '                        import; required with --table-mode=reference. Saved with' . PHP_EOL;
	print '                        the import so --logid/--reprocess can reuse it later.' . PHP_EOL;
	print '    --logid=N together with --logfile=S - Reuse an already-created parent record' . PHP_EOL;
	print '                        (e.g. one inserted up front by a caller) instead of' . PHP_EOL;
	print '                        creating a new one.' . PHP_EOL;
	print '    --description=S - LogFile description to save with a --logfile import' . PHP_EOL;
	print '                        (default: "Imported using import_log.php").' . PHP_EOL;
	print '    --length=N   - Truncate each imported query to N characters, -1 for no limit' . PHP_EOL;
	print '                        (default: -1). Only used with --logfile.' . PHP_EOL;
	print '    --delete-after - Delete the --logfile after a successful import (used by the' . PHP_EOL;
	print '                        web upload handler to clean up its staged copy).' . PHP_EOL . PHP_EOL;
}


