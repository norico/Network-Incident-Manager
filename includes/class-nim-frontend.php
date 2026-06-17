<?php
/**
 * NIM_Frontend — rewrite rules, template loader, asset enqueue, template-part helper.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Frontend {

    public static function register_hooks() {
        add_action( 'init',               [ __CLASS__, 'register_rewrite_rules' ] );
        add_filter( 'query_vars',         [ __CLASS__, 'add_query_vars' ] );
        add_filter( 'template_include',   [ __CLASS__, 'template_include' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_shortcode( 'nim_incidents',   [ __CLASS__, 'shortcode' ] );
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
     * Shortcode [nim_incidents] — embeds the full status page inside any post/page.
     *
     * Usage: [nim_incidents]
     * Optional attrs: resolved_limit (int, default 5)
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function shortcode( $atts ): string {
        $atts = shortcode_atts( [ 'resolved_limit' => 5 ], $atts, 'nim_incidents' );

        NIM_Cron::auto_transition();

        // Enqueue the default stylesheet (skipped when theme overrides page-incidents.php).
        if ( ! locate_template( 'page-incidents.php' ) ) {
            wp_enqueue_style(
                'nim-frontend',
                plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/frontend.css',
                [],
                NIM_VERSION
            );
        }

        $incidents = NIM_DB::get_active_incidents();
        $scheduled = NIM_DB::get_scheduled_incidents();
        $resolved  = NIM_DB::get_resolved_incidents( absint( $atts['resolved_limit'] ) );

        ob_start();
        include plugin_dir_path( dirname( __FILE__ ) ) . 'templates/page-incidents.php';
        // page-incidents.php calls get_header()/get_footer() — we DON'T want that in shortcode context.
        // So we use the individual parts directly instead.
        ob_end_clean();

        // Render inline using the same template parts.
        ob_start();
        ?>
        <div id="nim-incidents-page" class="nim-incidents-page">
            <?php if ( empty( $incidents ) ) : ?>
            <div class="nim-no-incidents">
                <span class="nim-no-incidents-icon" aria-hidden="true">&#10003;</span>
                <p><?php esc_html_e( 'No active incidents at this time.', NIM_TD ); ?></p>
            </div>
            <?php else : ?>
            <ul class="nim-incidents-list">
                <?php foreach ( $incidents as $incident ) :
                    self::get_template_part( 'incident-active', [ 'incident' => $incident ] );
                endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if ( ! empty( $scheduled ) ) : ?>
            <section class="nim-scheduled-section">
                <h2 class="nim-scheduled-title"><?php esc_html_e( 'Scheduled Incidents', NIM_TD ); ?></h2>
                <ul class="nim-scheduled-list">
                    <?php foreach ( $scheduled as $incident ) :
                        self::get_template_part( 'incident-scheduled', [ 'incident' => $incident ] );
                    endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ( ! empty( $resolved ) ) : ?>
            <section class="nim-resolved-section">
                <h2 class="nim-resolved-title"><?php esc_html_e( 'Recently Resolved', NIM_TD ); ?></h2>
                <ul class="nim-resolved-list">
                    <?php foreach ( $resolved as $incident ) :
                        self::get_template_part( 'incident-resolved', [ 'incident' => $incident ] );
                    endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
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
