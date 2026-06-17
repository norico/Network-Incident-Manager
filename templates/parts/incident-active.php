<?php
/**
 * Template part — active incident card.
 *
 * Available variables:
 *   $incident  stdClass  Row from wp_incidents JOIN wp_incident_apps
 *              ->id, ->reference, ->description, ->severity, ->status,
 *              ->created_at (UTC), ->app_name
 *
 * Theme override: copy to {theme}/nim/incident-active.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$severity_class = 'nim-severity--' . strtolower( esc_attr( $incident->severity ) );
$status_class   = 'nim-status--'   . strtolower( str_replace( ' ', '-', esc_attr( $incident->status ) ) );
$td             = 'network-incident-manager';
?>
<li class="nim-incident nim-incident--<?php echo esc_attr( strtolower( $incident->severity ) ); ?>">

    <div class="nim-incident__header">
        <span class="nim-incident__severity nim-badge <?php echo esc_attr( $severity_class ); ?>">
            <?php echo esc_html( __( $incident->severity, $td ) ); ?>
        </span>
        <span class="nim-incident__status nim-badge <?php echo esc_attr( $status_class ); ?>">
            <?php echo esc_html( __( $incident->status, $td ) ); ?>
        </span>
        <?php if ( $incident->app_name ) : ?>
        <span class="nim-incident__app"><?php echo esc_html( $incident->app_name ); ?></span>
        <?php endif; ?>
    </div>

    <h2 class="nim-incident__title">
        <?php echo esc_html( $incident->reference ?: __( '(No reference)', $td ) ); ?>
    </h2>

    <?php if ( $incident->description ) : ?>
    <div class="nim-incident__description">
        <?php echo wp_kses_post( wpautop( $incident->description ) ); ?>
    </div>
    <?php endif; ?>

    <p class="nim-incident__date">
        <?php
        printf(
            /* translators: %s: formatted date */
            esc_html__( 'Reported on %s', $td ),
            esc_html( wp_date(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                strtotime( ( $incident->start_at ?? $incident->created_at ) . ' UTC' )
            ) )
        );
        ?>
    </p>

</li>
