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
