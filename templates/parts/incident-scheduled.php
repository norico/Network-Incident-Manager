<?php
/**
 * Template part — scheduled incident row.
 *
 * Available variables:
 *   $incident  stdClass  ->id, ->reference, ->description, ->severity,
 *                        ->status, ->start_at (UTC), ->app_name
 *
 * Theme override: copy to {theme}/nim/incident-scheduled.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$severity_class = 'nim-severity--' . strtolower( esc_attr( $incident->severity ) );
?>
<li class="nim-scheduled-item">
    <div class="nim-scheduled-item__header">
        <span class="nim-badge <?php echo esc_attr( $severity_class ); ?>">
            <?php echo esc_html( NIM_Helpers::severity_label( $incident->severity ) ); ?>
        </span>
        <?php if ( $incident->app_name ) : ?>
            <span class="nim-incident__app"><?php echo esc_html( $incident->app_name ); ?></span>
        <?php endif; ?>
    </div>
    <p class="nim-scheduled-item__title">
        <?php echo esc_html( $incident->reference ?: __( '(No reference)', NIM_TD ) ); ?>
    </p>

    <?php if ( $incident->description ) : ?>
    <div class="nim-incident__description">
        <?php echo wp_kses_post( wpautop( $incident->description ) ); ?>
    </div>
    <?php endif; ?>

    <p class="nim-scheduled-item__date">
        <?php
        printf(
            /* translators: %s: formatted date */
            esc_html__( 'Scheduled for %s', NIM_TD ),
            esc_html( wp_date(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                strtotime( $incident->start_at . ' UTC' )
            ) )
        );
        ?>
    </p>
</li>


