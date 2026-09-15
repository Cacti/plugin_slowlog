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
plugin_slowlog_reserved_words
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

## Database Operations

### Table Creation
Use `slowlog_setup_table_new()` (`setup.php`) with `api_plugin_db_table_create()`-style guarded `CREATE TABLE IF NOT EXISTS` statements.

### Upgrade Handling
Version-gate schema changes in `slowlog_check_upgrade()` (`setup.php`) against the stored `plugin_config` row.

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

## Common Pitfalls to Avoid

```php
// WRONG - concatenating a parsed query fragment into a live SQL statement
db_execute("EXPLAIN " . $parsed_query);

// CORRECT - store/display parsed text only; never re-execute untrusted log content
$stored_query = db_qstr($parsed_query);
```

## Version Control

Document all changes in `CHANGELOG.md`; use descriptive commit messages referencing issue/PR numbers when applicable.

## References

- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [Cacti Documentation](https://www.github.com/Cacti/documentation)
- `README.md` for feature descriptions
