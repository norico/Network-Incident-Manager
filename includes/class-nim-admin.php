<?php
/**
 * NIM_Admin — admin menu, pages, form handlers, asset enqueue.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Admin {

    const TD = 'network-incident-manager';

    public static function register_hooks() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_incident_save' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_incident_delete' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_app_save' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_app_delete' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    // -----------------------------------------------------------------------
    // Menu
    // -----------------------------------------------------------------------

    public static function register_menu() {
        $td = self::TD;
        add_menu_page(
            __( 'Incidents', $td ), __( 'Incidents', $td ),
            'edit_posts', 'nim-incidents', [ __CLASS__, 'page_list' ],
            'dashicons-warning', 25
        );
        add_submenu_page( 'nim-incidents', __( 'All Incidents', $td ), __( 'All Incidents', $td ),
            'edit_posts', 'nim-incidents', [ __CLASS__, 'page_list' ] );
        add_submenu_page( 'nim-incidents', __( 'Declare an Incident', $td ), __( 'Declare an Incident', $td ),
            'edit_posts', 'nim-add-incident', [ __CLASS__, 'page_edit' ] );
        add_submenu_page( 'nim-incidents', __( 'Applications', $td ), __( 'Applications', $td ),
            'edit_posts', 'nim-apps', [ __CLASS__, 'page_apps_list' ] );
        add_submenu_page( null, __( 'Edit Application', $td ), '',
            'edit_posts', 'nim-edit-app', [ __CLASS__, 'page_app_edit' ] );
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    public static function enqueue_assets( $hook ) {
        if ( 'toplevel_page_nim-incidents' !== $hook ) return;
        wp_enqueue_script( 'nim-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/admin.js',
            [], NIM_VERSION, true
        );
        wp_localize_script( 'nim-admin', 'nimAdmin', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'nim_update_status' ),
            'saved'   => __( 'Saved', self::TD ),
            'error'   => __( 'Error', self::TD ),
        ] );
    }

    // -----------------------------------------------------------------------
    // Form handlers — incidents
    // -----------------------------------------------------------------------

    public static function handle_incident_save() {
        if ( ! isset( $_POST['nim_save_incident'] ) ) return;
        check_admin_referer( 'nim_save_incident' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( esc_html__( 'Insufficient permissions.', self::TD ) );

        global $wpdb;
        $table = $wpdb->prefix . NIM_TABLE;
        $data  = [
            'reference'   => sanitize_text_field( wp_unslash( $_POST['nim_reference']   ?? '' ) ),
            'description' => wp_kses_post( wp_unslash( $_POST['nim_description'] ?? '' ) ),
            'severity'    => in_array( $_POST['nim_severity'] ?? '', NIM_Helpers::SEVERITIES, true )
                                ? sanitize_text_field( $_POST['nim_severity'] ) : 'Minor',
            'status'      => in_array( $_POST['nim_status'] ?? '', NIM_Helpers::STATUSES, true )
                                ? sanitize_text_field( $_POST['nim_status'] )   : 'Scheduled',
            'app_id'      => absint( $_POST['nim_app_id']  ?? 0 ),
            'author_id'   => get_current_user_id(),
            'start_at'    => NIM_Helpers::parse_start_at( $_POST['nim_start_at'] ?? '' ),
            'updated_at'  => current_time( 'mysql', true ),
        ];
        $id = absint( $_POST['nim_incident_id'] ?? 0 );
        if ( $id > 0 ) {
            if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $id ) ) ) {
                wp_die( esc_html__( 'Incident not found.', self::TD ), esc_html__( 'Error', self::TD ), [ 'back_link' => true, 'response' => 404 ] );
            }
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            $saved_id = $id;
        } else {
            $data['created_at'] = current_time( 'mysql', true );
            $wpdb->insert( $table, $data );
            $saved_id = $wpdb->insert_id;
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'nim-add-incident', 'id' => $saved_id, 'updated' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_incident_delete() {
        if ( ( $_GET['action'] ?? '' ) !== 'nim_delete' ) return;
        if ( empty( $_GET['id'] ) || empty( $_GET['_wpnonce'] ) ) return;
        $id = absint( $_GET['id'] );
        if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'nim_delete_' . $id ) ) wp_die( esc_html__( 'Security check failed.', self::TD ) );
        if ( ! current_user_can( 'delete_posts' ) ) wp_die( esc_html__( 'Insufficient permissions.', self::TD ) );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . NIM_TABLE, [ 'id' => $id ] );
        wp_safe_redirect( add_query_arg( [ 'page' => 'nim-incidents', 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Form handlers — applications
    // -----------------------------------------------------------------------

    public static function handle_app_save() {
        if ( ! isset( $_POST['nim_save_app'] ) ) return;
        check_admin_referer( 'nim_save_app' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( esc_html__( 'Insufficient permissions.', self::TD ) );
        global $wpdb;
        $table     = $wpdb->prefix . NIM_APPS_TABLE;
        $id        = absint( $_POST['nim_app_id']        ?? 0 );
        $parent_id = absint( $_POST['nim_app_parent_id'] ?? 0 );
        if ( $id > 0 && $parent_id > 0 && in_array( $parent_id, NIM_Helpers::get_descendant_ids( $id ), true ) ) {
            wp_die( esc_html__( 'Invalid parent: an application cannot be its own ancestor.', self::TD ), esc_html__( 'Hierarchy error', self::TD ), [ 'back_link' => true ] );
        }
        $data = [ 'name' => sanitize_text_field( wp_unslash( $_POST['nim_app_name'] ?? '' ) ), 'parent_id' => $parent_id ];
        if ( $id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            $saved_id = $id;
        } else {
            $data['created_at'] = current_time( 'mysql', true );
            $wpdb->insert( $table, $data );
            $saved_id = $wpdb->insert_id;
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'nim-apps', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_app_delete() {
        if ( ( $_GET['action'] ?? '' ) !== 'nim_delete_app' ) return;
        if ( empty( $_GET['id'] ) || empty( $_GET['_wpnonce'] ) ) return;
        $id = absint( $_GET['id'] );
        if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'nim_delete_app_' . $id ) ) wp_die( esc_html__( 'Security check failed.', self::TD ) );
        if ( ! current_user_can( 'delete_posts' ) ) wp_die( esc_html__( 'Insufficient permissions.', self::TD ) );
        global $wpdb;
        $table = $wpdb->prefix . NIM_APPS_TABLE;
        $app   = $wpdb->get_row( $wpdb->prepare( "SELECT parent_id FROM $table WHERE id = %d", $id ) );
        if ( $app ) $wpdb->update( $table, [ 'parent_id' => $app->parent_id ], [ 'parent_id' => $id ] );
        $wpdb->delete( $table, [ 'id' => $id ] );
        wp_safe_redirect( add_query_arg( [ 'page' => 'nim-apps', 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Admin pages
    // -----------------------------------------------------------------------

    public static function page_list() {
        global $wpdb;
        $td   = self::TD;
        $table      = $wpdb->prefix . NIM_TABLE;
        $table_apps = $wpdb->prefix . NIM_APPS_TABLE;
        $status_opts = [ 'Scheduled' => __( 'Scheduled', $td ), 'In Progress' => __( 'In Progress', $td ), 'Resolved' => __( 'Resolved', $td ) ];
        $incidents = $wpdb->get_results( "SELECT i.*, a.name AS app_name FROM $table i LEFT JOIN $table_apps a ON i.app_id = a.id ORDER BY i.start_at DESC" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Incidents', $td ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=nim-add-incident' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Declare an Incident', $td ); ?></a>
            <?php if ( isset( $_GET['deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Incident deleted.', $td ); ?></p></div>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
                <thead><tr>
                    <th><?php esc_html_e( 'Reference',   $td ); ?></th>
                    <th><?php esc_html_e( 'Application', $td ); ?></th>
                    <th><?php esc_html_e( 'Severity',    $td ); ?></th>
                    <th><?php esc_html_e( 'Status',      $td ); ?></th>
                    <th><?php esc_html_e( 'Start Date',  $td ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $incidents ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No incidents found.', $td ); ?></td></tr>
                <?php else : foreach ( $incidents as $inc ) :
                    $edit_url   = add_query_arg( [ 'page' => 'nim-add-incident', 'id' => $inc->id ], admin_url( 'admin.php' ) );
                    $delete_url = wp_nonce_url( add_query_arg( [ 'page' => 'nim-incidents', 'action' => 'nim_delete', 'id' => $inc->id ], admin_url( 'admin.php' ) ), 'nim_delete_' . $inc->id );
                ?>
                <tr>
                    <td>
                        <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $inc->reference ?: '—' ); ?></a></strong>
                        <div class="row-actions">
                            <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', $td ); ?></a> | </span>
                            <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this incident?', $td ); ?>')"><?php esc_html_e( 'Delete', $td ); ?></a></span>
                        </div>
                    </td>
                    <td><?php echo esc_html( $inc->app_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( __( $inc->severity, $td ) ); ?></td>
                    <td>
                        <select class="nim-status-select" data-id="<?php echo esc_attr( $inc->id ); ?>">
                            <?php foreach ( $status_opts as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $inc->status, $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="nim-status-feedback" style="display:none;margin-left:4px;"></span>
                    </td>
                    <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $inc->start_at . ' UTC' ) ) ); ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_edit() {
        global $wpdb;
        $td       = self::TD;
        $table    = $wpdb->prefix . NIM_TABLE;
        $id       = absint( $_GET['id'] ?? 0 );
        $incident = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ) : null;
        $sev_opts = [ 'Minor' => __( 'Minor', $td ), 'Major' => __( 'Major', $td ), 'Critical' => __( 'Critical', $td ) ];
        $sts_opts = [ 'Scheduled' => __( 'Scheduled', $td ), 'In Progress' => __( 'In Progress', $td ), 'Resolved' => __( 'Resolved', $td ) ];
        ?>
        <div class="wrap">
            <h1><?php echo $incident ? esc_html__( 'Edit Incident', $td ) : esc_html__( 'Declare an Incident', $td ); ?></h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Incident saved.', $td ); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field( 'nim_save_incident' ); ?>
                <input type="hidden" name="nim_save_incident" value="1">
                <input type="hidden" name="nim_incident_id" value="<?php echo esc_attr( $id ); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="nim_reference"><?php esc_html_e( 'Reference', $td ); ?></label></th>
                        <td><input type="text" id="nim_reference" name="nim_reference" class="regular-text" value="<?php echo esc_attr( $incident->reference ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="nim_app_id"><?php esc_html_e( 'Application', $td ); ?></label></th>
                        <td>
                            <select id="nim_app_id" name="nim_app_id">
                                <option value="0"><?php esc_html_e( '— None —', $td ); ?></option>
                                <?php echo NIM_Helpers::apps_options_html( absint( $incident->app_id ?? 0 ) ); // phpcs:ignore ?>
                            </select>
                            <p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=nim-apps' ) ); ?>"><?php esc_html_e( 'Manage applications', $td ); ?></a></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="nim_severity"><?php esc_html_e( 'Severity', $td ); ?></label></th>
                        <td><select id="nim_severity" name="nim_severity"><?php foreach ( $sev_opts as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $incident->severity ?? 'Minor', $v ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
                    </tr>
                    <tr>
                        <th><label for="nim_status"><?php esc_html_e( 'Status', $td ); ?></label></th>
                        <td><select id="nim_status" name="nim_status"><?php foreach ( $sts_opts as $v => $l ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $incident->status ?? 'Scheduled', $v ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
                    </tr>
                    <tr>
                        <th><label for="nim_start_at"><?php esc_html_e( 'Start Date', $td ); ?></label></th>
                        <td>
                            <input type="datetime-local" id="nim_start_at" name="nim_start_at"
                                   value="<?php echo esc_attr( $incident && $incident->start_at ? wp_date( 'Y-m-d\TH:i', strtotime( $incident->start_at . ' UTC' ) ) : wp_date( 'Y-m-d\TH:i' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Incident becomes "In Progress" automatically when this date is reached.', $td ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="nim_description"><?php esc_html_e( 'Description', $td ); ?></label></th>
                        <td><textarea id="nim_description" name="nim_description" rows="8" class="large-text"><?php echo esc_textarea( $incident->description ?? '' ); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Incident', $td ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function page_apps_list() {
        global $wpdb;
        $td    = self::TD;
        $table = $wpdb->prefix . NIM_APPS_TABLE;
        $apps  = $wpdb->get_results( "SELECT a.*, p.name AS parent_name FROM $table a LEFT JOIN $table p ON a.parent_id = p.id ORDER BY p.name ASC, a.name ASC" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Applications', $td ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=nim-edit-app' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Application', $td ); ?></a>
            <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Application saved.', $td ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Application deleted.', $td ); ?></p></div><?php endif; ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
                <thead><tr><th><?php esc_html_e( 'Name', $td ); ?></th><th><?php esc_html_e( 'Parent Application', $td ); ?></th></tr></thead>
                <tbody>
                <?php if ( empty( $apps ) ) : ?>
                <tr><td colspan="2"><?php esc_html_e( 'No applications found.', $td ); ?></td></tr>
                <?php else : foreach ( $apps as $app ) :
                    $edit_url   = add_query_arg( [ 'page' => 'nim-edit-app', 'id' => $app->id ], admin_url( 'admin.php' ) );
                    $delete_url = wp_nonce_url( add_query_arg( [ 'page' => 'nim-apps', 'action' => 'nim_delete_app', 'id' => $app->id ], admin_url( 'admin.php' ) ), 'nim_delete_app_' . $app->id );
                ?>
                <tr>
                    <td>
                        <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $app->name ); ?></a></strong>
                        <div class="row-actions">
                            <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', $td ); ?></a> | </span>
                            <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this application?', $td ); ?>')"><?php esc_html_e( 'Delete', $td ); ?></a></span>
                        </div>
                    </td>
                    <td><?php echo esc_html( $app->parent_name ?: '—' ); ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_app_edit() {
        global $wpdb;
        $td    = self::TD;
        $table = $wpdb->prefix . NIM_APPS_TABLE;
        $id    = absint( $_GET['id'] ?? 0 );
        $app   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ) : null;
        ?>
        <div class="wrap">
            <h1><?php echo $app ? esc_html__( 'Edit Application', $td ) : esc_html__( 'Add New Application', $td ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'nim_save_app' ); ?>
                <input type="hidden" name="nim_save_app" value="1">
                <input type="hidden" name="nim_app_id"   value="<?php echo esc_attr( $id ); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="nim_app_name"><?php echo $app ? esc_html__( 'Application Name', $td ) : esc_html__( 'New Application Name', $td ); ?></label></th>
                        <td><input type="text" id="nim_app_name" name="nim_app_name" class="regular-text" value="<?php echo esc_attr( $app->name ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="nim_app_parent_id"><?php esc_html_e( 'Parent Application', $td ); ?></label></th>
                        <td>
                            <select id="nim_app_parent_id" name="nim_app_parent_id">
                                <option value="0"><?php esc_html_e( '— None (top level) —', $td ); ?></option>
                                <?php
                                $exclude = $id > 0 ? NIM_Helpers::get_descendant_ids( $id ) : [];
                                echo NIM_Helpers::apps_options_html( absint( $app->parent_id ?? 0 ), 0, 0, $exclude ); // phpcs:ignore
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Application', $td ) ); ?>
            </form>
        </div>
        <?php
    }
}
