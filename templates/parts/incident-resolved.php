<?php
/**
 * Template part — resolved incident row.
 *
 * Available variables:
 *   $incident  stdClass  Row from wp_incidents JOIN wp_incident_apps
 *          ->id, ->reference, ->severity, ->updated_at (UTC), ->app_name
 *
 * Theme override: copy to {theme}/nim/incident-resolved.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$severity_class = 'nim-severity--' . strtolower( esc_attr( $incident->severity ) );
$td             = 'network-incident-manager';
?>
<li class="nim-resolved-item">
    <span class="nim-badge <?php echo esc_attr( $severity_class ); ?>">
        <?php echo esc_html( __( $incident->severity, $td ) ); ?>
    </span>
    <span class="nim-resolved-item__title">
        <?php echo esc_html( $incident->reference ?: __( '(No reference)', $td ) ); ?>
    </span>
    <?php if ( $incident->app_name ) : ?>
    <span class="nim-incident__app"><?php echo esc_html( $incident->app_name ); ?></span>
    <?php endif; ?>
    <span class="nim-resolved-item__date">
        <?php
        printf(
            /* translators: %s: formatted date */
            esc_html__( 'Resolved on %s', $td ),
            esc_html( wp_date(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                strtotime( $incident->updated_at . ' UTC' )
            ) )
        );
        ?>
    </span>
</li>
