# GitHub Copilot Instructions

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: This is a Cacti plugin (`slowlog`, version 2.1) targeting Cacti 1.2.23+
2. **Context Files**: Prioritize patterns and standards defined in this file (`.github/copilot-instructions.md`)
3. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns
4. **Architectural Consistency**: Maintain plugin-based architecture extending Cacti core
5. **Code Quality**: Prioritize security, maintainability, and compatibility in all generated code

## Technology Stack

### Core Technologies
- **PHP**: Compatible with Cacti 1.2.x supported versions
- **Platform**: Cacti Plugin Architecture (MySQL/MariaDB Slow Query Log Viewer)
- **Database**: MySQL/MariaDB

### Key Dependencies
- Cacti core framework (`api_plugin_*`, `db_*`)
- `js/` chart rendering for slow-query analysis views
- `keywords.txt` reserved-word reference used by the log parser

## Project Structure

```
slowlog/                   # Repository root (install to plugins/slowlog/ in Cacti)
├── images/                  # UI icons
├── js/                        # Chart rendering client-side code
├── locales/                     # Internationalization files
├── tests/                         # Test suite
├── themes/                          # CSS theme overlays
├── import_log.php                     # CLI slow-query-log importer
├── keywords.txt                         # SQL reserved-word list used by the parser
├── slowlog.php                            # Main viewer/administration UI
├── slowlog_functions.php                    # Log parsing, import, and charting logic
├── INFO                                       # Plugin metadata (name, version, compat)
├── README.md
└── setup.php                                   # Plugin install/uninstall/upgrade hooks
```

## Naming Conventions

### Function Names
- **Plugin lifecycle/hook-registration functions** MUST be prefixed `plugin_slowlog_`: `plugin_slowlog_install()`, `plugin_slowlog_upgrade()`, `plugin_slowlog_version()`.
- **All other functions** MUST be prefixed `slowlog_`: `slowlog_check_upgrade()`, `slowlog_setup_table_new()`, `slowlog_import()`.
- **Public save/remove APIs** use the `api_slowlog_` prefix: `api_slowlog_save()`, `api_slowlog_remove()`.
- Match the existing prefix used by the function you are editing; do not introduce a fourth naming scheme.

### Database Tables
All plugin tables are prefixed `plugin_slowlog` (see `plugin_slowlog_uninstall()`):

```
plugin_slowlog, plugin_slowlog_details, plugin_slowlog_details_methods,
plugin_slowlog_details_tables, plugin_slowlog_methods, plugin_slowlog_tables,
plugin_slowlog_table_names, plugin_slowlog_reserved_words
```

## Code Style

### Indentation and Formatting
- **Tabs**: Use tabs (not spaces) for indentation throughout all PHP files.
- **Braces**: Opening brace on the same line for functions and control structures.
- **Spacing**: Space after control structure keywords (`if`, `foreach`, `while`).

### File Headers
ALL PHP files MUST include the standard GPL v2 license header used throughout this repository (see `setup.php`), crediting "The Cacti Group".

## Security Standards

### SQL Query Security
Use prepared statements for anything involving variable input:

```php
// CORRECT
api_slowlog_remove($logid); // internally uses db_execute_prepared()

// WRONG - never do this with request-derived values
db_execute("DELETE FROM plugin_slowlog WHERE logid = $logid");
```

### Log Import Handling
`import_logfile()`/`slowlog_import()` parse arbitrary uploaded/imported slow-query-log text. Treat log contents as untrusted: never `eval()` or directly execute parsed queries, and use `keywords.txt`-driven tokenizing (`is_reserved_word()`) rather than ad hoc regex that could mis-parse crafted input.

### Input Validation
Use `get_filter_request_var()` / `get_nfilter_request_var()` for request input; never read `$_GET`/`$_POST` directly.

`get_filter_request_var()` (and its `gfrv()` shorthand, where available) called with only the
`$name` argument (no regex/filter as the 2nd/3rd argument) already validates the value as numeric
and returns it as a **string** -- it does not return an int, and it halts execution if the request
value is not numeric. Because of this, do NOT cast its output to `(int)` when the result is only
used for string output (e.g. `print`/`echo`, string concatenation, embedding in HTML/JS); the cast
is redundant. Only cast when the value is genuinely used in an integer/numeric context (e.g.
arithmetic, strict `===` comparisons).

## Database Operations

### Table Creation
Use `slowlog_setup_table_new()` (`setup.php`) to build a Cacti table-definition array (`$data['columns']`,
`$data['primary']`, `$data['keys']`, `$data['unique_keys']`, `$data['type']`, `$data['row_format']`,
`$data['comment']`) and pass it to `api_plugin_db_table_create('slowlog', $table, $data)`. Never write raw
`CREATE TABLE` SQL - `api_plugin_db_table_create()` is a no-op when the table already exists, so it is safe
to call on every install/upgrade.

### Upgrade Handling
`slowlog_check_upgrade()` (`setup.php`) version-gates against the stored `plugin_config` row, then re-calls
`slowlog_setup_table_new()` on a version change. New columns added to an existing table must also be applied
via `api_plugin_db_add_column('slowlog', $table, $column)` inside `slowlog_setup_table_new()` (in addition to
being listed in the table's `$data['columns']` for fresh installs) - it's a no-op when the column already
exists, so re-running it on every upgrade is the mechanism that carries existing installs forward.

## Internationalization

ALL user-facing strings MUST use `__()` with the `'slowlog'` text domain.

## Plugin Architecture

### Plugin Hooks
Register all plugin hooks in `plugin_slowlog_install()` (`setup.php`):

```php
api_plugin_register_hook('slowlog', 'config_arrays',         'slowlog_config_arrays',        'setup.php');
api_plugin_register_hook('slowlog', 'draw_navigation_text',  'slowlog_draw_navigation_text', 'setup.php');
api_plugin_register_hook('slowlog', 'config_settings',       'slowlog_config_settings',      'setup.php');
api_plugin_register_hook('slowlog', 'top_header_tabs',       'slowlog_show_tab',             'setup.php');
api_plugin_register_hook('slowlog', 'top_graph_header_tabs', 'slowlog_show_tab',             'setup.php');

api_plugin_register_realm('slowlog', 'slowlog.php', 'Plugin -> MySQL Slow Log Viewer', 1);
```

## Best Practices

1. Never execute or `eval()` any content parsed out of an imported slow query log.
2. Use the `api_slowlog_*` functions for save/remove rather than inlining SQL in UI pages.
3. Wrap all user-facing strings with `__('text', 'slowlog')`.

## Static Analysis Tooling (PHPStan / PHP-CS-Fixer)

This plugin has no per-repo `composer.json`, `phpstan.neon`, `phpstan-stubs.php`, or
`.php-cs-fixer.php`, and none should be added/committed. When asked to run a PHPStan/
PHP-CS-Fixer pass (level 8 typing, style fixes, etc.), set up composer + phpstan/
php-cs-fixer + a stub file for unresolvable Cacti core symbols as **scratch tooling only**
(e.g. outside the repo, or in files deleted before the final commit), adapted from Cacti
core's own `.phpstan.neon`/`.php-cs-fixer.php` baseline. Only commit the resulting source
changes (type annotations, genuine bug fixes, formatting) to the plugin's actual PHP
files - never the tooling config/stub/lockfiles themselves. This applies to every
`plugin_*` repo, not just this one.

## Common Pitfalls to Avoid

```php
// WRONG - concatenating a parsed query fragment into a live SQL statement
db_execute("EXPLAIN " . $parsed_query);

// CORRECT - store/display parsed text only; never re-execute untrusted log content
$stored_query = db_qstr($parsed_query);
```

## Version Control

Document all changes in `CHANGELOG.md`; use descriptive commit messages referencing issue/PR numbers when applicable.

## CI & Dependency Baselines

- Do not commit a `composer.json` or `composer.lock` in this plugin's own repo root — the shared CI workflow installs Pest/dev dependencies into Cacti's own Composer-managed vendor tree (checked out alongside the plugin). Use Cacti's `composer.json`, not a plugin-local one.
- Do not add a plugin-local `.phpstan.neon`/`phpstan.neon` or `.php-cs-fixer.php`/`.php-cs-fixer.dist.php` — lint/static-analysis steps run against Cacti's own config from the Cacti core checkout, targeting this plugin's directory. Use the Cacti version, not a plugin-local config.
- Prefer Cacti's `cacti_count()`/`cacti_sizeof()` wrappers over the raw `count()`/`sizeof()` builtins in new or edited code.

## Internationalization (i18n)

- Translatable strings are managed with GNU gettext via `locales/build_gettext.sh`. `locales/po/cacti.pot` is the source template; Weblate owns syncing the per-language `.po`/`.mo` files from it.
- When a pull request adds or changes a string wrapped in `__()`/`__n()`/`__esc()`/`__x()`/`__xn()`/`__gettext()`, run `locales/build_gettext.sh` before pushing and add the resulting change to `locales/po/cacti.pot` only. Do not commit the regenerated per-language `.po`/`.mo` files in the same PR — Weblate takes care of the rest.

## References

- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [Cacti Documentation](https://www.github.com/Cacti/documentation)
- `README.md` for feature descriptions

## Security & Quality Conventions

These conventions apply across the Cacti plugin fleet and should be followed whenever touching
existing code or adding new code, not just in dedicated cleanup passes:

- **No hardcoded third-party hosts.** Never hardcode a third-party IP address, hostname, or URL
  in plugin code (even for tooling/download helpers). Expose it as a plugin setting instead, with
  secure-by-default values (e.g. an SSL-verification setting that defaults to verify-on).
- **Prepared statements over `db_qstr()`.** Build dynamic `WHERE` clauses using the
  `$sql_where`/`$sql_params` prepared-statement pattern, not string concatenation via `db_qstr()`.
- **Use `html_escape_request_var()`.** Prefer it over the `html_escape(get_request_var(...))` call
  chain.
- **Harden `unserialize()`.** Always pass `['allow_classes' => false]` as the second argument.
- **i18n text domain.** Every `__()`/`__esc()` call must include this plugin's text domain as the
  final argument, except when deliberately comparing against a literal, untranslated Cacti-core
  label.
- **Plugin table-creation API.** Use `api_plugin_db_table_create()`/`api_plugin_db_add_column()`
  (from Cacti core's `lib/plugins.php`) instead of raw `CREATE TABLE`/`ALTER TABLE ... ADD COLUMN`.
  Both are idempotent (safe no-ops when already applied), so the same call can run unconditionally
  from both the install AND upgrade paths.
- **PHPDoc shape.** Every function gets a PHPDoc block: a one-line description, a blank comment
  line, `@param` lines, a blank comment line, then `@return`. Infer parameter/return types from
  actual usage; don't change the function's real type-hints in the same pass (let static analysis
  flag mismatches separately). Skip vendored third-party library files.
