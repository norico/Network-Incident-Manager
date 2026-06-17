<?php
/**
 * NIM_REST_API — REST API routes and callbacks.
 *
 * Routes:
 *   GET  /wp-json/network-incidents/v1/list              — public, paginated, filterable
 *   POST /wp-json/network-incidents/v1/incidents         — auth: edit_posts
 *   PUT|PATCH /wp-json/network-incidents/v1/incidents/{id} — auth: edit_posts
 *
 * @package NetworkIncidentManager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NIM_REST_API {

    const NAMESPACE = 'network-incidents/v1';

    public static function register_hooks() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {

        register_rest_route( self::NAMESPACE, '/list', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_incidents' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'status' => [
                    'description'       => __( 'Filter by incident status.', 'network-incident-manager' ),
                    'type'              => 'string',
                    'enum'              => NIM_Helpers::STATUSES,
                    'required'          => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'severity' => [
                    'description'       => __( 'Filter by incident severity.', 'network-incident-manager' ),
                    'type'              => 'string',
                    'enum'              => NIM_Helpers::SEVERITIES,
                    'required'          => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'app_id' => [
                    'description'       => __( 'Filter by application ID.', 'network-incident-manager' ),
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'required'          => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'description'       => __( 'Results per page (1-100).', 'network-incident-manager' ),
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'default'           => 20,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'description'       => __( 'Page number (1-based).', 'network-incident-manager' ),
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'default'           => 1,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/incidents', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_incident' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );

        register_rest_route( self::NAMESPACE, '/incidents/(?P<id>\d+)', [
            'methods'             => [ 'PUT', 'PATCH' ],
            'callback'            => [ __CLASS__, 'update_incident' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Callbacks
    // -----------------------------------------------------------------------

    /**
     * GET /list — paginated, filterable incident list.
     */
    public static function list_incidents( WP_REST_Request $request ) {
        global $wpdb;
        $table      = $wpdb->prefix . NIM_TABLE;
        $table_apps = $wpdb->prefix . NIM_APPS_TABLE;

        $where  = [];
        $params = [];

        if ( $v = $request->get_param( 'status' ) ) {
            $where[]  = 'i.status = %s';
            $params[] = $v;
        }
        if ( $v = $request->get_param( 'severity' ) ) {
            $where[]  = 'i.severity = %s';
            $params[] = $v;
        }
        if ( $v = $request->get_param( 'app_id' ) ) {
            $where[]  = 'i.app_id = %d';
            $params[] = $v;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $per_page  = (int) $request->get_param( 'per_page' );
        $page      = (int) $request->get_param( 'page' );
        $offset    = ( $page - 1 ) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM $table i $where_sql";
        $total     = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
            : $wpdb->get_var( $count_sql ) );

        $select_sql = "SELECT i.id, i.reference, i.description, i.severity,
                              i.status, i.app_id, i.start_at, i.created_at, i.updated_at,
                              a.name AS application
                       FROM $table i
                       LEFT JOIN $table_apps a ON i.app_id = a.id
                       $where_sql
                       ORDER BY i.created_at DESC
                       LIMIT %d OFFSET %d";

        $query_params = array_merge( $params, [ $per_page, $offset ] );
        $results      = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_params ) ); // phpcs:ignore

        $response = new WP_REST_Response( $results ?? [], 200 );
        $response->header( 'X-WP-Total',      $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
        return $response;
    }

    /**
     * POST /incidents — create a new incident.
     */
    public static function create_incident( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . NIM_TABLE;

        $data = self::sanitize_incident_fields( $request );
        $data['author_id']  = get_current_user_id();
        $data['created_at'] = current_time( 'mysql', true );
        $data['updated_at'] = current_time( 'mysql', true );

        $wpdb->insert( $table, $data );
        $incident = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ) );
        return new WP_REST_Response( $incident, 201 );
    }

    /**
     * PUT|PATCH /incidents/{id} — update an existing incident.
     */
    public static function update_incident( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . NIM_TABLE;
        $id    = absint( $request->get_param( 'id' ) );

        $incident = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $incident ) {
            return new WP_REST_Response(
                [ 'code' => 'nim_not_found', 'message' => __( 'Incident not found.', 'network-incident-manager' ) ],
                404
            );
        }

        $fields = array_merge(
            self::sanitize_incident_fields( $request, /* partial */ true ),
            [ 'updated_at' => current_time( 'mysql', true ) ]
        );

        $wpdb->update( $table, $fields, [ 'id' => $id ] );
        return new WP_REST_Response(
            $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ),
            200
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Sanitize incident fields from a REST request.
     *
     * @param WP_REST_Request $request
     * @param bool            $partial When true, only include non-null params (for PATCH/PUT).
     * @return array
     */
    private static function sanitize_incident_fields( WP_REST_Request $request, bool $partial = false ) {
        $fields = [];

        $ref = $request->get_param( 'reference' );
        if ( ! $partial || null !== $ref ) {
            $fields['reference'] = sanitize_text_field( $ref ?? '' );
        }

        $desc = $request->get_param( 'description' );
        if ( ! $partial || null !== $desc ) {
            $fields['description'] = wp_kses_post( $desc ?? '' );
        }

        $severity = $request->get_param( 'severity' );
        if ( in_array( $severity, NIM_Helpers::SEVERITIES, true ) ) {
            $fields['severity'] = $severity;
        } elseif ( ! $partial ) {
            $fields['severity'] = 'Minor';
        }

        $status = $request->get_param( 'status' );
        if ( in_array( $status, NIM_Helpers::STATUSES, true ) ) {
            $fields['status'] = $status;
        } elseif ( ! $partial ) {
            $fields['status'] = 'Scheduled';
        }

        $app_id = $request->get_param( 'app_id' );
        if ( ! $partial || null !== $app_id ) {
            $fields['app_id'] = absint( $app_id ?? 0 );
        }

        $start_at = $request->get_param( 'start_at' );
        if ( ! $partial ) {
            $fields['start_at'] = $start_at
                ? NIM_Helpers::parse_start_at( $start_at )
                : current_time( 'mysql', true );
        } elseif ( null !== $start_at ) {
            $fields['start_at'] = NIM_Helpers::parse_start_at( $start_at );
        }

        return $fields;
    }
}
