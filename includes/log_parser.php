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

/**
 * A read failure must not be mistaken for a clean end of the input stream.
 */
class SlowlogImportReadException extends RuntimeException {}

/**
 * A rejected detail batch must stop ingestion before post-processing.
 */
class SlowlogImportWriteException extends RuntimeException {}

/**
 * Build fresh defaults for a log entry so optional headers cannot leak between queries.
 *
 * @param mixed $date Time header inherited by entries sharing it.
 * @param int|null $epoch Absolute timestamp, when supplied by the server.
 *
 * @return array The entry fields, without database identity or SQL serialization.
 */
function slowlog_empty_log_entry($date = 0, ?int $epoch = null): array {
	return ['date' => $date, 'epoch' => $epoch, 'user' => '', 'host' => '', 'ip' => '', 'query' => '', 'oquery' => '',
		'schema' => '', 'qc_hit' => 0, 'query_time' => 0, 'thread_id' => 0, 'lock_time' => 0,
		'rows_sent' => 0, 'rows_examined' => 0, 'rows_affected' => 0, 'bytes_sent' => 0];
}

/**
 * Parse a MySQL/MariaDB slow log without accessing the database or request state.
 *
 * @param resource $stream An already-open readable stream, owned by the caller.
 *
 * @return Generator Parsed entries in source order.
 */
function slowlog_parse_log_stream($stream): Generator {
	$entry = slowlog_empty_log_entry();
	$start = false;
	$query_start = false;
	while (($line = fgets($stream)) !== false) {
		if ($start && substr($line, 0, 1) !== '#') {
			$query_start = true;
		}
		if (strncmp($line, 'SET timestamp=', 14) === 0) {
			$entry['epoch'] = (int) substr($line, 14);
		} elseif (strncmp($line, '# Time:', 7) === 0) {
			if ($start && $entry['query'] !== '') {
				yield $entry;
			}
			$entry = slowlog_empty_log_entry();
			$start = true;
			$entry['date'] = $line;
			$query_start = false;
		} elseif (strncmp($line, '# User@Host:', 12) === 0) {
			if ($query_start) {
				if ($entry['query'] !== '') {
					yield $entry;
				}
				$entry = slowlog_empty_log_entry($entry['date'], $entry['epoch']);
			}
			[$entry['user'], $entry['host'], $entry['ip']] = slowlog_parse_user_host($line);
			$query_start = false;
		} elseif (strncmp($line, '# Query_time:', 13) === 0) {
			$data = preg_split('/\s+/', $line) ?: [];
			if (count($data) < 9) {
				continue;
			}
			$entry['query_time'] = slowlog_log_number($data[2]);
			$entry['lock_time'] = slowlog_log_number($data[4]);
			$entry['rows_sent'] = slowlog_log_number($data[6]);
			$entry['rows_examined'] = slowlog_log_number($data[8]);
		} elseif (strncmp($line, '# Rows_affected:', 16) === 0) {
			$data = preg_split('/\s+/', $line) ?: [];
			if (count($data) < 5) {
				continue;
			}
			$entry['rows_affected'] = slowlog_log_number($data[2]);
			$entry['bytes_sent'] = slowlog_log_number($data[4]);
		} elseif (strncmp($line, '# Thread_id:', 12) === 0) {
			$data = preg_split('/\s+/', $line) ?: [];
			if (count($data) < 7) {
				continue;
			}
			$entry['thread_id'] = slowlog_log_number($data[2]);
			$entry['schema'] = $data[4];
			$entry['qc_hit'] = $data[6] === 'No' ? 0 : 1;
		} elseif (strncmp($line, '# administrator command:', 24) === 0) {
			continue;
		} elseif ($start) {
			if (strpos($line, '/*!32311 LOCAL */') !== false) {
				$entry['query'] = '';
				$entry['oquery'] = '';
			} else {
				$entry['oquery'] .= $line;
				if (substr(trim($line), 0, 2) !== '--') {
					$entry['query'] .= trim($line) . ' ';
				}
			}
		}
	}
	if (!feof($stream)) {
		throw new SlowlogImportReadException();
	}
	if ($entry['query'] !== '') {
		yield $entry;
	}
}


/**
 * Returns a numeric field from a slow log header line in a form that is safe to place
 * unquoted in the bulk INSERT, or '0' when the value is not a plain decimal number. The
 * log is user-supplied, and these fields are concatenated into the SQL text rather than
 * bound, so anything else (for example a subquery) must never reach the statement.
 *
 * Called from import_logfile() for each numeric field it parses.
 *
 * @param string $value The raw whitespace-delimited token from the log line.
 *
 * @return string The token unchanged when it is a plain decimal number, otherwise '0'.
 */
function slowlog_log_number(string $value): string {
	return preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value) ? $value : '0';
}

/**
 * Splits a slow log "# User@Host:" header into the user, the short host name and the client
 * address. The line is not split on every ':' because an IPv6 address contains them and
 * MySQL appends "Id: N" after the address.
 *
 * Called from import_logfile() for each entry header.
 *
 * @param string $line The "# User@Host:" header line.
 *
 * @return array{0: string, 1: string, 2: string} The user, short host name and address,
 *                                                or three empty strings when the line
 *                                                does not have the expected shape.
 */
function slowlog_parse_user_host(string $line): array {
	if (!preg_match('/^# User@Host:\s*(\S*?)(?:\[[^\]]*\])?\s+@\s+([^\s\[]*)\s*(?:\[([^\]]*)\])?/', $line, $m)) {
		return ['', '', ''];
	}

	return [$m[1], slowlog_strip_domain($m[2]), isset($m[3]) ? $m[3] : ''];
}

/**
 * Extracts the hostname portion (before the first dot) from a fully
 * qualified host string, stripping a '-new' suffix sometimes present in
 * slow-log host entries. Used when parsing the User@Host header.
 *
 * @param string $host The fully qualified host string to shorten.
 *
 * @return string The shortened hostname.
 */
function slowlog_strip_domain(string $host): string {
	$parts = explode('.', $host);

	return str_replace('-new', '', $parts[0]);
}
