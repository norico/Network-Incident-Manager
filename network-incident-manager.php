<?php
/**
 * Plugin Name: Network Incident Manager
 * Description: Network incident manager with dedicated table and REST API for multisite.
 * Version: 2.3.0
 * Author: Votre Nom
 * Text Domain: network-incident-manager
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NIM_VERSION',    '2.3.0' );
define( 'NIM_TABLE',      'incidents' );
define( 'NIM_APPS_TABLE', 'incident_apps' );

// ---------------------------------------------------------------------------
// 0. Text domain
// ---------------------------------------------------------------------------
function nim_load_textdomain() {
    load_plugin_textdomain(
        'network-incident-manager',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
add_action( 'init', 'nim_load_textdomain' );

// ---------------------------------------------------------------------------
// 1. Database
// ---------------------------------------------------------------------------
function nim_install_database() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table      = $wpdb->prefix . NIM_TABLE;
    $table_apps = $wpdb->prefix . NIM_APPS_TABLE;

    // wp_incidents
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

    // wp_incident_apps — hierarchical list of applications.
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
register_activation_hook( __FILE__, 'nim_activate' );
function nim_activate() {
    nim_install_database();
    nim_rewrite_rules();
    flush_rewrite_rules();
    if ( ! wp_next_scheduled( 'nim_cron_transition' ) ) {
        wp_schedule_event( time(), 'hourly', 'nim_cron_transition' );
    }
}

register_deactivation_hook( __FILE__, 'nim_deactivate' );
function nim_deactivate() {
    wp_clear_scheduled_hook( 'nim_cron_transition' );
    flush_rewrite_rules();
}

function nim_maybe_upgrade() {
    if ( get_option( 'nim_db_version' ) !== NIM_VERSION ) {
        nim_install_database();
        nim_drop_legacy_columns();
    }
}

/**
 * Drop columns that existed in older versions but are no longer used.
 * dbDelta never removes columns — this function handles that explicitly.
 */
function nim_drop_legacy_columns() {
    global $wpdb;
    $table = $wpdb->prefix . NIM_TABLE;

    // 'application varchar(255)' was present in 2.0.0; replaced by app_id + wp_incident_apps in 2.1.0.
    $columns = $wpdb->get_col( "PRAGMA table_info($table)", 1 );
    if ( in_array( 'application', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE $table DROP COLUMN application" );
    }
}
add_action( 'plugins_loaded', 'nim_maybe_upgrade' );

// ---------------------------------------------------------------------------
// 2. Admin menu
// ---------------------------------------------------------------------------
function nim_admin_menu() {
    $td = 'network-incident-manager';

    add_menu_page(
        __( 'Incidents', $td ),
        __( 'Incidents', $td ),
        'edit_posts',
        'nim-incidents',
        'nim_page_list',
        'dashicons-warning',
        25
    );
    add_submenu_page(
        'nim-incidents',
        __( 'All Incidents', $td ),
        __( 'All Incidents', $td ),
        'edit_posts',
        'nim-incidents',
        'nim_page_list'
    );
    add_submenu_page(
        'nim-incidents',
        __( 'Declare an Incident', $td ),
        __( 'Declare an Incident', $td ),
        'edit_posts',
        'nim-add-incident',
        'nim_page_edit'
    );
    add_submenu_page(
        'nim-incidents',
        __( 'Applications', $td ),
        __( 'Applications', $td ),
        'edit_posts',
        'nim-apps',
        'nim_page_apps_list'
    );
    // Hidden: edit application (no menu entry)
    add_submenu_page(
        null,
        __( 'Edit Application', $td ),
        '',
        'edit_posts',
        'nim-edit-app',
        'nim_page_app_edit'
    );
}
add_action( 'admin_menu', 'nim_admin_menu' );

// ---------------------------------------------------------------------------
// 3. Helpers — applications dropdown (recursive, indented)
// ---------------------------------------------------------------------------
function nim_apps_options_html( $selected_id = 0, $parent_id = 0, $depth = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . NIM_APPS_TABLE;
    $apps  = $wpdb->get_results(
        $wpdb->prepare( "SELECT id, name FROM $table WHERE parent_id = %d ORDER BY name ASC", $parent_id )
    );
    $html = '';
    foreach ( $apps as $app ) {
        $prefix  = str_repeat( "\u{00A0}\u{00A0}\u{00A0}", $depth ) . ( $depth > 0 ? '— ' : '' );
        $html   .= sprintf(
            '<option value="%d"%s>%s%s</option>',
            $app->id,
            selected( $selected_id, $app->id, false ),
            $prefix,
            esc_html( $app->name )
        );
        $html .= nim_apps_options_html( $selected_id, $app->id, $depth + 1 );
    }
    return $html;
}

// ---------------------------------------------------------------------------
// 4. Handle incident save
// ---------------------------------------------------------------------------
function nim_handle_save() {
    if ( ! isset( $_POST['nim_save_incident'] ) ) return;

    check_admin_referer( 'nim_save_incident' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'network-incident-manager' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . NIM_TABLE;

    $allowed_severity = [ 'Minor', 'Major', 'Critical' ];
    $allowed_status   = [ 'Scheduled', 'In Progress', 'Resolved' ];

    // Convert datetime-local (local time) to UTC for storage.
    $start_at_input = sanitize_text_field( wp_unslash( $_POST['nim_start_at'] ?? '' ) );
    try {
        $dt = new DateTime( $start_at_input, wp_timezone() );
        $dt->setTimezone( new DateTimeZone( 'UTC' ) );
        $start_at_utc = $dt->format( 'Y-m-d H:i:s' );
    } catch ( Exception $e ) {
        $start_at_utc = current_time( 'mysql', true );
    }

    $data = [
        'reference'   => sanitize_text_field( wp_unslash( $_POST['nim_reference']   ?? '' ) ),
        'description' => wp_kses_post( wp_unslash( $_POST['nim_description'] ?? '' ) ),
        'severity'    => in_array( $_POST['nim_severity'] ?? '', $allowed_severity, true )
                            ? sanitize_text_field( $_POST['nim_severity'] ) : 'Minor',
        'status'      => in_array( $_POST['nim_status'] ?? '', $allowed_status, true )
                            ? sanitize_text_field( $_POST['nim_status'] ) : 'Scheduled',
        'app_id'      => absint( $_POST['nim_app_id'] ?? 0 ),
        'author_id'   => get_current_user_id(),
        'start_at'    => $start_at_utc,
        'updated_at'  => current_time( 'mysql', true ),
    ];

    $id = absint( $_POST['nim_incident_id'] ?? 0 );

    if ( $id > 0 ) {
        $wpdb->update( $table, $data, [ 'id' => $id ] );
        $saved_id = $id;
    } else {
        $data['created_at'] = current_time( 'mysql', true );
        $wpdb->insert( $table, $data );
        $saved_id = $wpdb->insert_id;
    }

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'nim-add-incident', 'id' => $saved_id, 'updated' => '1' ],
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_init', 'nim_handle_save' );

// ---------------------------------------------------------------------------
// 5. Handle incident delete
// ---------------------------------------------------------------------------
function nim_handle_delete() {
    if ( ( $_GET['action'] ?? '' ) !== 'nim_delete' ) return;
    if ( empty( $_GET['id'] ) || empty( $_GET['_wpnonce'] ) ) return;

    $id = absint( $_GET['id'] );

    if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'nim_delete_' . $id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'network-incident-manager' ) );
    }
    if ( ! current_user_can( 'delete_posts' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'network-incident-manager' ) );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . NIM_TABLE, [ 'id' => $id ] );

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'nim-incidents', 'deleted' => '1' ],
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_init', 'nim_handle_delete' );

// ---------------------------------------------------------------------------
// 6. Handle application save
// ---------------------------------------------------------------------------
function nim_handle_app_save() {
    if ( ! isset( $_POST['nim_save_app'] ) ) return;

    check_admin_referer( 'nim_save_app' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'network-incident-manager' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . NIM_APPS_TABLE;

    $data = [
        'name'      => sanitize_text_field( wp_unslash( $_POST['nim_app_name'] ?? '' ) ),
        'parent_id' => absint( $_POST['nim_app_parent_id'] ?? 0 ),
    ];

    $id = absint( $_POST['nim_app_id'] ?? 0 );

    if ( $id > 0 ) {
        $wpdb->update( $table, $data, [ 'id' => $id ] );
        $saved_id = $id;
    } else {
        $data['created_at'] = current_time( 'mysql', true );
        $wpdb->insert( $table, $data );
        $saved_id = $wpdb->insert_id;
    }

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'nim-apps', 'updated' => '1' ],
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_init', 'nim_handle_app_save' );

// ---------------------------------------------------------------------------
// 7. Handle application delete
// ---------------------------------------------------------------------------
function nim_handle_app_delete() {
    if ( ( $_GET['action'] ?? '' ) !== 'nim_delete_app' ) return;
    if ( empty( $_GET['id'] ) || empty( $_GET['_wpnonce'] ) ) return;

    $id = absint( $_GET['id'] );

    if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'nim_delete_app_' . $id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'network-incident-manager' ) );
    }
    if ( ! current_user_can( 'delete_posts' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'network-incident-manager' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . NIM_APPS_TABLE;

    // Re-parent children to the deleted app's parent.
    $app = $wpdb->get_row( $wpdb->prepare( "SELECT parent_id FROM $table WHERE id = %d", $id ) );
    if ( $app ) {
        $wpdb->update( $table, [ 'parent_id' => $app->parent_id ], [ 'parent_id' => $id ] );
    }
    $wpdb->delete( $table, [ 'id' => $id ] );

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'nim-apps', 'deleted' => '1' ],
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_init', 'nim_handle_app_delete' );

// ---------------------------------------------------------------------------
// 8. Incident list page
// ---------------------------------------------------------------------------
function nim_page_list() {
    global $wpdb;
    $td         = 'network-incident-manager';
    $table      = $wpdb->prefix . NIM_TABLE;
    $table_apps = $wpdb->prefix . NIM_APPS_TABLE;

    $status_opts = [
        'Scheduled'   => __( 'Scheduled',   $td ),
        'In Progress' => __( 'In Progress', $td ),
        'Resolved'    => __( 'Resolved',    $td ),
    ];

    $incidents = $wpdb->get_results(
        "SELECT i.*, a.name AS app_name
         FROM $table i
         LEFT JOIN $table_apps a ON i.app_id = a.id
         ORDER BY i.created_at DESC"
    );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'Incidents', $td ); ?></h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=nim-add-incident' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Declare an Incident', $td ); ?>
        </a>

        <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Incident deleted.', $td ); ?></p>
        </div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Reference', $td ); ?></th>
                    <th><?php esc_html_e( 'Application', $td ); ?></th>
                    <th><?php esc_html_e( 'Severity', $td ); ?></th>
                    <th><?php esc_html_e( 'Status', $td ); ?></th>
                    <th><?php esc_html_e( 'Date', $td ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $incidents ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No incidents found.', $td ); ?></td></tr>
                <?php else : ?>
                <?php foreach ( $incidents as $incident ) :
                    $edit_url   = add_query_arg(
                        [ 'page' => 'nim-add-incident', 'id' => $incident->id ],
                        admin_url( 'admin.php' )
                    );
                    $delete_url = wp_nonce_url(
                        add_query_arg(
                            [ 'page' => 'nim-incidents', 'action' => 'nim_delete', 'id' => $incident->id ],
                            admin_url( 'admin.php' )
                        ),
                        'nim_delete_' . $incident->id
                    );
                ?>
                <tr>
                    <td>
                        <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $incident->reference ?: '—' ); ?></a></strong>
                        <div class="row-actions">
                            <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', $td ); ?></a> | </span>
                            <span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this incident?', $td ); ?>')"><?php esc_html_e( 'Delete', $td ); ?></a></span>
                        </div>
                    </td>
                    <td><?php echo esc_html( $incident->app_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( __( $incident->severity, $td ) ); ?></td>
                    <td>
                        <select class="nim-status-select" data-id="<?php echo esc_attr( $incident->id ); ?>">
                            <?php foreach ( $status_opts as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $incident->status, $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="nim-status-feedback" style="display:none;margin-left:4px;"></span>
                    </td>
                    <td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $incident->created_at . ' UTC' ) ) ); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 9. Incident add / edit page
// ---------------------------------------------------------------------------
function nim_page_edit() {
    global $wpdb;
    $td       = 'network-incident-manager';
    $table    = $wpdb->prefix . NIM_TABLE;
    $id       = absint( $_GET['id'] ?? 0 );
    $incident = $id
        ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) )
        : null;

    $severity_opts = [
        'Minor'    => __( 'Minor',    $td ),
        'Major'    => __( 'Major',    $td ),
        'Critical' => __( 'Critical', $td ),
    ];
    $status_opts = [
        'Scheduled'   => __( 'Scheduled',   $td ),
        'In Progress' => __( 'In Progress', $td ),
        'Resolved'    => __( 'Resolved',    $td ),
    ];
    ?>
    <div class="wrap">
        <h1><?php echo $incident ? esc_html__( 'Edit Incident', $td ) : esc_html__( 'Declare an Incident', $td ); ?></h1>

        <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Incident saved.', $td ); ?></p>
        </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'nim_save_incident' ); ?>
            <input type="hidden" name="nim_save_incident" value="1">
            <input type="hidden" name="nim_incident_id"   value="<?php echo esc_attr( $id ); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="nim_reference"><?php esc_html_e( 'Incident reference', $td ); ?></label></th>
                    <td><input type="text" id="nim_reference" name="nim_reference" class="regular-text"
                               value="<?php echo esc_attr( $incident->reference ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nim_app_id"><?php esc_html_e( 'Application', $td ); ?></label></th>
                    <td>
                        <select id="nim_app_id" name="nim_app_id">
                            <option value="0"><?php esc_html_e( '— None —', $td ); ?></option>
                            <?php echo nim_apps_options_html( absint( $incident->app_id ?? 0 ) ); // phpcs:ignore ?>
                        </select>
                        <p class="description">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=nim-apps' ) ); ?>">
                                <?php esc_html_e( 'Manage applications', $td ); ?>
                            </a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nim_severity"><?php esc_html_e( 'Severity', $td ); ?></label></th>
                    <td>
                        <select id="nim_severity" name="nim_severity">
                            <?php foreach ( $severity_opts as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $incident->severity ?? 'Minor', $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nim_status"><?php esc_html_e( 'Status', $td ); ?></label></th>
                    <td>
                        <select id="nim_status" name="nim_status">
                            <?php foreach ( $status_opts as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $incident->status ?? 'Scheduled', $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nim_start_at"><?php esc_html_e( 'Start Date', $td ); ?></label></th>
                    <td>
                        <input type="datetime-local" id="nim_start_at" name="nim_start_at"
                               value="<?php echo esc_attr(
                                   $incident && $incident->start_at
                                       ? wp_date( 'Y-m-d\TH:i', strtotime( $incident->start_at . ' UTC' ) )
                                       : wp_date( 'Y-m-d\TH:i' )
                               ); ?>">
                        <p class="description"><?php esc_html_e( 'Incident becomes "In Progress" automatically when this date is reached.', $td ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nim_description"><?php esc_html_e( 'Description', $td ); ?></label></th>
                    <td><textarea id="nim_description" name="nim_description" rows="8" class="large-text"><?php echo esc_textarea( $incident->description ?? '' ); ?></textarea></td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Incident', $td ) ); ?>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 10. Applications list page
// ---------------------------------------------------------------------------
function nim_page_apps_list() {
    global $wpdb;
    $td    = 'network-incident-manager';
    $table = $wpdb->prefix . NIM_APPS_TABLE;

    // Build flat list with parent names for display.
    $apps = $wpdb->get_results(
        "SELECT a.*, p.name AS parent_name
         FROM $table a
         LEFT JOIN $table p ON a.parent_id = p.id
         ORDER BY p.name ASC, a.name ASC"
    );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'Applications', $td ); ?></h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=nim-edit-app' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Add New Application', $td ); ?>
        </a>

        <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Application saved.', $td ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Application deleted.', $td ); ?></p></div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', $td ); ?></th>
                    <th><?php esc_html_e( 'Parent Application', $td ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $apps ) ) : ?>
                <tr><td colspan="2"><?php esc_html_e( 'No applications found.', $td ); ?></td></tr>
                <?php else : ?>
                <?php foreach ( $apps as $app ) :
                    $edit_url   = add_query_arg(
                        [ 'page' => 'nim-edit-app', 'id' => $app->id ],
                        admin_url( 'admin.php' )
                    );
                    $delete_url = wp_nonce_url(
                        add_query_arg(
                            [ 'page' => 'nim-apps', 'action' => 'nim_delete_app', 'id' => $app->id ],
                            admin_url( 'admin.php' )
                        ),
                        'nim_delete_app_' . $app->id
                    );
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
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 11. Application add / edit page
// ---------------------------------------------------------------------------
function nim_page_app_edit() {
    global $wpdb;
    $td    = 'network-incident-manager';
    $table = $wpdb->prefix . NIM_APPS_TABLE;
    $id    = absint( $_GET['id'] ?? 0 );
    $app   = $id
        ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) )
        : null;
    ?>
    <div class="wrap">
        <h1><?php echo $app ? esc_html__( 'Edit Application', $td ) : esc_html__( 'Add New Application', $td ); ?></h1>

        <form method="post">
            <?php wp_nonce_field( 'nim_save_app' ); ?>
            <input type="hidden" name="nim_save_app" value="1">
            <input type="hidden" name="nim_app_id"   value="<?php echo esc_attr( $id ); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="nim_app_name"><?php esc_html_e( 'New Application Name', $td ); ?></label></th>
                    <td><input type="text" id="nim_app_name" name="nim_app_name" class="regular-text"
                               value="<?php echo esc_attr( $app->name ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nim_app_parent_id"><?php esc_html_e( 'Parent Application', $td ); ?></label></th>
                    <td>
                        <select id="nim_app_parent_id" name="nim_app_parent_id">
                            <option value="0"><?php esc_html_e( '— None (top level) —', $td ); ?></option>
                            <?php
                            // Exclude the current app and its children from the parent dropdown.
                            echo nim_apps_options_html( absint( $app->parent_id ?? 0 ), 0, 0 ); // phpcs:ignore
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

// ---------------------------------------------------------------------------
// 12. REST API
// ---------------------------------------------------------------------------
function nim_register_rest_routes() {
    register_rest_route( 'network-incidents/v1', '/list', [
        'methods'             => 'GET',
        'callback'            => 'nim_api_list',
        'permission_callback' => '__return_true', // /!\ À sécuriser en production
    ]);
    register_rest_route( 'network-incidents/v1', '/incidents', [
        'methods'             => 'POST',
        'callback'            => 'nim_api_create',
        'permission_callback' => fn() => current_user_can( 'edit_posts' ),
    ]);
    register_rest_route( 'network-incidents/v1', '/incidents/(?P<id>\d+)', [
        'methods'             => 'PUT',
        'callback'            => 'nim_api_update',
        'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
    ]);
}
add_action( 'rest_api_init', 'nim_register_rest_routes' );

function nim_api_list() {
    global $wpdb;
    $table      = $wpdb->prefix . NIM_TABLE;
    $table_apps = $wpdb->prefix . NIM_APPS_TABLE;

    $results = $wpdb->get_results(
        "SELECT i.*, a.name AS application
         FROM $table i
         LEFT JOIN $table_apps a ON i.app_id = a.id
         ORDER BY i.created_at DESC"
    );

    if ( empty( $results ) ) {
        return new WP_REST_Response(
            [ 'message' => __( 'No incidents found.', 'network-incident-manager' ) ],
            404
        );
    }
    return new WP_REST_Response( $results, 200 );
}

function nim_api_create( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . NIM_TABLE;

    $allowed_severity = [ 'Minor', 'Major', 'Critical' ];
    $allowed_status   = [ 'Scheduled', 'In Progress', 'Resolved' ];

    $data = [
        'reference'   => sanitize_text_field( $request->get_param( 'reference' )   ?? '' ),
        'description' => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
        'severity'    => in_array( $request->get_param( 'severity' ), $allowed_severity, true )
                            ? $request->get_param( 'severity' ) : 'Minor',
        'status'      => in_array( $request->get_param( 'status' ), $allowed_status, true )
                            ? $request->get_param( 'status' ) : 'Scheduled',
        'app_id'      => absint( $request->get_param( 'app_id' ) ?? 0 ),
        'start_at'    => $request->get_param( 'start_at' ) ? sanitize_text_field( $request->get_param( 'start_at' ) ) : current_time( 'mysql', true ),
        'author_id'   => get_current_user_id(),
        'created_at'  => current_time( 'mysql', true ),
        'updated_at'  => current_time( 'mysql', true ),
    ];

    $wpdb->insert( $table, $data );
    $incident = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ) );
    return new WP_REST_Response( $incident, 201 );
}

function nim_api_update( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . NIM_TABLE;
    $id    = absint( $request['id'] );

    $allowed_severity = [ 'Minor', 'Major', 'Critical' ];
    $allowed_status   = [ 'Scheduled', 'In Progress', 'Resolved' ];

    $fields = [ 'updated_at' => current_time( 'mysql', true ) ];

    if ( null !== $request->get_param( 'reference' ) )
        $fields['reference']   = sanitize_text_field( $request->get_param( 'reference' ) );
    if ( null !== $request->get_param( 'description' ) )
        $fields['description'] = wp_kses_post( $request->get_param( 'description' ) );
    if ( in_array( $request->get_param( 'severity' ), $allowed_severity, true ) )
        $fields['severity']    = $request->get_param( 'severity' );
    if ( in_array( $request->get_param( 'status' ), $allowed_status, true ) )
        $fields['status']      = $request->get_param( 'status' );
    if ( null !== $request->get_param( 'app_id' ) )
        $fields['app_id']      = absint( $request->get_param( 'app_id' ) );
    if ( null !== $request->get_param( 'start_at' ) )
        $fields['start_at']    = sanitize_text_field( $request->get_param( 'start_at' ) );

    $wpdb->update( $table, $fields, [ 'id' => $id ] );

    $incident = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    if ( ! $incident ) {
        return new WP_REST_Response( [ 'message' => 'Incident not found.' ], 404 );
    }
    return new WP_REST_Response( $incident, 200 );
}

// ---------------------------------------------------------------------------
// 13. Frontend — rewrite rule + query var
// ---------------------------------------------------------------------------
function nim_rewrite_rules() {
    add_rewrite_rule( '^incidents/?$', 'index.php?nim_incidents=1', 'top' );
}
add_action( 'init', 'nim_rewrite_rules' );

function nim_query_vars( $vars ) {
    $vars[] = 'nim_incidents';
    return $vars;
}
add_filter( 'query_vars', 'nim_query_vars' );

// ---------------------------------------------------------------------------
// 14. Frontend — template override (theme > plugin)
// ---------------------------------------------------------------------------

/**
 * Locate the incidents template:
 * 1. {active-theme}/page-incidents.php
 * 2. {parent-theme}/page-incidents.php  (handled by locate_template)
 * 3. {plugin}/templates/page-incidents.php  (fallback)
 */
function nim_locate_template() {
    $theme_tpl = locate_template( 'page-incidents.php' );
    if ( $theme_tpl ) {
        return $theme_tpl;
    }
    return plugin_dir_path( __FILE__ ) . 'templates/page-incidents.php';
}

function nim_template_include( $template ) {
    if ( get_query_var( 'nim_incidents' ) ) {
        nim_auto_transition_statuses(); // ensure transitions are current before render
        return nim_locate_template();
    }
    return $template;
}
add_filter( 'template_include', 'nim_template_include' );

// ---------------------------------------------------------------------------
// 15. Frontend — enqueue stylesheet (only on the incidents page)
// ---------------------------------------------------------------------------
function nim_enqueue_frontend_assets() {
    if ( ! get_query_var( 'nim_incidents' ) ) return;

    // Only enqueue default styles if the theme doesn't provide its own template.
    if ( ! locate_template( 'page-incidents.php' ) ) {
        wp_enqueue_style(
            'nim-frontend',
            plugin_dir_url( __FILE__ ) . 'assets/css/frontend.css',
            [],
            NIM_VERSION
        );
    }
}
add_action( 'wp_enqueue_scripts', 'nim_enqueue_frontend_assets' );

// ---------------------------------------------------------------------------
// 16. Frontend — template part loader (theme override support)
// ---------------------------------------------------------------------------

/**
 * Load a plugin template part, with theme override support.
 *
 * Lookup order:
 *   1. {theme}/nim/{slug}.php
 *   2. {theme}/nim-{slug}.php
 *   3. {plugin}/templates/parts/{slug}.php  (fallback)
 *
 * @param string $slug  Part slug, e.g. 'incident-active'
 * @param array  $data  Variables to extract into the template scope
 */
function nim_get_template_part( $slug, array $data = [] ) {
    $theme_file = locate_template( [ 'nim/' . $slug . '.php', 'nim-' . $slug . '.php' ] );
    $file       = $theme_file ?: plugin_dir_path( __FILE__ ) . 'templates/parts/' . $slug . '.php';

    if ( ! file_exists( $file ) ) return;

    // phpcs:ignore WordPress.PHP.DontExtract
    extract( $data, EXTR_SKIP );
    include $file;
}

// ---------------------------------------------------------------------------
// 17. Admin — enqueue JS for the list page
// ---------------------------------------------------------------------------
function nim_enqueue_admin_assets( $hook ) {
    if ( 'toplevel_page_nim-incidents' !== $hook ) return;

    wp_enqueue_script(
        'nim-admin',
        plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
        [],
        NIM_VERSION,
        true
    );
    wp_localize_script( 'nim-admin', 'nimAdmin', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'nim_update_status' ),
        'saved'   => __( 'Saved', 'network-incident-manager' ),
        'error'   => __( 'Error', 'network-incident-manager' ),
    ]);
}
add_action( 'admin_enqueue_scripts', 'nim_enqueue_admin_assets' );

// ---------------------------------------------------------------------------
// 17. AJAX — inline status update
// ---------------------------------------------------------------------------
function nim_ajax_update_status() {
    check_ajax_referer( 'nim_update_status' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'network-incident-manager' ) ] );
    }

    $id     = absint( $_POST['id'] ?? 0 );
    $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

    $allowed = [ 'Scheduled', 'In Progress', 'Resolved' ];
    if ( ! $id || ! in_array( $status, $allowed, true ) ) {
        wp_send_json_error( [ 'message' => 'Invalid data.' ] );
    }

    global $wpdb;
    $result = $wpdb->update(
        $wpdb->prefix . NIM_TABLE,
        [ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
        [ 'id' => $id ]
    );

    if ( false === $result ) {
        wp_send_json_error( [ 'message' => 'Update failed.' ] );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_nim_update_status', 'nim_ajax_update_status' );

// ---------------------------------------------------------------------------
// 18. Auto-transition: Scheduled → In Progress when start_at is reached
// ---------------------------------------------------------------------------
function nim_auto_transition_statuses() {
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
add_action( 'nim_cron_transition', 'nim_auto_transition_statuses' );
