<?php
/**
 * NIM_DB — database installation and migration.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_DB {

    /**
     * Create or upgrade both plugin tables.
     * Safe to call repeatedly (dbDelta is idempotent).
     */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $table      = $wpdb->prefix . NIM_TABLE;
        $table_apps = $wpdb->prefix . NIM_APPS_TABLE;

        $sql_incidents = "CREATE TABLE $table (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            reference   varchar(255) NOT NULL DEFAULT '',
            description longtext,
            severity    varchar(20)  NOT NULL DEFAULT 'Minor',
            status      varchar(20)  NOT NULL DEFAULT 'Scheduled',
            app_id      bigint(20)   NOT NULL DEFAULT 0,
            author_id   bigint(20)   NOT NULL DEFAULT 0,
            start_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime     NULL,
            created_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY author_id (author_id),
            KEY app_id (app_id),
            KEY start_at (start_at)
        ) $charset;";

        $sql_apps = "CREATE TABLE $table_apps (
            id         bigint(20)   NOT NULL AUTO_INCREMENT,
            name       varchar(255) NOT NULL DEFAULT '',
            parent_id  bigint(20)   NOT NULL DEFAULT 0,
            created_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_incidents );
        dbDelta( $sql_apps );

        update_option( 'nim_db_version', NIM_VERSION );
    }

    /**
     * Run on plugins_loaded: upgrade DB + rewrites when version changes.
     */
    public static function maybe_upgrade() {
        if ( get_option( 'nim_db_version' ) !== NIM_VERSION ) {
            self::install();
            self::drop_legacy_columns();
            // Re-flush rewrites so /incidents/ stays reachable after updates.
            NIM_Frontend::register_rewrite_rules();
            flush_rewrite_rules();
        }
    }

    // -------------------------------------------------------------------------
    // Query helpers — used by templates and other classes
    // -------------------------------------------------------------------------

    /**
     * Return active (In Progress) incidents, ordered by severity then start_at.
     *
     * @return array|object|null
     */
    public static function get_active_incidents() {
        global $wpdb;
        $t  = $wpdb->prefix . NIM_TABLE;
        $ta = $wpdb->prefix . NIM_APPS_TABLE;

        return $wpdb->get_results(
            "SELECT i.id, i.reference, i.description, i.severity, i.status,
                    i.start_at, i.created_at,
                    a.name AS app_name
             FROM $t i
             LEFT JOIN $ta a ON i.app_id = a.id
             WHERE i.status = 'In Progress'
             ORDER BY
                 CASE i.severity WHEN 'Critical' THEN 1 WHEN 'Major' THEN 2 ELSE 3 END,
                 i.start_at ASC"
        );
    }

    /**
     * Return scheduled incidents, ordered by start_at ascending.
     *
     * @return array|object|null
     */
    public static function get_scheduled_incidents() {
        global $wpdb;
        $t  = $wpdb->prefix . NIM_TABLE;
        $ta = $wpdb->prefix . NIM_APPS_TABLE;

        return $wpdb->get_results(
            "SELECT i.id, i.reference, i.description, i.severity, i.status,
                    i.start_at,
                    a.name AS app_name
             FROM $t i
             LEFT JOIN $ta a ON i.app_id = a.id
             WHERE i.status = 'Scheduled'
             ORDER BY i.start_at ASC"
        );
    }

    /**
     * Return the most recently resolved incidents.
     *
     * @param int $limit Maximum number of rows to return (default 5).
     * @return array|object|null
     */
    public static function get_resolved_incidents( $limit = 5 ) {
        global $wpdb;
        $t      = $wpdb->prefix . NIM_TABLE;
        $ta     = $wpdb->prefix . NIM_APPS_TABLE;
        $limit  = absint( $limit );

        return $wpdb->get_results(
            "SELECT i.id, i.reference, i.description, i.severity, i.updated_at,
                    COALESCE(i.resolved_at, i.updated_at) AS resolved_at,
                    a.name AS app_name
             FROM $t i
             LEFT JOIN $ta a ON i.app_id = a.id
             WHERE i.status = 'Resolved'
             ORDER BY COALESCE(i.resolved_at, i.updated_at) DESC
             LIMIT $limit"
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Return all incidents for the admin list, with application name.
     *
     * @return array|object|null
     */
    public static function get_all_incidents_admin() {
        global $wpdb;
        $t  = $wpdb->prefix . NIM_TABLE;
        $ta = $wpdb->prefix . NIM_APPS_TABLE;

        return $wpdb->get_results(
            "SELECT i.*, a.name AS app_name
             FROM $t i
             LEFT JOIN $ta a ON i.app_id = a.id
             ORDER BY i.start_at DESC"
        );
    }

    /**
     * Return all applications for the admin list, with parent name.
     *
     * @return array|object|null
     */
    public static function get_all_apps_admin() {
        global $wpdb;
        $ta = $wpdb->prefix . NIM_APPS_TABLE;

        return $wpdb->get_results(
            "SELECT a.*, p.name AS parent_name
             FROM $ta a
             LEFT JOIN $ta p ON a.parent_id = p.id
             ORDER BY p.name ASC, a.name ASC"
        );
    }

    /**
     * Update a single incident's status, setting resolved_at when transitioning to Resolved.
     *
     * @param int    $id         Incident ID.
     * @param string $new_status New status value.
     * @param string $old_status Previous status value (used to detect Resolved transition).
     * @return int|false Rows affected, or false on DB error.
     */
    public static function update_status( int $id, string $new_status, string $old_status ) {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql', true );

        $fields = [
            'status'     => $new_status,
            'updated_at' => $now,
        ];

        if ( 'Resolved' === $new_status && 'Resolved' !== $old_status ) {
            $fields['resolved_at'] = $now;
        } elseif ( 'Resolved' !== $new_status ) {
            // Re-opening a resolved incident clears the resolved date.
            $fields['resolved_at'] = null;
        }

        return $wpdb->update( $table, $fields, [ 'id' => $id ] );
    }

    /**
     * Convenience: fully-qualified incidents table name.
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . NIM_TABLE;
    }

    /**
     * Convenience: fully-qualified apps table name.
     */
    public static function apps_table(): string {
        global $wpdb;
        return $wpdb->prefix . NIM_APPS_TABLE;
    }

    /**
     * Drop columns removed in past versions that dbDelta never removes.
     * 'application varchar(255)' existed in 2.0.0; replaced by app_id in 2.1.0.
     */
    public static function drop_legacy_columns() {
        global $wpdb;
        $table   = $wpdb->prefix . NIM_TABLE;
        $columns = NIM_Helpers::get_column_names( $table );

        if ( in_array( 'application', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table DROP COLUMN application" );
        }
    }
}
