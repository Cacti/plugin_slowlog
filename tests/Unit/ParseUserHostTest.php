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

it('parses the MariaDB form', function () {
	expect(slowlog_parse_user_host("# User@Host: root[root] @ dbhost-new [10.0.0.5]\n"))
		->toBe(array('root', 'dbhost', '10.0.0.5'));
});

it('does not include the MySQL Id suffix in the address', function () {
	expect(slowlog_parse_user_host("# User@Host: root[root] @ localhost [192.168.100.100]  Id:     5\n"))
		->toBe(array('root', 'localhost', '192.168.100.100'));
});

it('keeps an IPv6 address intact', function () {
	expect(slowlog_parse_user_host("# User@Host: app[app] @ localhost [::1]  Id: 12\n"))
		->toBe(array('app', 'localhost', '::1'));
});

it('returns an empty host and address when the server logs neither', function () {
	expect(slowlog_parse_user_host("# User@Host: event_scheduler[event_scheduler] @  []\n"))
		->toBe(array('event_scheduler', '', ''));
});

it('keeps an at sign that is part of the user name', function () {
	expect(slowlog_parse_user_host("# User@Host: svc@corp[svc@corp] @ web1.example.com [10.1.2.3]\n"))
		->toBe(array('svc@corp', 'web1', '10.1.2.3'));
});

it('accepts a user without the bracketed name', function () {
	expect(slowlog_parse_user_host("# User@Host: root @ localhost\n"))
		->toBe(array('root', 'localhost', ''));
});

it('returns empty values for a line of the wrong shape', function () {
	expect(slowlog_parse_user_host("# User@Host: garbage\n"))
		->toBe(array('', '', ''));
});
