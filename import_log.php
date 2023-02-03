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

include(__DIR__ . '/../../include/cli_check.php');
include(__DIR__ . '/slowlog_functions.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$logfile    = false;
$logid      = false;
$usecacti   = false;

if (cacti_sizeof($parms)) {
	$shortopts = 'VvHh';

	$longopts = array(
		'logfile:',
		'logid:',
		'usecacti',
		'version',
		'help'
	);

	$options = getopt($shortopts, $longopts);

	foreach($options as $arg => $value) {
		switch($arg) {
			case 'logfile':
				$logfile = $value;

				break;
			case 'logid':
				$logid = $value;

				break;
			case 'usecacti':
				$usecacti = true;

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

if ($logfile !== false) {
	import_logfile($logfile, 'Imported using import_log.php', -1, '', $usecacti, false);
} elseif ($logid !== false) {
	import_post_process($logid, '', $usecacti);
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Import Slowlog, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

function display_help() {
	display_version();

	print PHP_EOL . 'usage: import_log.php [ --usecacti ] --logid=N | --logfile=S' . PHP_EOL . PHP_EOL;
	print 'Cacti utility for auditing the MySQL/MariaDB slow log file.' . PHP_EOL;
	print 'Options:' . PHP_EOL;
	print '    --usecacti   - The logid when performing batch operations' . PHP_EOL;
	print '    --logid=N    - The logid when performing batch operations' . PHP_EOL;
	print '    --logfile=S  - The logfile assuming the current Cacti database' . PHP_EOL . PHP_EOL;;
}

