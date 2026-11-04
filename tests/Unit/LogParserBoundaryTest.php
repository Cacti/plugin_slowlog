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


uses(TestCase::class);
beforeEach(function () { TestCase::loadPluginSource('includes/functions.php'); });

it('resets optional headers between entries sharing a timestamp', function () {
	$stream = fopen('php://temp', 'r+');
	fwrite($stream, "# Time: 240101 00:00:00\n# User@Host: first[first] @ host [::1]\n# Thread_id: 9 Schema: shop QC_hit: Yes\n# Rows_affected: 5 Bytes_sent: 100\nSELECT 1;\n# User@Host: next[next] @ host [::1]\n# Query_time: broken\n# Rows_affected: broken\n# Thread_id: broken\n# administrator command: Quit;\nSELECT 2;\n");
	rewind($stream);
	try { $rows = iterator_to_array(slowlog_parse_log_stream($stream)); } finally { fclose($stream); }
	expect($rows)->toHaveCount(2);
	expect($rows[0]['schema'])->toBe('shop');
	expect($rows[1]['schema'])->toBe('');
	expect($rows[1]['thread_id'])->toBe(0);
	expect($rows[1]['bytes_sent'])->toBe(0);
	expect($rows[1]['date'])->toBe($rows[0]['date']);
	expect($rows[1]['query'])->toBe('SELECT 2; ');
});


class SlowlogInterruptedTestStream {
	public $context;
	private string $data = '';
	public function stream_open($path, $mode, $options, &$opened): bool {
		$this->data = str_contains($path, 'after') ? "# Time: 240101 00:00:00\nSELECT 1;\n# Time: 240101 00:00:01\n" : '';
		return true;
	}
	public function stream_read($count) {
		if ($this->data === '') { return false; }
		$result = substr($this->data, 0, $count);
		$this->data = substr($this->data, $count);
		return $result;
	}
	public function stream_eof(): bool { return false; }
	public function stream_stat(): array { return []; }
}

it('reports interrupted input before and after creating the parent import', function ($phase) {
	stream_wrapper_register('slowloginterrupted', SlowlogInterruptedTestStream::class);
	ob_start();
	try { $ok = import_logfile('slowloginterrupted://' . $phase, 'test', -1, '', false, false); }
	finally { stream_wrapper_unregister('slowloginterrupted'); $message = ob_get_clean(); }
	expect($ok)->toBeFalse();
	expect($message)->toContain($phase === 'before' ? 'Read error' : 'Read Error');
})->with(['before', 'after']);


it('drops the local-server prologue without leaking it into a query', function () {
	$stream = fopen('php://temp', 'r+');
	fwrite($stream, "# Time: 240101 00:00:00\nSELECT discard;\n/*!32311 LOCAL */\nSELECT keep;\n");
	rewind($stream);
	try { $rows = iterator_to_array(slowlog_parse_log_stream($stream)); } finally { fclose($stream); }
	expect($rows)->toHaveCount(1);
	expect($rows[0]['query'])->toBe('SELECT keep; ');
	expect($rows[0]['oquery'])->toBe("SELECT keep;\n");
});
