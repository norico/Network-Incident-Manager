# Network Incident Manager — Claude Instructions

## Plugin overview

Custom WordPress plugin (v2.5.1) that manages network incidents using **dedicated database tables** (not Custom Post Types). Fully object-oriented since v2.4.0.

## File structure

```
network-incident-manager/
├── network-incident-manager.php        ← Bootstrap: constants, require NIM_Plugin, activation hooks, compat shim
├── includes/
│   ├── class-nim-plugin.php            ← Singleton orchestrator: loads classes, wires hooks
│   ├── class-nim-helpers.php           ← Shared constants (SEVERITIES, STATUSES) + utility methods
│   ├── class-nim-db.php                ← DB install, maybe_upgrade(), drop_legacy_columns()
│   ├── class-nim-cron.php              ← Cron schedule/unschedule, auto_transition()
│   ├── class-nim-frontend.php          ← Rewrite rules, template loader, asset enqueue, get_template_part()
│   ├── class-nim-ajax.php              ← wp_ajax_nim_update_status handler
│   ├── class-nim-rest-api.php          ← REST routes + callbacks (list, create, update)
│   └── class-nim-admin.php             ← Admin menu, all pages, form handlers, asset enqueue
├── assets/
│   ├── css/frontend.css                ← Default frontend styles (loaded only without theme override)
│   └── js/admin.js                     ← Admin list page: inline AJAX status update
├── languages/
│   ├── network-incident-manager-fr_FR.po   ← Edit this for French translations
│   └── network-incident-manager-fr_FR.mo   ← Compiled binary — always recompile after editing .po
├── templates/
│   ├── page-incidents.php              ← Main frontend template (/incidents/ URL)
│   └── parts/
│       ├── incident-active.php         ← Card for In Progress incidents
│       ├── incident-scheduled.php      ← Card for Scheduled incidents (header: badge+app / title / description? / date)
│       └── incident-resolved.php       ← Row for Resolved incidents
├── changelog.txt
├── readme.txt
└── CLAUDE.md
```

## Architecture: OOP class map

| Class | Responsibility | Key methods |
|---|---|---|
| `NIM_Plugin` | Singleton orchestrator | `instance()`, `activate()`, `deactivate()`, `load_textdomain()` |
| `NIM_Helpers` | Shared utilities | `parse_start_at()`, `get_column_names()`, `get_descendant_ids()`, `apps_options_html()`, `severity_label()`, `status_label()` |
| `NIM_DB` | Database lifecycle | `install()`, `maybe_upgrade()`, `drop_legacy_columns()`, `update_status()`, `table()`, `apps_table()`, `get_all_incidents_admin()`, `get_all_apps_admin()`, `get_active_incidents()`, `get_scheduled_incidents()`, `get_resolved_incidents()` |
| `NIM_Cron` | WP-Cron | `schedule()`, `unschedule()`, `auto_transition()` |
| `NIM_Frontend` | Public-facing | `register_rewrite_rules()`, `template_include()`, `enqueue_assets()`, `get_template_part()`, `shortcode()` |
| `NIM_Ajax` | Admin AJAX | `update_status()` |
| `NIM_REST_API` | REST API | `register_routes()`, `list_incidents()`, `create_incident()`, `update_incident()` |
| `NIM_Admin` | WP-Admin | `register_menu()`, `page_list/edit/apps_list/app_edit()`, `handle_*_save/delete()` |

## Database tables

| Table | Purpose |
|---|---|
| `wp_incidents` | All incident data |
| `wp_incident_apps` | Hierarchical application list |

### `wp_incidents` columns
`id`, `reference`, `description`, `severity`, `status`, `app_id`, `author_id`, `start_at`, `resolved_at`, `created_at`, `updated_at`

`resolved_at` is set automatically when status transitions to Resolved (via `NIM_DB::update_status()`), and cleared when re-opened. Use `COALESCE(resolved_at, updated_at)` in queries for backward compatibility with pre-2.5.0 rows.

All datetimes stored in **UTC**. Use `current_time('mysql', true)` to write, `strtotime($val . ' UTC')` + `wp_date()` to display.

### `wp_incident_apps` columns
`id`, `name`, `parent_id`, `created_at`

## Valid values — single source of truth

```php
NIM_Helpers::SEVERITIES   // ['Minor', 'Major', 'Critical']
NIM_Helpers::STATUSES     // ['Scheduled', 'In Progress', 'Resolved']
NIM_Helpers::MAX_APP_DEPTH // 10 — max recursion depth for apps dropdown
```

For translated labels, use the dedicated helpers (these use literal strings extractable by `make-pot`):
```php
NIM_Helpers::severity_label( $severity ); // 'Minor' → __('Minor', NIM_TD)
NIM_Helpers::status_label( $status );     // 'In Progress' → __('In Progress', NIM_TD)
```
Never use `__( $variable, NIM_TD )` — dynamic strings are invisible to i18n extraction tools.

**When adding a new status or severity**, update ALL of the following:
1. `NIM_Helpers::SEVERITIES` or `NIM_Helpers::STATUSES` constants
2. `NIM_Helpers::severity_label()` or `NIM_Helpers::status_label()` map
3. `$status_opts` / `$severity_opts` arrays in `NIM_Admin::page_list()` and `NIM_Admin::page_edit()`
4. `enum` arrays in `NIM_REST_API::register_routes()` args
5. `WHERE` clauses in `templates/page-incidents.php`
6. CSS class in `assets/css/frontend.css` (`.nim-status--{slug}` or `.nim-severity--{slug}`)
7. Translation strings in `languages/network-incident-manager-fr_FR.po`
8. Recompile `.mo`: `wp i18n make-mo languages/network-incident-manager-fr_FR.po`

## Version bumping

When changing the database schema:
1. Increment `NIM_VERSION` in `network-incident-manager.php` (header + `define`)
2. `NIM_DB::maybe_upgrade()` detects the mismatch on next page load and runs `dbDelta` + rewrite flush automatically

## Timezone rule

- **Store:** `current_time('mysql', true)` → UTC string
- **Display:** `wp_date(format, strtotime($utc_string . ' UTC'))`
- **Form input (`datetime-local`):** use `NIM_Helpers::parse_start_at($input)` — validates format and converts local → UTC

## Template override system

`NIM_Frontend::get_template_part($slug, $data)` (also available as global `nim_get_template_part()` for backward compat) loads templates in this order:
1. `{theme}/nim/{slug}.php`
2. `{theme}/nim-{slug}.php`
3. `{plugin}/templates/parts/{slug}.php`

Variables are passed via `extract($data, EXTR_SKIP)`. Always use `$incident` as the variable name.

**Never use a local `$td` variable for the text domain in template parts** — it is not injected by `extract()` and will generate `Undefined variable` warnings. Use the global constant `NIM_TD` directly.

### Adding a new template part
1. Create `templates/parts/{slug}.php`
2. Call with `NIM_Frontend::get_template_part('{slug}', ['incident' => $incident])`
3. Document the theme override path in `readme.txt`

## Auto-transition

`NIM_Cron::auto_transition()` runs:
- On every `/incidents/` page load (called from `NIM_Frontend::template_include()`)
- Hourly via WP-Cron hook `nim_cron_transition`

Logic: `UPDATE wp_incidents SET status = 'In Progress' WHERE status = 'Scheduled' AND start_at <= NOW(UTC)`

## REST API

| Method | Route | Auth | Notes |
|---|---|---|---|
| GET | `/wp-json/network-incidents/v1/list` | Public | Params: `status`, `severity`, `app_id`, `per_page` (max 100), `page`, `orderby` (start_at\|created_at\|updated_at\|severity\|status), `order` (ASC\|DESC). Headers: `X-WP-Total`, `X-WP-TotalPages` |
| POST | `/wp-json/network-incidents/v1/incidents` | `edit_posts` | |
| PUT\|PATCH | `/wp-json/network-incidents/v1/incidents/{id}` | `edit_posts` | Returns 404 if not found |
| DELETE | `/wp-json/network-incidents/v1/incidents/{id}` | `delete_posts` | Returns 204 on success, 404 if not found |

All route args use `validate_callback: rest_validate_request_arg` (never bare PHP functions like `is_numeric` — WP passes 3 args to validate callbacks).

## AJAX

Action: `nim_update_status` (class `NIM_Ajax`).
Nonce: `nim_update_status` — created in `NIM_Admin::enqueue_assets()`, verified in `NIM_Ajax::update_status()`.
Fires `do_action('nim_status_changed', $id, $old_status, $new_status)` on every status change.

## Hooks

### `nim_status_changed`
```php
do_action( 'nim_status_changed', int $id, string $old_status, string $new_status );
```
Fired in three places:
- `NIM_Cron::auto_transition()` — Scheduled → In Progress (bulk, one action per incident)
- `NIM_Ajax::update_status()` — inline admin list dropdown change
- `NIM_Admin::handle_incident_save()` — save form status field change

## Shortcode

`[nim_incidents]` — embeds the full status page (active / scheduled / resolved) inside any post or page.
Optional attribute: `resolved_limit` (integer, default 5).

Implemented in `NIM_Frontend::shortcode()`. Calls `NIM_Cron::auto_transition()` before rendering.
Does **not** call `get_header()` / `get_footer()` — outputs only the `<div id="nim-incidents-page">` wrapper.

## i18n workflow

All user-visible strings use `__( 'string', 'network-incident-manager' )` or `esc_html_e()`.

After editing `.po`:
```bash
wp i18n make-mo languages/network-incident-manager-fr_FR.po
```

To regenerate the full `.pot` from source (recommended before a release):
```bash
wp i18n make-pot . languages/network-incident-manager.pot --domain=network-incident-manager
```

## SQLite constraints (WordPress Studio)

- No `ON UPDATE CURRENT_TIMESTAMP` — update `updated_at` manually
- No `FULLTEXT` indexes
- `PRAGMA table_info()` must **not** go through `$wpdb` on Studio — the `sqlite-database-integration` mu-plugin only parses MySQL syntax. `NIM_Helpers::get_column_names()` routes PRAGMA directly to PDO
- `wp shell` not supported in Studio — use `wp eval '...'`
- `DB_ENGINE` is defined as `'sqlite'` via `db.php` (drop-in); `SQLITE_DB_DROPIN_VERSION` is also available
