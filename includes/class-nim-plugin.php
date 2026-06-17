<?php
/**
 * NIM_Plugin — central orchestrator.
 *
 * Loads all classes, wires hooks, and handles activation/deactivation.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Plugin {

    private static ?self $instance = null;

    /** Singleton — prevents double-initialisation. */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_classes();
        $this->register_hooks();
    }

    // -----------------------------------------------------------------------
    // Class loader
    // -----------------------------------------------------------------------

    private function load_classes(): void {
        $dir = plugin_dir_path( __FILE__ );
        require_once $dir . 'class-nim-helpers.php';
        require_once $dir . 'class-nim-db.php';
        require_once $dir . 'class-nim-cron.php';
        require_once $dir . 'class-nim-frontend.php';
        require_once $dir . 'class-nim-ajax.php';
        require_once $dir . 'class-nim-rest-api.php';
        require_once $dir . 'class-nim-admin.php';
    }

    // -----------------------------------------------------------------------
    // Hook wiring
    // -----------------------------------------------------------------------

    private function register_hooks(): void {
        // Database upgrade check on every page load.
        add_action( 'plugins_loaded', [ 'NIM_DB',       'maybe_upgrade' ] );

        // Text domain.
        add_action( 'init', [ __CLASS__, 'load_textdomain' ] );

        // Feature modules.
        NIM_Cron::register_hooks();
        NIM_Frontend::register_hooks();
        NIM_Ajax::register_hooks();
        NIM_REST_API::register_hooks();

        if ( is_admin() ) {
            NIM_Admin::register_hooks();
        }
    }

    // -----------------------------------------------------------------------
    // Activation / Deactivation
    // -----------------------------------------------------------------------

    public static function activate(): void {
        // Load classes before activation hooks run (no autoloader yet).
        $dir = plugin_dir_path( __FILE__ );
        foreach ( [ 'class-nim-helpers', 'class-nim-db', 'class-nim-cron', 'class-nim-frontend' ] as $f ) {
            require_once $dir . $f . '.php';
        }
        NIM_DB::install();
        NIM_Frontend::register_rewrite_rules();
        flush_rewrite_rules();
        NIM_Cron::schedule();
    }

    public static function deactivate(): void {
        NIM_Cron::unschedule();
        flush_rewrite_rules();
    }

    // -----------------------------------------------------------------------
    // i18n
    // -----------------------------------------------------------------------

    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'network-incident-manager',
            false,
            dirname( plugin_basename( NIM_PLUGIN_FILE ) ) . '/languages'
        );
    }
}
