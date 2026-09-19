## ChangeLog

--- Unreleased ---

* feature: Add `timeout` column to `plugin_slowlog_details` and new `plugin_slowlog_table_names` table-name dictionary (deduplicates table_name text, tracks whether it's a known Cacti table)
* feature: Add MAX_EXECUTION_TIME, MAX_STATEMENT_TIME, UNION ALLS, INFILES, GROUP BY, COUNTS, and SHOWS to the method dictionary
* refactor: Replace raw `CREATE TABLE` statements in `setup.php` with `api_plugin_db_table_create()`/`api_plugin_db_add_column()`, and re-run schema sync during upgrade instead of only on install

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
