# slowlog

The plugin lets a Cacti administrator import a MySQL or MariaDB slow query log into the Cacti
database and look at it: which statements are slow, which tables and kinds of statement they
touch, and how the time and row counts are spread.

## Versions and compatibility

This README describes the `main` source branch.

| Code | Plugin version | Minimum Cacti declared in `INFO` |
| --- | --- | --- |
| [Source branch](INFO) | 2.8 | 1.2.29 |
| [Published v2.1](https://github.com/Cacti/plugin_slowlog/releases/tag/v2.1) | 2.1 | 1.2.23 |

Use the published release’s assets and tagged documentation when installing that
package. Branch code can include later changes, even when its version number
matches the release. Cacti’s own PHP and database requirements also apply.

Install this plugin in **`<cacti-root>/plugins/slowlog`**, using the directory
name `slowlog` rather than `plugin_slowlog`.

See [CHANGELOG.md](CHANGELOG.md) for the version history.

## Features

* Upload a slow query log from the browser, or import one from the command line with
  `import_log.php`. The import runs in the background.
* A list of the imported logs with their import status.
* Charts per log, grouped by kind of statement (By Method) or by table (By Table): a bar chart of
  the number of queries and of the totals of query time, rows examined, rows sent, rows
  affected and bytes sent, and a box-whisker chart of the distribution of each of those five. The
  top of a box is the 95th percentile unless Include Max is checked.
* A details list of every query with sorting, paging and filters on log, method, table, user,
  host, date range and text, and a page with the full text of one query.
* Detection of the tables each statement uses, optionally grouping tables that are not in a
  list of interest as OTHER TABLES.

The analysis is deliberately simple.

## Requirements

* Cacti 1.2.29 or later in the 1.2 series (`compat` in `INFO`). The CI workflow runs against
  Cacti 1.2.x.
* MySQL or MariaDB for the Cacti database. On MariaDB the three large per-query tables use the
  Aria engine; on MySQL they use InnoDB.
* PHP as supported by your Cacti. The code uses return and nullable type declarations, which
  need PHP 7.1. The CI workflow tests PHP 8.2 through 8.5 and includes a
  non-blocking PHP 8.6 experimental job. Cacti may require a higher PHP version
  than the plugin syntax alone.
* The PHP binary that Cacti is configured to use (the `path_php_binary` setting), because uploads
  are imported by a background `php` process started with it.
* The main Cacti server. `INFO` declares no support for remote pollers (`remote_collect:0`,
  `offline_view:0`, `offline_mgmt:0`).

## Installation

Place the directory in the Cacti `plugins` directory under the name `slowlog`, then either
install and enable it under Console > Configuration > Plugin Management, or run:

```
php cli/plugin_manage.php --plugin=slowlog --install --enable
```

Installation creates the tables below, loads the SQL reserved word list from
`docs/keywords.txt`, registers viewing and management realms. Grant **Plugin -> MySQL Slow Log Viewer**
for read access and **Plugin -> MySQL Slow Log Management** as well for imports,
uploads and deletion. Manage grants through Cacti’s user/group permissions.

On upgrade to 2.8, existing viewers and their groups receive the management realm
once to preserve access. Administrators can narrow those grants afterward.

### Tables

| Table | Holds |
|---|---|
| `plugin_slowlog` | one row per imported log, with its status |
| `plugin_slowlog_details` | one row per slow query |
| `plugin_slowlog_details_methods` | the statement kinds found in each query |
| `plugin_slowlog_details_tables` | the tables found in each query |
| `plugin_slowlog_methods` | the statement kind dictionary |
| `plugin_slowlog_table_names` | every table name seen, and whether it is a Cacti table |
| `plugin_slowlog_reserved_words` | SQL reserved words used by the table detection |
| `plugin_slowlog_stats` | cached totals and distributions for the charts |

### Settings

The plugin adds no settings.

## Upgrading

Replace the files. When a page that runs the plugin's version check loads
(`plugins.php` or `slowlog.php`) and the version in `INFO` differs from the installed one, the
plugin re-runs its schema update, records the new version, and removes files that earlier
versions shipped and that are listed as tombstones in `manifest.json`. It also removes the
development-only `tests/` directory and `phpunit.xml`. A file or directory that is not listed
in `manifest.json` is left in place and reported in the Cacti log.

## Uninstalling

Disable the plugin to keep its data. Uninstalling it drops all the tables above, together with
every imported log, and removes its realms and their grants:

```
php cli/plugin_manage.php --plugin=slowlog --disable
php cli/plugin_manage.php --plugin=slowlog --uninstall
```

## Usage

### Importing a log from the browser

Open `plugins/slowlog/slowlog.php` in the Cacti web interface and follow the link in the title
bar of the filter box (`slowlog.php?action=edit`). Choose the `.log` file, give it a
description, and choose:

* **Max Query Length**: keep all of each query (All) or only the first 1024, 2048, 4096, 8192 or
  16384 bytes (the form calls them Chars; the cut is made on bytes).
* **Cacti Table Detection**: Detect all tables (the default, no OTHER TABLES grouping); use this
  Cacti database and group every other table as OTHER TABLES; or detect all tables and group
  those not in the list below as OTHER TABLES (the reference list).
* **Tables of Interest**: the reference list, separated by spaces. It is required for the
  reference mode.

The form shows the PHP upload and post size limits. The file is staged in the system temporary
directory, a row is added to the list, and a background process imports it and removes the
staged file. The list shows Pre-Processing, Post-Processing, Complete or Bad File Format, and
refreshes itself every five seconds while an import is still queued.

### Importing a log from the command line

```
php plugins/slowlog/import_log.php --logfile=/var/log/mysql/slow.log --description="db1 May"
```

| Option | Meaning |
|---|---|
| `--logfile=S` | import this file as a new log |
| `--description=S` | description to store with it (default `Imported using import_log.php`) |
| `--length=N` | keep only the first N bytes of each query, -1 for all (default) |
| `--table-mode=cacti\|reference\|all` | table detection mode, `all` by default |
| `--usecacti` | same as `--table-mode=cacti` |
| `--table-names="t1 t2"` | the reference list; required with `--table-mode=reference` |
| `--logid=N` | with `--logfile`, import into a record that already exists; alone, finish the processing of that log |
| `--reprocess=N\|all` | redo the method, table and timeout detection of one log or of every log, without the original file |
| `--delete-after` | delete the source file only after successful processing; failed inputs are retained |
| `--version`, `--help` | show the version, or the usage |

Without `--table-mode`, a `--table-names` list selects the older list mode: only the tables in
the list are searched for.

### Log formats

The importer reads the MariaDB format and the MySQL 5.7 and 8.0 format. The time of an entry is
taken from its `SET timestamp=` line, so keep the PHP time zone and the database time zone the
same. The current parser preserves bracketed IPv4/IPv6 client addresses and
does not include MySQL’s trailing `Id:` field in the address. Current schema
widths are 45 characters for an IP address, 128 for a user name, and 64 for
schema/table names; upgrades widen older columns. Longer values remain subject
to those database limits.

### Pages

* **Summary**: the imported logs, with a search box.
* **By Method** and **By Table**: the charts, with a filter for which methods or tables to
  show, the top N, and whether the box top is the 95th percentile or the maximum.
* **Details**: the queries of a log, filtered and sorted. Clicking a table, method, user or host
  filters by it.
* **Query**: the original text of one query and its timings.

### Performance and memory

The plugin raises PHP’s execution-time and memory limits for the import form,
uploads and deletion. Ordinary viewing requests retain the web-server limits;
a proxy or web server can also end a long request independently of PHP.

The CLI importer reads input incrementally and writes batches of 1000 records.
Statistics retain bounded samples for percentile estimates; counts, totals,
minima and maxima use all entries. The Details view caps pages at 1000 rows.
These bounds do not establish a fixed memory requirement for every log.

## Translations

The plugin ships translations for 21 languages in `locales/po` (source) and
`locales/LC_MESSAGES` (compiled). Run `locales/build_gettext.sh` from the plugin directory to
rebuild the POT template after a string changes; per-language catalogs remain
Weblate-managed. The script needs GNU gettext (`xgettext`, `msgmerge`, `msgfmt`)
and `realpath`.

## Development

The tests are Pest tests that run inside a Cacti checkout, with this directory as
`plugins/slowlog`. They use the Composer development dependencies of that checkout (Pest comes
with Cacti develop; the CI workflow installs its own set). Set `tests/.cacti-version` to
`1.2.x` or `develop` to match the checkout. From the Cacti root:

```
include/vendor/bin/pest --configuration=plugins/slowlog/phpunit.xml
```

With a coverage driver, the gate that the CI applies to a pull request is:

```
include/vendor/bin/pest --configuration=plugins/slowlog/phpunit.xml --coverage-clover=plugins/slowlog/coverage/clover.xml
php tests/bin/patch-coverage.php coverage/clover.xml origin/main 100
```

The second command runs from the plugin directory. Two more checks, also run from the plugin
directory, are part of the workflow:

```
php tests/bin/validate-manifest.php
php tests/bin/check-i18n-pot.php origin/main
```

`validate-manifest.php` checks that `manifest.json` lists what is in the directory.
`check-i18n-pot.php` fails when a translatable string changed and `locales/po/cacti.pot` did
not.

## Additional Help?

If you need additional help, please go to forums.cacti.net.

## Possible Bugs?

Please open an issue on GitHub with the Cacti and PHP versions and the steps to reproduce.

## Future Changes

Got any ideas or complaints, please post them on the forums.

-----------------------------------------------
Copyright (c) 2004-2026 - The Cacti Group, Inc.
