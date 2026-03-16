<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | Regression checks for prepared DB helper usage in selected plugin files |
 |                                                                         |
 | Run: php tests/test_prepared_statements.php                             |
 +-------------------------------------------------------------------------+
 */

$pass = 0;
$fail = 0;

function assert_true($label, $value) {
	global $pass, $fail;

	if ($value) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		$fail++;
	}
}

$setup_file    = __DIR__ . '/../setup.php';
$slowlog_file  = __DIR__ . '/../slowlog.php';
$helpers_file  = __DIR__ . '/../slowlog_functions.php';

$setup_contents   = file_get_contents($setup_file);
$slowlog_contents = file_get_contents($slowlog_file);
$helpers_contents = file_get_contents($helpers_file);

assert_true('setup.php is readable', $setup_contents !== false);
assert_true('slowlog.php is readable', $slowlog_contents !== false);
assert_true('slowlog_functions.php is readable', $helpers_contents !== false);

$setup_contents   = ($setup_contents === false ? '' : $setup_contents);
$slowlog_contents = ($slowlog_contents === false ? '' : $slowlog_contents);
$helpers_contents = ($helpers_contents === false ? '' : $helpers_contents);

assert_true(
	'setup.php uses prepared version lookup',
	preg_match('/db_fetch_cell_prepared\s*\(\s*\'SELECT version/s', $setup_contents) === 1
);
assert_true(
	'setup.php uses prepared plugin_config updates',
	preg_match('/db_execute_prepared\s*\(\s*\'UPDATE plugin_config/s', $setup_contents) >= 1
);
assert_true(
	'setup.php uses prepared realm lookup',
	preg_match('/db_fetch_cell_prepared\s*\(\s*\'SELECT id\s+FROM plugin_realms/s', $setup_contents) === 1
);
assert_true(
	'slowlog.php has no raw db_fetch_assoc calls',
	preg_match('/\bdb_fetch_assoc\s*\(/', $slowlog_contents) === 0
);
assert_true(
	'slowlog.php uses prepared db_fetch_assoc calls',
	preg_match_all('/\bdb_fetch_assoc_prepared\s*\(/', $slowlog_contents, $slowlog_matches) >= 5
);
assert_true(
	'slowlog_functions.php removed raw table insert interpolation',
	strpos($helpers_contents, "VALUES (\$logid, '\$t')") === false
);
assert_true(
	'slowlog_functions.php removed raw details table interpolation',
	strpos($helpers_contents, "WHERE logid=\$logid") === false
);
assert_true(
	'slowlog_functions.php parameterizes methodid insert values',
	strpos($helpers_contents, "SELECT '\$logid' AS logid, logentry") === false
);
assert_true(
	'slowlog_functions.php uses prepared insert for plugin_slowlog_tables',
	preg_match('/db_execute_prepared\s*\(\s*\'INSERT INTO plugin_slowlog_tables/s', $helpers_contents) === 1
);
assert_true(
	'slowlog_functions.php uses prepared insert for plugin_slowlog_details_tables',
	preg_match('/db_execute_prepared\s*\(\s*\'INSERT INTO plugin_slowlog_details_tables/s', $helpers_contents) === 1
);
assert_true(
	'slowlog_functions.php limits raw db_execute to parser replay paths',
	preg_match_all('/\bdb_execute\s*\(/', $helpers_contents, $raw_execute_matches) === 3
	&& preg_match_all('/db_execute\s*\(\s*\$sql_prefix\s*\./', $helpers_contents, $prefixed_execute_matches) === 3
);
assert_true(
	'slowlog_functions.php limits raw db_fetch_assoc to schema discovery helpers',
	preg_match_all('/\bdb_fetch_assoc\s*\(/', $helpers_contents, $raw_fetch_assoc_matches) === 2
);

echo "\n";
echo "Results: $pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
