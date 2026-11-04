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
 * slowlog_parse_user_host() reads the "# User@Host:" header of a slow log entry. The formats
 * differ between servers: MySQL appends "Id: N" after the address, clients can connect over
 * IPv6, and the host can be empty.
 */

uses(TestCase::class);

beforeEach(function () {
	TestCase::loadPluginSource('includes/functions.php');
});

it('parses the header', function ($line, $expected) {
	expect(slowlog_parse_user_host($line))->toBe($expected);
})->with(array(
	'MariaDB form'                           => array("# User@Host: root[root] @ dbhost-new [10.0.0.5]\n", array('root', 'dbhost', '10.0.0.5')),
	'MySQL Id suffix is not part of the address' => array("# User@Host: root[root] @ localhost [192.168.100.100]  Id:     5\n", array('root', 'localhost', '192.168.100.100')),
	'IPv6 address'                           => array("# User@Host: app[app] @ localhost [::1]  Id: 12\n", array('app', 'localhost', '::1')),
	'no host and no address'                 => array("# User@Host: event_scheduler[event_scheduler] @  []\n", array('event_scheduler', '', '')),
	'at sign in the user name'               => array("# User@Host: svc@corp[svc@corp] @ web1.example.com [10.1.2.3]\n", array('svc@corp', 'web1', '10.1.2.3')),
	'user without the bracketed name'        => array("# User@Host: root @ localhost\n", array('root', 'localhost', '')),
	'wrong shape'                            => array("# User@Host: garbage\n", array('', '', '')),
));
