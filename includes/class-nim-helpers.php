<?php
/**
 * NIM_Helpers — shared constants and utility methods.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Helpers {

    /** Valid severity values (single source of truth). */
    const SEVERITIES = [ 'Minor', 'Major', 'Critical' ];

    /** Valid status values (single source of truth). */
    const STATUSES = [ 'Scheduled', 'In Progress', 'Resolved' ];

    /**
     * Return the translated label for a severity value.
     * Uses literal strings so wp i18n make-pot can extract them.
     *
     * @param string $severity Raw DB value.
     * @return string Translated label, or the raw value if unknown.
     */
    public static function severity_label( string $severity ): string {
        $map = [
            'Minor'    => __( 'Minor',    NIM_TD ),
            'Major'    => __( 'Major',    NIM_TD ),
            'Critical' => __( 'Critical', NIM_TD ),
        ];
        return $map[ $severity ] ?? $severity;
    }

    /**
     * Return the translated label for a status value.
     * Uses literal strings so wp i18n make-pot can extract them.
     *
     * @param string $status Raw DB value.
     * @return string Translated label, or the raw value if unknown.
     */
    public static function status_label( string $status ): string {
        $map = [
            'Scheduled'   => __( 'Scheduled',   NIM_TD ),
            'In Progress' => __( 'In Progress',  NIM_TD ),
            'Resolved'    => __( 'Resolved',     NIM_TD ),
        ];
        return $map[ $status ] ?? $status;
    }

    // -----------------------------------------------------------------------
    // Date helpers
    // -----------------------------------------------------------------------

    /**
     * Validate and convert a datetime-local / ISO-8601 string to UTC for storage.
     *
     * Accepted input formats:
     *   'Y-m-d\TH:i:s'  — datetime-local with seconds
     *   'Y-m-d\TH:i'    — datetime-local without seconds (most browsers)
     *   'Y-m-d H:i:s'   — plain MySQL / REST clients
     *   'Y-m-d H:i'     — plain without seconds
     *
     * The input is treated as site local time (wp_timezone()) and converted
     * to UTC. Returns current UTC time on parse failure.
     *
     * @param string $input Raw datetime string from POST or REST.
     * @return string UTC datetime 'Y-m-d H:i:s'.
     */
    public static function parse_start_at( $input ) {
        $input = trim( sanitize_text_field( wp_unslash( (string) $input ) ) );

        $formats = [
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        ];

        $tz = wp_timezone();
        foreach ( $formats as $fmt ) {
            $dt = DateTime::createFromFormat( $fmt, $input, $tz );
            $errors = DateTime::getLastErrors();
            if ( false !== $dt && ! array_sum( $errors ?: [ 'warning_count' => 0, 'error_count' => 0 ] ) ) {
                $dt->setTimezone( new DateTimeZone( 'UTC' ) );
                return $dt->format( 'Y-m-d H:i:s' );
            }
        }

        return current_time( 'mysql', true );
    }

    // -----------------------------------------------------------------------
    // Database helpers
    // -----------------------------------------------------------------------

    /**
     * Return column names for a table, compatible with MySQL and SQLite.
     *
     * @param string $table Fully-qualified table name (with prefix).
     * @return string[]
     */
    public static function get_column_names( $table ) {
        global $wpdb;

        // Detect SQLite: Studio uses the sqlite-database-integration mu-plugin
        // which defines DB_ENGINE = 'sqlite' via db.php. The PDO connection is
        // available on $wpdb->dbh but wrapped — check the class name as fallback.
        $is_sqlite = (
            ( defined( 'DB_ENGINE' ) && 'sqlite' === strtolower( DB_ENGINE ) )
            || ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof PDO
                 && 'sqlite' === $wpdb->dbh->getAttribute( PDO::ATTR_DRIVER_NAME ) )
        );

        if ( $is_sqlite ) {
            // PRAGMA must NOT go through $wpdb on the sqlite-database-integration
            // driver — that layer only parses MySQL syntax. Query PDO directly.
            $pdo = null;
            if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof PDO ) {
                $pdo = $wpdb->dbh;
            } elseif ( isset( $wpdb->dbh->pdo ) && $wpdb->dbh->pdo instanceof PDO ) {
                $pdo = $wpdb->dbh->pdo;
            } elseif ( isset( $wpdb->sqlite ) && $wpdb->sqlite instanceof PDO ) {
                $pdo = $wpdb->sqlite;
            }
            if ( $pdo ) {
                $stmt = $pdo->query( "PRAGMA table_info($table)" );
                return $stmt ? array_column( $stmt->fetchAll( PDO::FETCH_ASSOC ), 'name' ) : [];
            }
            // PDO not accessible — fall through to INFORMATION_SCHEMA (will fail
            // silently on SQLite but drop_legacy_columns becomes a no-op, which is safe).
        }

        // MySQL / MariaDB — INFORMATION_SCHEMA is standard SQL.
        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_NAME   = %s",
                DB_NAME,
                $table
            )
        );
    }

    // -----------------------------------------------------------------------
    // Applications dropdown helpers
    // -----------------------------------------------------------------------

    /**
     * Collect the IDs of an app and all its descendants (to prevent hierarchy cycles).
     *
     * @param int   $id       Root app ID.
     * @param array $all_apps Pre-loaded rows (stdClass id, parent_id). Auto-loaded if empty.
     * @return int[]
     */
    public static function get_descendant_ids( $id, array $all_apps = [] ) {
        if ( empty( $all_apps ) ) {
            global $wpdb;
            $all_apps = $wpdb->get_results(
                "SELECT id, parent_id FROM {$wpdb->prefix}" . NIM_APPS_TABLE
            );
        }

        $ids = [ (int) $id ];
        foreach ( $all_apps as $app ) {
            if ( (int) $app->parent_id === (int) $id ) {
                $ids = array_merge( $ids, self::get_descendant_ids( $app->id, $all_apps ) );
            }
        }
        return $ids;
    }

    /**
     * Build <option> elements for an applications <select>.
     *
     * @param int   $selected_id  Currently selected app ID (0 = none).
     * @param int   $parent_id    Subtree root to render (0 = top level).
     * @param int   $depth        Current indentation depth (internal).
     * @param int[] $exclude_ids  IDs to skip (self + descendants when editing).
     * @param array $all_apps     Pre-loaded rows. Auto-loaded on first call.
     * @return string HTML <option> markup.
     */
    /** Maximum nesting depth for the applications dropdown. */
    const MAX_APP_DEPTH = 10;

    public static function apps_options_html(
        $selected_id = 0,
        $parent_id   = 0,
        $depth       = 0,
        array $exclude_ids = [],
        array $all_apps    = []
    ) {
        if ( $depth > self::MAX_APP_DEPTH ) {
            return '';
        }
        if ( empty( $all_apps ) ) {
            global $wpdb;
            $all_apps = $wpdb->get_results(
                "SELECT id, name, parent_id FROM {$wpdb->prefix}" . NIM_APPS_TABLE . " ORDER BY name ASC"
            );
        }

        $html = '';
        foreach ( $all_apps as $app ) {
            if ( (int) $app->parent_id !== (int) $parent_id ) {
                continue;
            }
            if ( in_array( (int) $app->id, $exclude_ids, true ) ) {
                continue;
            }
            $prefix = str_repeat( "\u{00A0}\u{00A0}\u{00A0}", $depth ) . ( $depth > 0 ? '— ' : '' );
            $html  .= sprintf(
                '<option value="%d"%s>%s%s</option>',
                $app->id,
                selected( $selected_id, $app->id, false ),
                $prefix,
                esc_html( $app->name )
            );
            $html .= self::apps_options_html( $selected_id, $app->id, $depth + 1, $exclude_ids, $all_apps );
        }
        return $html;
    }
}
