<?php
/**
 * Default template for the /incidents/ page.
 *
 * To override this template, copy it to your theme root:
 *   {your-theme}/page-incidents.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$incidents = NIM_DB::get_active_incidents();
$scheduled = NIM_DB::get_scheduled_incidents();
$resolved  = NIM_DB::get_resolved_incidents( 5 );

get_header();
?>

<main id="nim-incidents-page" class="nim-incidents-page">

    <?php /* ----------------------------------------------------------------
     * Section 1 — Active incidents
     * -------------------------------------------------------------- */ ?>
    <header class="nim-incidents-header">
        <h1 class="nim-incidents-title">
            <?php esc_html_e( 'Incidents', NIM_TD ); ?>
        </h1>
        <?php if ( ! empty( $incidents ) ) : ?>
        <p class="nim-incidents-count">
            <?php printf(
                esc_html( _n( '%d active incident', '%d active incidents', count( $incidents ), NIM_TD ) ),
                count( $incidents )
            ); ?>
        </p>
        <?php endif; ?>
    </header>

    <?php if ( empty( $incidents ) ) : ?>
    <div class="nim-no-incidents">
        <span class="nim-no-incidents-icon" aria-hidden="true">&#10003;</span>
        <p><?php esc_html_e( 'No active incidents at this time.', NIM_TD ); ?></p>
    </div>
    <?php else : ?>
    <ul class="nim-incidents-list">
        <?php foreach ( $incidents as $incident ) :
            nim_get_template_part( 'incident-active', [ 'incident' => $incident ] );
        endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php /* ----------------------------------------------------------------
     * Section 2 — Scheduled incidents
     * -------------------------------------------------------------- */ ?>
    <?php if ( ! empty( $scheduled ) ) : ?>
    <section class="nim-scheduled-section">
        <h2 class="nim-scheduled-title">
            <?php esc_html_e( 'Scheduled Incidents', NIM_TD ); ?>
        </h2>
        <ul class="nim-scheduled-list">
            <?php foreach ( $scheduled as $incident ) :
                nim_get_template_part( 'incident-scheduled', [ 'incident' => $incident ] );
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php /* ----------------------------------------------------------------
     * Section 3 — Recently resolved
     * -------------------------------------------------------------- */ ?>
    <?php if ( ! empty( $resolved ) ) : ?>
    <section class="nim-resolved-section">
        <h2 class="nim-resolved-title">
            <?php esc_html_e( 'Recently Resolved', NIM_TD ); ?>
        </h2>
        <ul class="nim-resolved-list">
            <?php foreach ( $resolved as $incident ) :
                nim_get_template_part( 'incident-resolved', [ 'incident' => $incident ] );
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

</main>

<?php get_footer(); ?>
