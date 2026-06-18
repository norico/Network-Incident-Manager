<?php
/**
 * Plugin Name:       Network Incident Manager
 * Description:       Network incident manager with dedicated tables and REST API for multisite.
 * Version:           2.5.3
 * Author:            Norico
 * Text Domain:       network-incident-manager
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      8.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'NIM_VERSION',     '2.5.3' );
define( 'NIM_TABLE',       'incidents' );
define( 'NIM_APPS_TABLE',  'incident_apps' );
define( 'NIM_PLUGIN_FILE', __FILE__ );
define( 'NIM_TD',          'network-incident-manager' );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once plugin_dir_path( __FILE__ ) . 'includes/class-nim-plugin.php';

register_activation_hook( __FILE__,   [ 'NIM_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'NIM_Plugin', 'deactivate' ] );

NIM_Plugin::instance();

// ---------------------------------------------------------------------------
// Backward-compatibility shim
// Keeps the global helper function that templates use to load template parts.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'nim_get_template_part' ) ) {
    function nim_get_template_part( $slug, array $data = [] ) {
        NIM_Frontend::get_template_part( $slug, $data );
    }
}
