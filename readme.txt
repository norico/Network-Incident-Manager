=== Network Incident Manager ===
Contributors:       norico
Tags:               incidents, monitoring, status page, network, multisite
Requires at least:  6.3
Tested up to:       6.8
Requires PHP:       8.2
Stable tag:         2.5.3
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html
Studio Code:        WordPress Studio Code powered by Claude claude-sonnet-4-5 (Anthropic)

Documentation en français : lisezmoi.txt

Network incident manager with a dedicated database table, hierarchical applications, a public status page, and a REST API.

== Description ==

Network Incident Manager lets you declare, track and publish network incidents without using WordPress posts.
Incidents are stored in a dedicated `wp_incidents` table and displayed on a public `/incidents/` page.

= Key Features =

* **Dedicated table** — incidents stored in `wp_incidents`, not `wp_posts`
* **Incident lifecycle** — Scheduled → In Progress → Resolved, with auto-transition at the configured start date
* **Application management** — hierarchical `wp_incident_apps` table; associate incidents with applications
* **Public status page** — `/incidents/` shows active, scheduled, and recently resolved incidents
* **Template override** — themes can override any template part (see Templates section)
* **Inline status update** — change incident status directly from the admin list (AJAX)
* **REST API** — read and write incidents via `/wp-json/network-incidents/v1/`
* **Multisite-ready** — REST API endpoint can be queried across subsites
* **i18n** — fully internationalised; French (fr_FR) translation included

= Statuses =

* **Scheduled** — incident declared for a future date; auto-transitions to In Progress at `start_at`
* **In Progress** — ongoing incident
* **Resolved** — incident closed

= Auto-transition =

When an incident's `start_at` datetime is reached, its status automatically changes from Scheduled to In Progress.
The check runs:
1. On every visit to the `/incidents/` frontend page
2. Every hour via WP-Cron (`nim_cron_transition`)

= REST API =

* `GET        /wp-json/network-incidents/v1/list` — public; supports `?status=`, `?severity=`, `?app_id=`, `?per_page=`, `?page=`, `?orderby=`, `?order=`; returns `X-WP-Total` / `X-WP-TotalPages` headers
* `POST       /wp-json/network-incidents/v1/incidents` — create (requires `edit_posts`)
* `PUT|PATCH  /wp-json/network-incidents/v1/incidents/{id}` — partial or full update (requires `edit_posts`); returns 404 if the incident does not exist
* `DELETE     /wp-json/network-incidents/v1/incidents/{id}` — delete (requires `delete_posts`); returns 204 on success

= Templates =

Default templates live in `templates/`. To override in your theme, create the file at the matching path:

* `page-incidents.php` → `{theme}/page-incidents.php`
* `templates/parts/incident-active.php` → `{theme}/nim/incident-active.php`
* `templates/parts/incident-scheduled.php` → `{theme}/nim/incident-scheduled.php`
* `templates/parts/incident-resolved.php` → `{theme}/nim/incident-resolved.php`

Default CSS (`assets/css/frontend.css`) is only loaded when no theme template override is found.

= Shortcode =

Use `[nim_incidents]` in any post or page to embed the full status view inline. Optional attribute: `resolved_limit` (default 5).

== Installation ==

1. Upload the `network-incident-manager` folder to `wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Go to **Incidents → Applications** to create your application list
4. Go to **Incidents → Declare an Incident** to log your first incident
5. Visit `yoursite.com/incidents/` to see the public status page

If the `/incidents/` URL returns a 404, go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

== Frequently Asked Questions ==

= The /incidents/ page returns 404 =
Go to Settings → Permalinks and click Save Changes to regenerate the rewrite rules.

= How do I style the incidents page? =
Create `page-incidents.php` in your active theme root. The plugin's default CSS will not be loaded and your theme controls the layout entirely.

= Can I override just one template part? =
Yes. Create `nim/incident-active.php` (or `incident-scheduled.php`, `incident-resolved.php`) in your theme. Parts not overridden fall back to the plugin defaults.

= Is it compatible with Multisite? =
Yes. The REST API endpoint `GET /network-incidents/v1/list` can be queried from any subsite or external application.

= Does it work with SQLite (WordPress Studio)? =
Yes. `ON UPDATE CURRENT_TIMESTAMP` is avoided; timestamps are managed manually. `FULLTEXT` indexes are not used.

== Screenshots ==

1. Incident list in admin with inline status dropdown
2. Add / Edit incident form with start date picker
3. Applications management page
4. Public /incidents/ page — active, scheduled, and resolved sections

== Changelog ==

See changelog.txt for the full version history.

== Upgrade Notice ==

= 2.5.3 =
Adds a native Gutenberg block `nim/incidents` with live server-side preview, section selector, and item limit control. The block stylesheet is shared with the frontend so the editor preview matches the public render exactly. Also extends the `[nim_incidents]` shortcode with `section=` and `limit=` attributes, and fixes the shortcode breaking the host page design when `page-incidents.php` was included inside `ob_start()`.

= 2.5.2 =
Patch release: fixes Fatal Error on PHP 8.2+ — `DateTime::getLastErrors()` now returns `false` instead of an empty array when there are no errors; `parse_start_at()` now guards against this before calling `array_sum()`. Bumps minimum PHP requirement to 8.2. Also fixes admin notices positioning (`wp-header-end` marker added on all admin pages) and adds `auto_transition()` call on `admin_init` so scheduled incidents transition correctly from the admin.

= 2.5.1 =
Patch release: fixes CSS structure of the scheduled incident template (date was displayed inline after the title), restores missing description display, and removes undefined `$td` variable warnings in template parts.

= 2.5.0 =
New `resolved_at` column added to `wp_incidents` — migration runs automatically on plugin load. Scheduled incidents now render with the correct CSS style. New: REST DELETE route, `[nim_incidents]` shortcode, `?orderby=` / `?order=` params on the list endpoint.

= 2.4.0 =
Major internal refactoring to OOP. No database schema changes — upgrade is safe and automatic. Requires PHP 8.1+. If you have a theme that calls `nim_get_template_part()` directly, the function is still available via a backward-compat shim.

= 2.2.0 =
New `start_at` column added to `wp_incidents`. The upgrade runs automatically on plugin load. "Open" renamed to "Scheduled"; "Maintenance" status removed — update any existing data manually if needed.

= 2.0.0 =
Breaking change: incidents moved from wp_posts to wp_incidents. Existing CPT posts are not migrated automatically.
