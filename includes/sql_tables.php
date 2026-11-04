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

// keywords that end a FROM/JOIN table-reference-list clause at the current nesting depth
const SLOWLOG_CLAUSE_BOUNDARY = '(?:WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION|INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|STRAIGHT_JOIN|JOIN|ON|USING|INTO\s+OUTFILE|PROCEDURE|FOR\s+UPDATE|LOCK\s+IN)';

// any flavor of JOIN keyword, used both to split a table-ref-list and to find join targets
const SLOWLOG_JOIN_KEYWORD = '(?:INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|STRAIGHT_JOIN|JOIN)';

/**
 * Collapses a query's whitespace (including newlines) into single spaces
 * and trims it, for consistent regex matching by the table/JOIN
 * extraction functions. Called from slowlog_extract_tables_from_query()
 * before tokenizing a query.
 *
 * @param string|null $query The raw query text to normalize.
 *
 * @return string The whitespace-collapsed, trimmed query text.
 */
function slowlog_normalize_query_text(?string $query): string {
	return trim(preg_replace('/\s+/', ' ', (string) $query) ?? '');
}

/**
 * Replaces the content of single/double-quoted string literals with same-length filler
 * (keeping the delimiters and any newlines intact), so keyword/pattern scanning elsewhere
 * can't be fooled by SQL-looking text inside a string constant. Backtick-quoted identifiers
 * are left untouched since they're table/column names, not string content.
 *
 * Called from slowlog_mask_strings_and_comments().
 *
 * @param string $query The query text to mask.
 *
 * @return string The query text with quoted-string contents replaced by
 *                filler.
 */
function slowlog_mask_quoted_strings(string $query): string {
	$len  = strlen($query);
	$out  = '';
	$i    = 0;
	$in_s = null;

	while ($i < $len) {
		$ch = $query[$i];

		if ($in_s !== null) {
			if ($ch === '\\' && $i + 1 < $len) {
				$out .= '  ';
				$i   += 2;

				continue;
			}

			if ($ch === $in_s) {
				if ($i + 1 < $len && $query[$i + 1] === $in_s) {
					// a doubled quote ('' or "") is an escaped quote, still inside the string
					$out .= '  ';
					$i   += 2;

					continue;
				}

				$in_s = null;
				$out .= $ch;
				$i++;

				continue;
			}

			$out .= ($ch === "\n" || $ch === "\t") ? $ch : ' ';
			$i++;

			continue;
		}

		if ($ch === "'" || $ch === '"') {
			$in_s = $ch;
			$out .= $ch;
			$i++;

			continue;
		}

		$out .= $ch;
		$i++;
	}

	return $out;
}

/**
 * Replaces --/#/\/* *\/ SQL comments with same-length filler. Run this after
 * slowlog_mask_quoted_strings() so a comment-looking sequence inside a string literal isn't
 * mistaken for a real comment.
 *
 * Called from slowlog_mask_strings_and_comments().
 *
 * @param string $query The (already string-masked) query text to mask.
 *
 * @return string The query text with comment contents replaced by
 *                filler.
 */
function slowlog_mask_sql_comments(string $query): string {
	$len = strlen($query);
	$out = '';
	$i   = 0;

	while ($i < $len) {
		// MySQL/MariaDB only treat "--" as a comment starter when the second
		// dash is followed by whitespace/a control character (or end of
		// string) - otherwise "a--b" is a valid expression, not a comment.
		if ($query[$i] === '-' && $i + 1 < $len && $query[$i + 1] === '-' &&
			($i + 2 >= $len || ctype_space($query[$i + 2]))) {
			$end = strpos($query, "\n", $i);
			$end = ($end === false) ? $len : $end;
			$out .= str_repeat(' ', $end - $i);
			$i    = $end;

			continue;
		}

		if ($query[$i] === '#') {
			$end = strpos($query, "\n", $i);
			$end = ($end === false) ? $len : $end;
			$out .= str_repeat(' ', $end - $i);
			$i    = $end;

			continue;
		}

		if ($query[$i] === '/' && $i + 1 < $len && $query[$i + 1] === '*') {
			$end = strpos($query, '*/', $i + 2);
			$end = ($end === false) ? $len : $end + 2;
			$out .= str_repeat(' ', $end - $i);
			$i    = $end;

			continue;
		}

		$out .= $query[$i];
		$i++;
	}

	return $out;
}

/**
 * Masks both string literals and comments (in that order) so the table/JOIN scanner below
 * can't mistake SQL-looking text inside either one for a real clause - e.g.
 * "SELECT 1 /* FROM admins * /" no longer looks like it references a table named admins.
 *
 * Called from slowlog_extract_tables_from_query() before scanning for
 * table references.
 *
 * @param string $query The raw query text to mask.
 *
 * @return string The masked query text, safe for clause/keyword
 *                scanning.
 */
function slowlog_mask_strings_and_comments(string $query): string {
	return slowlog_mask_sql_comments(slowlog_mask_quoted_strings($query));
}

/**
 * Splits $text on a top-level delimiter, i.e. one that isn't nested inside parens - so a
 * comma inside a function call or a derived table doesn't split a table-reference list.
 *
 * Called from slowlog_extract_table_ref_list() to split a FROM/JOIN
 * clause's comma-separated table references.
 *
 * @param string $text  The text to split.
 * @param string $delim The single-character delimiter to split on;
 *                      defaults to ','.
 *
 * @return array The top-level segments (trailing empty segments
 *               omitted).
 */
function slowlog_split_top_level(string $text, string $delim = ','): array {
	$parts   = [];
	$depth   = 0;
	$current = '';
	$len     = strlen($text);

	for ($i = 0; $i < $len; $i++) {
		$ch = $text[$i];

		if ($ch === '(') {
			$depth++;
		} elseif ($ch === ')') {
			$depth--;
		}

		if ($ch === $delim && $depth === 0) {
			$parts[] = $current;
			$current = '';
		} else {
			$current .= $ch;
		}
	}

	if (trim($current) !== '') {
		$parts[] = $current;
	}

	return $parts;
}

/**
 * Matches a leading, fully-balanced parenthesized group at the start of
 * $text (e.g. a derived table's subquery). Called from
 * slowlog_extract_single_table_ref() to isolate a subquery's contents
 * from what follows it.
 *
 * @param string $text Text expected to start with '('.
 *
 * @return array{0: string, 1: string}|false A two-element array of the
 *                                           parenthesized content and
 *                                           the remaining text after the
 *                                           closing paren, or false if
 *                                           the leading '(' has no
 *                                           matching close.
 */
function slowlog_match_balanced_parens(string $text) {
	if (preg_match('/\((?:[^()]|(?R))*\)/', $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] === 0) {
		$whole = $m[0][0];

		return [substr($whole, 1, -1), substr($text, strlen($whole))];
	}

	return false;
}

/**
 * grabs a leading `schema`.`table`/schema.table/table identifier off of $text
 *
 * Called from slowlog_extract_single_table_ref(),
 * slowlog_extract_join_chain(), and other table-reference extraction
 * functions to read a single identifier at the start of a clause
 * fragment.
 *
 * @param string $text The text to extract a leading identifier from.
 *
 * @return string The normalized (via parseTable()) leading identifier,
 *                or '' if $text doesn't start with one.
 */
function slowlog_first_identifier(string $text): string {
	$text = ltrim($text);

	if (preg_match('/^`[^`]+`(?:\.`[^`]+`)?|^[A-Za-z0-9_$]+(?:\.[A-Za-z0-9_$]+)?/', $text, $m)) {
		return parseTable($m[0]);
	}

	return '';
}

/**
 * Finds the end offset (exclusive) of a clause starting at $start: the first depth-0
 * SLOWLOG_CLAUSE_BOUNDARY keyword, an unmatched ')', a ';', or end of string - whichever
 * comes first. Depth is relative to $start, so a derived table's own inner keywords don't
 * end the clause early.
 *
 * Called from slowlog_extract_from_clauses(),
 * slowlog_extract_join_targets(), and slowlog_extract_using_clause_tables()
 * to bound each FROM/JOIN/USING clause they scan.
 *
 * @param string $text  The (masked) query text being scanned.
 * @param int    $start The offset to start scanning the clause from.
 *
 * @return int The offset (exclusive) where the clause ends.
 */
function slowlog_scan_clause_span(string $text, int $start): int {
	$depth = 0;
	$len   = strlen($text);
	$i     = $start;

	while ($i < $len) {
		$ch = $text[$i];

		if ($ch === '(') {
			$depth++;
			$i++;

			continue;
		}

		if ($ch === ')') {
			if ($depth === 0) {
				return $i;
			}

			$depth--;
			$i++;

			continue;
		}

		if ($ch === ';') {
			return $i;
		}

		if ($depth === 0 && preg_match('/\G\s+' . SLOWLOG_CLAUSE_BOUNDARY . '\b/i', $text, $bm, 0, $i)) {
			return $i;
		}

		$i++;
	}

	return $len;
}

/**
 * a single item of a comma-separated table-reference list, e.g. "t1 a" or "(SELECT ...) x"
 *
 * Called from slowlog_extract_table_ref_list() and
 * slowlog_extract_join_chain() for each item in a table-reference list.
 *
 * @param string                $text   A single table-reference-list item.
 * @param array<string, string> $tables Reference, the de-duplicating
 *                                      set of discovered table names
 *                                      being built up (name => name).
 *
 * @return void
 */
function slowlog_extract_single_table_ref(string $text, array &$tables): void {
	$text = trim($text);

	if ($text === '') {
		return;
	}

	if ($text[0] === '(') {
		$balanced = slowlog_match_balanced_parens($text);

		if ($balanced !== false) {
			slowlog_extract_tables_from_query($balanced[0], $tables);

			return;
		}
	}

	$id = slowlog_first_identifier($text);

	if ($id !== '') {
		$tables[$id] = $id;
	}
}

/**
 * the continuation of a table-reference list after its first JOIN keyword has been consumed
 *
 * Called from slowlog_extract_table_ref_list() to walk the remaining
 * chain of JOIN targets in a table-reference list.
 *
 * @param string                $text   The text following the first JOIN keyword.
 * @param array<string, string> $tables Reference, the de-duplicating
 *                                      set of discovered table names
 *                                      being built up (name => name).
 *
 * @return void
 */
function slowlog_extract_join_chain(string $text, array &$tables): void {
	while (true) {
		$text = ltrim($text);

		if ($text === '') {
			return;
		}

		if ($text[0] === '(') {
			$balanced = slowlog_match_balanced_parens($text);

			if ($balanced === false) {
				return;
			}

			slowlog_extract_tables_from_query($balanced[0], $tables);

			// $balanced[1] begins with the derived table's own "[AS] alias ON
			// ..." - that alias names the subquery just extracted, not a real
			// table, so fall through to the JOIN-keyword search below instead
			// of looping back and mistaking the alias for an identifier.
			$text = $balanced[1];
		} else {
			$id = slowlog_first_identifier($text);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}

		if (!preg_match('/\s+' . SLOWLOG_JOIN_KEYWORD . '\s+/i', $text, $m, PREG_OFFSET_CAPTURE)) {
			return;
		}

		$text = substr($text, $m[0][1] + strlen($m[0][0]));
	}
}

/**
 * a comma-separated table-reference list, e.g. a FROM clause or an UPDATE target list
 *
 * Called from slowlog_extract_from_clauses(),
 * slowlog_extract_using_clause_tables(), and
 * slowlog_extract_tables_from_query() to extract every table referenced
 * in a table-reference list.
 *
 * @param string                $text   The table-reference-list text to parse.
 * @param array<string, string> $tables Reference, the de-duplicating
 *                                      set of discovered table names
 *                                      being built up (name => name).
 *
 * @return void
 */
function slowlog_extract_table_ref_list(string $text, array &$tables): void {
	$text = trim($text);

	if ($text === '') {
		return;
	}

	foreach (slowlog_split_top_level($text, ',') as $part) {
		$part = trim($part);

		if ($part === '') {
			continue;
		}

		if (preg_match('/^(.*?)\s+' . SLOWLOG_JOIN_KEYWORD . '\s+(.*)$/is', $part, $m)) {
			slowlog_extract_single_table_ref($m[1], $tables);
			slowlog_extract_join_chain($m[2], $tables);
		} else {
			slowlog_extract_single_table_ref($part, $tables);
		}
	}
}

/**
 * Finds every FROM keyword in $query - at any nesting depth - and extracts its
 * table-reference list. Overlap with subqueries discovered elsewhere (e.g. via a JOIN
 * target) is intentional and harmless, since $tables is a de-duplicating set.
 *
 * Called from slowlog_extract_tables_from_query() for SELECT/DELETE-style
 * statements.
 *
 * @param string                $query  The (masked) query text to scan.
 * @param array<string, string> $tables Reference, the de-duplicating
 *                                      set of discovered table names
 *                                      being built up (name => name).
 *
 * @return void
 */
function slowlog_extract_from_clauses(string $query, array &$tables): void {
	$offset = 0;
	$len    = strlen($query);

	while ($offset < $len && preg_match('/\bFROM\b/i', $query, $m, PREG_OFFSET_CAPTURE, $offset)) {
		$pos   = $m[0][1];
		$start = $pos + 4;
		$end   = slowlog_scan_clause_span($query, $start);

		slowlog_extract_table_ref_list(substr($query, $start, $end - $start), $tables);

		$offset = max($end, $pos + 4);
	}
}

/**
 * finds every JOIN keyword in $query and extracts the table (or derived subquery) it targets
 *
 * Called from slowlog_extract_tables_from_query() as an additional pass
 * to catch JOIN targets not already found via slowlog_extract_from_clauses().
 *
 * @param string                $query  The (masked) query text to scan.
 * @param array<string, string> $tables Reference, the de-duplicating
 *                                      set of discovered table names
 *                                      being built up (name => name).
 *
 * @return void
 */
function slowlog_extract_join_targets(string $query, array &$tables): void {
	if (!preg_match_all('/\b' . SLOWLOG_JOIN_KEYWORD . '\s+/i', $query, $m, PREG_OFFSET_CAPTURE)) {
		return;
	}

	foreach ($m[0] as $match) {
		$after = ltrim(substr($query, $match[1] + strlen($match[0])));

		if ($after !== '' && $after[0] === '(') {
			$balanced = slowlog_match_balanced_parens($after);

			if ($balanced !== false) {
				slowlog_extract_tables_from_query($balanced[0], $tables);

				continue;
			}
		}

		$id = slowlog_first_identifier($after);

		if ($id !== '') {
			$tables[$id] = $id;
		}
	}
}

/**
 * Finds a top-level "USING <table_ref_list>" clause (the multi-table DELETE FROM ... USING
 * form) and extracts every table it lists, including comma-separated ones. A JOIN's
 * "USING (col1, col2)" column list is skipped instead, since it's always immediately
 * followed by an open paren rather than a bare identifier.
 *
 * Called from slowlog_extract_tables_from_query() for DELETE-style
 * statements.
 *
 * @param string                $query  The (masked) query text to scan.
 * @param array<string, string> $tables Reference, the de-duplicating
 *                                      set of discovered table names
 *                                      being built up (name => name).
 *
 * @return void
 */
function slowlog_extract_using_clause_tables(string $query, array &$tables): void {
	$offset = 0;
	$len    = strlen($query);

	while ($offset < $len && preg_match('/\bUSING\b/i', $query, $m, PREG_OFFSET_CAPTURE, $offset)) {
		$pos    = $m[0][1];
		$after  = substr($query, $pos + 5);
		$padlen = strlen($after) - strlen(ltrim($after));
		$start  = $pos + 5 + $padlen;

		if ($start >= $len || $query[$start] === '(') {
			$offset = $pos + 5;

			continue;
		}

		$end = slowlog_scan_clause_span($query, $start);

		slowlog_extract_table_ref_list(substr($query, $start, $end - $start), $tables);

		$offset = max($end, $pos + 5);
	}
}

/**
 * Determines every table referenced by a (normalized, single-line) SQL statement:
 * SELECT/DELETE FROM lists, JOINs (including chains and derived tables), INSERT/REPLACE
 * INTO, UPDATE ... SET (including JOIN'd targets), TRUNCATE TABLE, CREATE TABLE (including its
 * source table when cloned via LIKE), DROP TABLE, ALTER TABLE, ANALYZE/OPTIMIZE/CHECK/REPAIR
 * TABLE, RENAME TABLE ... TO ..., FLUSH TABLE(S), LOAD DATA ... INTO TABLE, and SHOW
 * TABLES/COLUMNS/INDEX/CREATE TABLE. Subqueries are followed recursively wherever they're found.
 *
 * Called from get_table_associations() for each logged query, and
 * recursively by itself/the extraction helpers when descending into a
 * derived table's subquery.
 *
 * @param mixed                 $query  The raw query text to
 *                                      analyze.
 * @param array<string, string> $tables Reference, the de-
 *                                      duplicating set of discovered
 *                                      table names being built up
 *                                      (name => name); defaults to an
 *                                      empty array.
 *
 * @return array<string, string> The de-duplicated set of table names
 *                               found (also reflected via the $tables
 *                               reference).
 */
function slowlog_extract_tables_from_query($query, array &$tables = []): array {
	$query = slowlog_normalize_query_text(slowlog_mask_strings_and_comments((string) $query));

	if ($query === '') {
		return $tables;
	}

	if (preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^UPDATE\s+(.*?)\s+SET\b/is', $query, $m)) {
		slowlog_extract_table_ref_list($m[1], $tables);
	} elseif (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $query, $m)) {
		$rest = substr($query, strlen($m[0]));
		$id   = slowlog_first_identifier($rest);

		if ($id !== '') {
			$tables[$id] = $id;
		}

		// "CREATE TABLE new LIKE existing" clones another table's structure - only look for a
		// top-level LIKE (before any column-definition parenthesis), so a LIKE comparison buried
		// inside a column's DEFAULT/CHECK expression isn't mistaken for this clause.
		$paren_pos = strpos($rest, '(');

		if (preg_match('/\bLIKE\s+/i', $rest, $lm, PREG_OFFSET_CAPTURE) && ($paren_pos === false || $lm[0][1] < $paren_pos)) {
			$like_id = slowlog_first_identifier(substr($rest, $lm[0][1] + strlen($lm[0][0])));

			if ($like_id !== '') {
				$tables[$like_id] = $like_id;
			}
		}
	} elseif (preg_match('/^DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?/i', $query, $m)) {
		foreach (slowlog_split_top_level(substr($query, strlen($m[0])), ',') as $t) {
			$id = slowlog_first_identifier($t);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}
	} elseif (preg_match('/^ALTER\s+TABLE\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^(?:ANALYZE|OPTIMIZE|CHECK|REPAIR)\s+(?:NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLE\s+/i', $query, $m)) {
		foreach (slowlog_split_top_level(substr($query, strlen($m[0])), ',') as $t) {
			$id = slowlog_first_identifier($t);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}
	} elseif (preg_match('/^RENAME\s+TABLE\s+(.*)$/i', $query, $m)) {
		foreach (slowlog_split_top_level($m[1], ',') as $pair) {
			if (preg_match('/^\s*(.+?)\s+TO\s+(.+)\s*$/i', $pair, $pm)) {
				$from = slowlog_first_identifier($pm[1]);
				$to   = slowlog_first_identifier($pm[2]);

				if ($from !== '') {
					$tables[$from] = $from;
				}

				if ($to !== '') {
					$tables[$to]   = $to;
				}
			}
		}
	} elseif (preg_match('/^FLUSH\s+TABLES?\s+(.*)$/i', $query, $m)) {
		$rest = preg_replace('/\s+WITH\s+READ\s+LOCK\s*$/i', '', $m[1]) ?? '';

		foreach (slowlog_split_top_level($rest, ',') as $t) {
			$id = slowlog_first_identifier($t);

			if ($id !== '') {
				$tables[$id] = $id;
			}
		}
	} elseif (preg_match('/^LOAD\s+DATA\b/i', $query) && preg_match('/\bINTO\s+TABLE\s+/i', $query, $m, PREG_OFFSET_CAPTURE)) {
		$id = slowlog_first_identifier(substr($query, $m[0][1] + strlen($m[0][0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^SHOW\s+CREATE\s+TABLE\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	} elseif (preg_match('/^SHOW\s+(?:FULL\s+)?(?:TABLES|COLUMNS|FIELDS|INDEX(?:ES)?|KEYS)\s+(?:FROM|IN)\s+/i', $query, $m)) {
		$id = slowlog_first_identifier(substr($query, strlen($m[0])));

		if ($id !== '') {
			$tables[$id] = $id;
		}
	}

	// Always look for FROM clauses and JOINs too - covers INSERT ... SELECT ... FROM,
	// subqueries in a WHERE/SET/etc, and multi-table DELETE/UPDATE ... JOIN forms.
	slowlog_extract_from_clauses($query, $tables);
	slowlog_extract_join_targets($query, $tables);
	slowlog_extract_using_clause_tables($query, $tables);

	return $tables;
}

/**
 * Normalizes a raw table-reference string into a bare table name: strips
 * trailing ';'/')' and backticks, drops any leading schema qualifier,
 * and truncates at the first '(' (e.g. for a function-call-looking
 * fragment). Called from slowlog_first_identifier() to clean up each
 * matched identifier.
 *
 * @param string $table The raw table-reference text to normalize.
 *
 * @return string The normalized, unquoted table name.
 */
function parseTable(string $table): string {
	$table = trim($table, ';)');
	$table = str_replace('`', '', $table);
	$parts = explode('.', $table);

	// Prune the schema
	if (count($parts) > 1) {
		$table = $parts[1];
	}

	// Remove beginning braces
	$parts = explode('(', $table);

	if (count($parts) == 1) {
		return trim($table, '\'`');
	} else {
		return trim($parts[0], '\'`');
	}
}
