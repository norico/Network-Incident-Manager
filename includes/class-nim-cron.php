<?php
/**
 * NIM_Cron — WP-Cron scheduling and auto-transition logic.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Cron {

    /** Cron hook name. */
    const HOOK = 'nim_cron_transition';

    /**
     * Schedule the hourly cron event on plugin activation.
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK );
        }
    }

    /**
     * Clear the cron event on plugin deactivation.
     */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Transition all Scheduled incidents whose start_at has passed to In Progress.
     * Called both by the cron hook and on every /incidents/ page load.
     */
    public static function auto_transition() {
        global $wpdb;
        $table = $wpdb->prefix . NIM_TABLE;
        $now   = current_time( 'mysql', true );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table
                 SET status = 'In Progress', updated_at = %s
                 WHERE status = 'Scheduled' AND start_at <= %s",
                $now,
                $now
            )
        );
    }

    /**
     * Register the cron callback.
     */
    public static function register_hooks() {
        add_action( self::HOOK, [ __CLASS__, 'auto_transition' ] );
    }
}
