<?php
/**
 * NIM_Block — registers the nim/incidents Gutenberg block.
 *
 * Dynamic block: attributes are passed to NIM_Frontend::shortcode() for rendering.
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_Block {

    public static function register_hooks() {
        add_action( 'init', [ __CLASS__, 'register_block' ] );
    }

    /**
     * Register the block type and its editor assets.
     */
    public static function register_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        // Editor script.
        wp_register_script(
            'nim-block-editor',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/block.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ],
            NIM_VERSION,
            true
        );

        // Frontend stylesheet — also loaded in the editor so SSR preview matches front.
        wp_register_style(
            'nim-frontend',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/frontend.css',
            [],
            NIM_VERSION
        );

        register_block_type( 'nim/incidents', [
            'editor_script'   => 'nim-block-editor',
            'editor_style'    => 'nim-frontend',
            'style'           => 'nim-frontend',
            'render_callback' => [ __CLASS__, 'render' ],
            'attributes'      => [
                'section' => [
                    'type'    => 'string',
                    'default' => '',
                    'enum'    => [ '', 'active', 'scheduled', 'resolved' ],
                ],
                'limit' => [
                    'type'    => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                    'maximum' => 50,
                ],
            ],
        ] );
    }

    /**
     * Server-side render callback.
     * Delegates to the shortcode handler so logic lives in one place.
     *
     * @param array $attributes Block attributes.
     * @return string HTML output.
     */
    public static function render( array $attributes ): string {
        return NIM_Frontend::shortcode( [
            'section'        => $attributes['section'] ?? '',
            'limit'          => $attributes['limit']   ?? 0,
            'resolved_limit' => 5, // BC default; overridden by limit when set.
        ] );
    }
}
