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
                'message' => __( 'Insufficient permissions.', 'network-incident-manager' ),
            ] );
        }

        $id     = absint( $_POST['id']     ?? 0 );
        $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

        if ( ! $id || ! in_array( $status, NIM_Helpers::STATUSES, true ) ) {
            wp_send_json_error( [
                'message' => __( 'Invalid data.', 'network-incident-manager' ),
            ] );
        }

        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . NIM_TABLE,
            [ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        );

        if ( false === $result ) {
            wp_send_json_error( [
                'message' => __( 'Update failed.', 'network-incident-manager' ),
            ] );
        }

        wp_send_json_success();
    }
}
