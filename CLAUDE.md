# Network Incident Manager — Claude Instructions

## Plugin overview

Custom WordPress plugin that manages network incidents using **dedicated database tables** (not Custom Post Types).

## File structure

```
network-incident-manager/
├── network-incident-manager.php   ← Main plugin file (all PHP logic)
├── assets/
│   ├── css/frontend.css           ← Default frontend styles (loaded only without theme override)
│   └── js/admin.js                ← Admin list page: inline AJAX status update
├── languages/
│   ├── network-incident-manager-fr_FR.po   ← Edit this for French translations
│   └── network-incident-manager-fr_FR.mo   ← Compiled binary — always recompile after editing .po
├── templates/
│   ├── page-incidents.php         ← Main frontend template (/incidents/ URL)
│   └── parts/
│       ├── incident-active.php    ← Card for In Progress incidents
│       ├── incident-scheduled.php ← Row for Scheduled incidents
│       └── incident-resolved.php  ← Row for Resolved incidents
├── changelog.txt
├── readme.txt
└── CLAUDE.md
```

## Database tables

| Table | Purpose |
|---|---|
| `wp_incidents` | All incident data |
| `wp_incident_apps` | Hierarchical application list |

### `wp_incidents` columns
`id`, `reference`, `description`, `severity`, `status`, `app_id`, `author_id`, `start_at`, `created_at`, `updated_at`

All datetimes are stored in **UTC**. Use `current_time('mysql', true)` to write and `strtotime($val . ' UTC')` + `wp_date()` to display.

### `wp_incident_apps` columns
`id`, `name`, `parent_id`, `created_at`

## Valid values

**Severity:** `Minor` | `Major` | `Critical`

**Status:** `Scheduled` | `In Progress` | `Resolved`

When adding a new status or severity, update ALL of the following:
1. `$allowed_status` arrays in `nim_handle_save()`, `nim_api_create()`, `nim_api_update()`, `nim_ajax_update_status()`
2. `$status_opts` arrays in `nim_page_list()` and `nim_page_edit()`
3. `$allowed` in `nim_ajax_update_status()`
4. WHERE clauses in `templates/page-incidents.php`
5. CSS class in `assets/css/frontend.css` (`.nim-status--{slug}`)
6. Translation strings in `languages/network-incident-manager-fr_FR.po`
7. Recompile `.mo`: `studio wp i18n make-mo languages/network-incident-manager-fr_FR.po`

## Version bumping

When changing the database schema:
1. Increment `NIM_VERSION` in both the plugin header comment and `define('NIM_VERSION', ...)`
2. `nim_maybe_upgrade()` will detect the version mismatch on next page load and run `dbDelta` automatically — no manual reactivation needed

## Timezone rule

- **Store:** `current_time('mysql', true)` → UTC string
- **Display:** `wp_date(format, strtotime($utc_string . ' UTC'))`
- **Form input (datetime-local):** convert local → UTC with `new DateTime($input, wp_timezone())` then `setTimezone(new DateTimeZone('UTC'))`

## Template override system

`nim_get_template_part($slug, $data)` loads templates in this order:
1. `{theme}/nim/{slug}.php`
2. `{theme}/nim-{slug}.php`
3. `{plugin}/templates/parts/{slug}.php`

Variables are passed via `extract($data, EXTR_SKIP)`. Always use `$incident` as the variable name.

## Adding a new template part

1. Create `templates/parts/{slug}.php`
2. Call it with `nim_get_template_part('{slug}', ['incident' => $incident])`
3. Document the theme override path in `readme.txt`

## Auto-transition

`nim_auto_transition_statuses()` runs:
- On every `/incidents/` page load (in `nim_template_include`)
- Hourly via WP-Cron hook `nim_cron_transition`

Logic: `UPDATE wp_incidents SET status = 'In Progress' WHERE status = 'Scheduled' AND start_at <= NOW(UTC)`

## REST API

| Method | Route | Auth |
|---|---|---|
| GET | `/wp-json/network-incidents/v1/list` | Public |
| POST | `/wp-json/network-incidents/v1/incidents` | `edit_posts` |
| PUT | `/wp-json/network-incidents/v1/incidents/{id}` | `edit_posts` |

## AJAX

Action: `nim_update_status` — updates status of a single incident inline from the admin list.
Nonce: `nim_update_status` (created per page load, verified server-side).

## i18n workflow

All user-visible strings use `__( 'English string', 'network-incident-manager' )`.

After editing the `.po` file:
```bash
studio wp i18n make-mo languages/network-incident-manager-fr_FR.po
```

## SQLite constraints (WordPress Studio)

- No `ON UPDATE CURRENT_TIMESTAMP` — update `updated_at` manually
- No `FULLTEXT` indexes
- No `DB_NAME` / `DB_HOST` constants — use `$wpdb` directly
- `wp shell` not supported — use `studio wp eval '...'`
