<?php
/**
 * Default template for the /incidents/ page.
 *
 * To override this template, copy it to your theme root:
 *   {your-theme}/page-incidents.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

// 1. Active incidents (In Progress)
$incidents = $wpdb->get_results(
    "SELECT i.id, i.reference, i.description, i.severity, i.status, i.start_at, i.created_at,
            a.name AS app_name
     FROM {$wpdb->prefix}incidents i
     LEFT JOIN {$wpdb->prefix}incident_apps a ON i.app_id = a.id
     WHERE i.status = 'In Progress'
     ORDER BY
         CASE i.severity WHEN 'Critical' THEN 1 WHEN 'Major' THEN 2 ELSE 3 END,
         i.start_at ASC"
);

// 2. Scheduled incidents (future start_at)
$scheduled = $wpdb->get_results(
    "SELECT i.id, i.reference, i.description, i.severity, i.status, i.start_at,
            a.name AS app_name
     FROM {$wpdb->prefix}incidents i
     LEFT JOIN {$wpdb->prefix}incident_apps a ON i.app_id = a.id
     WHERE i.status = 'Scheduled'
     ORDER BY i.start_at ASC"
);

// 3. Recently resolved (last 5)
$resolved = $wpdb->get_results(
    "SELECT i.id, i.reference, i.severity, i.updated_at,
            a.name AS app_name
     FROM {$wpdb->prefix}incidents i
     LEFT JOIN {$wpdb->prefix}incident_apps a ON i.app_id = a.id
     WHERE i.status = 'Resolved'
     ORDER BY i.updated_at DESC
     LIMIT 5"
);

get_header();
?>

<main id="nim-incidents-page" class="nim-incidents-page">

    <?php /* ----------------------------------------------------------------
     * Section 1 — Active incidents
     * -------------------------------------------------------------- */ ?>
    <header class="nim-incidents-header">
        <h1 class="nim-incidents-title">
            <?php esc_html_e( 'Incidents', 'network-incident-manager' ); ?>
        </h1>
        <?php if ( ! empty( $incidents ) ) : ?>
        <p class="nim-incidents-count">
            <?php printf(
                esc_html( _n( '%d active incident', '%d active incidents', count( $incidents ), 'network-incident-manager' ) ),
                count( $incidents )
            ); ?>
        </p>
        <?php endif; ?>
    </header>

    <?php if ( empty( $incidents ) ) : ?>
    <div class="nim-no-incidents">
        <span class="nim-no-incidents-icon" aria-hidden="true">&#10003;</span>
        <p><?php esc_html_e( 'No active incidents at this time.', 'network-incident-manager' ); ?></p>
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
            <?php esc_html_e( 'Scheduled Incidents', 'network-incident-manager' ); ?>
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
            <?php esc_html_e( 'Recently Resolved', 'network-incident-manager' ); ?>
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
