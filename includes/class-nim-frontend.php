<?php
/**
 * NIM_Frontend — rewrite rules, template loader, asset enqueue, template-part helper.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Frontend {

    public static function register_hooks() {
        add_action( 'init',              [ __CLASS__, 'register_rewrite_rules' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'add_query_vars' ] );
        add_filter( 'template_include',  [ __CLASS__, 'template_include' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /**
     * Register the /incidents/ virtual URL.
     */
    public static function register_rewrite_rules() {
        add_rewrite_rule( '^incidents/?$', 'index.php?nim_incidents=1', 'top' );
    }

    /**
     * Expose the nim_incidents query var to WP.
     *
     * @param string[] $vars
     * @return string[]
     */
    public static function add_query_vars( $vars ) {
        $vars[] = 'nim_incidents';
        return $vars;
    }

    /**
     * Swap in the plugin (or theme-override) template for /incidents/.
     *
     * @param string $template Current template path.
     * @return string
     */
    public static function template_include( $template ) {
        if ( ! get_query_var( 'nim_incidents' ) ) {
            return $template;
        }
        NIM_Cron::auto_transition(); // ensure statuses are current before render
        return self::locate_template();
    }

    /**
     * Locate the main incidents template:
     *   1. {theme}/page-incidents.php
     *   2. {plugin}/templates/page-incidents.php  (fallback)
     *
     * @return string Absolute file path.
     */
    public static function locate_template() {
        $theme_tpl = locate_template( 'page-incidents.php' );
        return $theme_tpl ?: plugin_dir_path( dirname( __FILE__ ) ) . 'templates/page-incidents.php';
    }

    /**
     * Enqueue the default frontend stylesheet.
     * Skipped when the theme provides its own page-incidents.php.
     */
    public static function enqueue_assets() {
        if ( ! get_query_var( 'nim_incidents' ) ) {
            return;
        }
        if ( ! locate_template( 'page-incidents.php' ) ) {
            wp_enqueue_style(
                'nim-frontend',
                plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/frontend.css',
                [],
                NIM_VERSION
            );
        }
    }

    /**
     * Load a plugin template part, with theme override support.
     *
     * Lookup order:
     *   1. {theme}/nim/{slug}.php
     *   2. {theme}/nim-{slug}.php
     *   3. {plugin}/templates/parts/{slug}.php  (fallback)
     *
     * @param string $slug Template part slug, e.g. 'incident-active'.
     * @param array  $data Variables passed into the template scope.
     */
    public static function get_template_part( $slug, array $data = [] ) {
        $theme_file = locate_template( [ 'nim/' . $slug . '.php', 'nim-' . $slug . '.php' ] );
        $file       = $theme_file ?: plugin_dir_path( dirname( __FILE__ ) ) . 'templates/parts/' . $slug . '.php';

        if ( ! file_exists( $file ) ) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DontExtract
        extract( $data, EXTR_SKIP );
        include $file;
    }
}
