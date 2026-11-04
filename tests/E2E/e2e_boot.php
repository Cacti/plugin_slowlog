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
 * Start-up shared by the end-to-end driver scripts. A driver includes this file from its top level, so
 * the variables below are the driver's own. Like the drivers, it does nothing unless a test process
 * started it from the command line with SLOWLOG_E2E=1 and a temp directory.
 */
if (PHP_SAPI !== 'cli' || getenv('SLOWLOG_E2E') !== '1' || !is_dir((string) getenv('SLOWLOG_E2E_TMP'))) { http_response_code(404); exit; }

require_once __DIR__ . '/e2e_common.php';

$root = slowlog_e2e_enter(__FILE__);
$tmp  = slowlog_e2e_tmp();

require_once $root . '/include/cli_check.php';
require_once $root . '/plugins/slowlog/includes/functions.php';

$saved_log = slowlog_e2e_redirect_log($tmp);
$plugin    = $root . '/plugins/slowlog';
$tag       = 'e2e' . getmypid();
$out       = [];
