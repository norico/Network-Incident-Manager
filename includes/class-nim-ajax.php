<?php
/**
 * NIM_Ajax — admin-side AJAX handlers.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Ajax {

    public static function register_hooks() {
        add_action( 'wp_ajax_nim_update_status', [ __CLASS__, 'update_status' ] );
    }

    /**
     * Handle inline status update from the admin incidents list.
     */
    public static function update_status() {
        check_ajax_referer( 'nim_update_status' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [
                'message' => __( 'Insufficient permissions.', NIM_TD ),
            ] );
        }

        $id     = absint( $_POST['id']     ?? 0 );
        $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

        if ( ! $id || ! in_array( $status, NIM_Helpers::STATUSES, true ) ) {
            wp_send_json_error( [
                'message' => __( 'Invalid data.', NIM_TD ),
            ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . NIM_TABLE;

        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $id ) ) ) {
            wp_send_json_error( [
                'message' => __( 'Incident not found.', NIM_TD ),
            ] );
        }

        $old_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", $id ) );

        $result = NIM_DB::update_status( $id, $status, $old_status );

        if ( false === $result ) {
            wp_send_json_error( [
                'message' => __( 'Update failed.', NIM_TD ),
            ] );
        }

        if ( $old_status !== $status ) {
            /** @see NIM_Cron::auto_transition() for hook documentation. */
            do_action( 'nim_status_changed', $id, $old_status, $status );
        }

        wp_send_json_success();
    }
}
