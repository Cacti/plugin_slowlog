## ChangeLog

--- 2.4 ---

* chore: Harmonize CI workflow, issue/PR templates, and PHP-compatibility test structure with the shared Cacti plugin baseline
* feature: Add `plugin_slowlog_stats` cache table storing per-method/per-table box-whisker statistics (min/p25/median/p75/p95/max) and totals for query_time, rows_sent, rows_examined, rows_affected, and bytes_sent, computed once during import/reprocess instead of aggregated live on each chart view
* feature: Add box-whisker (rate distribution) charts alongside the existing raw-totals charts on the By Method/By Table pages
* feature: The By Method/By Table raw-totals charts now read from the `plugin_slowlog_stats` cache instead of live-aggregating `plugin_slowlog_details` on every view, matching the box-whisker chart; falls back to the old live aggregation for logs imported before the cache existed, so upgrading doesn't blank their charts
* refactor: Move the chart-data functions (slowlog_chart_measures/slowlog_get_chart_object/slowlog_get_stats_chart_object) from slowlog.php into slowlog_functions.php - slowlog.php's top-level request dispatch code makes it unsafe to load in isolation, so those functions previously had zero executable test coverage; they're now covered by tests/Unit/ChartDataTest.php
* bug: api_slowlog_remove() (deleting an imported log) now also clears its plugin_slowlog_stats rows, so removing a log no longer leaves orphaned stats-cache entries behind; also moved into slowlog_functions.php alongside the other per-logid cleanup logic so it's covered by tests/Unit/SlowlogRemoveTest.php
* bug: The stats-cache collectors now page on a unique surrogate key (plugin_slowlog_details_methods.id / plugin_slowlog_details_tables.tableid) instead of the non-unique logentry column - a batch boundary landing inside a group of same-logentry method/table matches previously caused the remaining rows in that group to be silently skipped
* bug: Store the CREATES/CREATE TEMPS method-dictionary fragments lowercase (matching is case-insensitive) so the literal string "CREATE TABLE" doesn't appear in setup.php next to actual DDL usage
* security: Chart titles (built from the user-supplied import description) are now emitted via json_encode() with JSON_HEX_* flags instead of raw string concatenation into the inline chart-rendering <script> block, preventing a crafted description from breaking out of the JS string/script context
* feature: The Import Logfile page now shows the current max_execution_time/memory_limit alongside the existing upload_max_filesize/post_max_size, and a WARNING banner listing anything in the current server configuration likely to cause a large import to fail (non-unlimited execution time/memory, post_max_size smaller than upload_max_filesize, or a very small upload_max_filesize)
* feature: The import form now shows a live upload progress indicator (an ApexCharts donut with the percentage in its center, alongside Pace.js's existing top-of-page bar), driven by the browser's native xhr.upload.progress event - no php.ini session.upload_progress setting or web server buffering configuration required

--- 2.3 ---

* feature: Add FORCE INDEX to the method dictionary so queries using an index hint can be filtered/found in the Methods view
* bug: Recognize CREATE TABLE (including its LIKE source table), DROP TABLE, ALTER TABLE, and ANALYZE/OPTIMIZE/CHECK/REPAIR TABLE in the tokenizer - these administrative statements previously produced no table association at all
* feature: Add ALTERS, DROPS, ANALYZES, and OPTIMIZES to the method dictionary
* feature: Add CREATES and CREATE TEMPS to the method dictionary, distinguishing permanent from temporary CREATE TABLE statements

--- 2.2 ---

* bug: Rewrite the query tokenizer (get_table_associations()) as a regex/scanner-based parser - fixes dropped JOIN targets, dropped comma-separated FROM list members, and subqueries in WHERE/SET clauses not being followed
* feature: Add `timeout` column to `plugin_slowlog_details` and new `plugin_slowlog_table_names` dictionary table tracking whether each table seen in an import is a known Cacti table
* feature: Add MAX_EXECUTION_TIME, MAX_STATEMENT_TIME, UNION ALLS, INFILES, GROUP BY, COUNTS, SHOWS, and OTHER TABLES to the method dictionary
* feature: Replace the "Use this Cacti Database" import checkbox with a 3-option table-detection dropdown (detect all tables [default], use this Cacti Database, or compare against a reference list), grouping non-matching tables under the new OTHER TABLES method
* bug: "Use this Cacti Database" table detection now runs the tokenizer so tables outside the local Cacti schema are actually discovered and can be grouped as OTHER TABLES, instead of only ever scanning for tables already known to be in the Cacti schema
* refactor: Replace raw `CREATE TABLE` statements in `setup.php` with `api_plugin_db_table_create()`/`api_plugin_db_add_column()`, and re-run schema sync during upgrade instead of only on install
* bug: Bump the plugin version so `slowlog_check_upgrade()` actually re-runs schema sync for existing 2.1 installs instead of silently skipping it forever
* security: Parameterize the bulk `plugin_slowlog_details_methods`/OTHER TABLES inserts instead of interpolating logid/logentry/methodid into raw SQL
* bug: Classify method associations in bounded chunks instead of loading an entire log's query text into memory at once
* bug: `get_table_associations()`'s FROM/JOIN scanner and the MAX_EXECUTION_TIME/MAX_STATEMENT_TIME timeout regexes now ignore string literals and comments, instead of mistaking SQL-looking text inside either one for a real table reference or timeout hint
* bug: 'reference' mode table classification no longer overwrites the shared `plugin_slowlog_table_names.is_cacti_table` flag with a per-import reference list - it's compared per-log instead, so one reference import can no longer corrupt OTHER TABLES classification for another log that shares a table name
* bug: Add a `--table-names` CLI option and forward the table list to the background post-processing worker, so `--table-mode=reference`/list-mode imports no longer silently lose their reference list via `--logfile` or batch/background processing

--- 2.1 ---

* security: Migrate remaining slowlog SQL helpers (setup, upgrade, and post-processing queries) to prepared statements
* feature: Adopt a Pest-based test suite and Cacti CI workflow, replacing the standalone test scripts
* security: Fix potential security exposure with unserialize() function
* issue#2: Warnings issue when attempting to import a Slowlog from the CLI
* issue: Add index to the main table to improve performance
* feature: Allow summarizing the Details page by method and by table
* feature: Add Time-span selector to details page
* feature: Switch from Billboard.js to ApexCharts due to lack of feature support in Billboard.js
* feature: Upgrade ApexCharts to v3.50.0
* feature: Switch to the Aria engine for performance
* feature: Update the keywords.txt file for latest MySQL keywords

--- 2.0 ---

* feature: Compatibility with Cacti 1.2.x
* feature: Faster slowlog post-processing
* feature: Move from flash charts to Billboard.js

--- 1.3 ---

* feature: Allow the use of the local Cacti Database as Prototype

--- 1.2 ---

* bug: Pagination and 'clear' filters not working as expected when filter dropdowns change
* bug: Pasting lists from Linux does not show tables on the table side

--- 1.0 ---

* Initial release

-----------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
