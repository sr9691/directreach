<?php
/**
 * Journey Circles REST Controller
 *
 * Handles REST API endpoints for journey circles.
 *
 * @package Journey_Circle
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DR_Journey_Circles_Controller extends WP_REST_Controller {

    /**
     * Namespace
     *
     * @var string
     */
    protected $namespace = 'directreach/v2';

    /**
     * Rest base
     *
     * @var string
     */
    protected $rest_base = 'journey-circles';

    /**
     * Journey Circle Manager instance
     *
     * @var DR_Journey_Circle_Manager
     */
    private $manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->manager = new DR_Journey_Circle_Manager();
    }

    /**
     * Register routes
     */
    public function register_routes() {
        // GET /journey-circles?service_area_id={id} - Get journey circle for service area
        // POST /journey-circles - Create new journey circle
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_items' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
                'args'                => array(
                    'service_area_id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'description'       => 'Service area ID to get journey circle for.',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
                'args'                => array(
                    'service_area_id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'description'       => 'Service area ID.',
                    ),
                    'industries' => array(
                        'type'    => 'array',
                        'default' => array(),
                    ),
                    'brain_content' => array(
                        'type'    => 'array',
                        'default' => array(),
                    ),
                    'status' => array(
                        'type'    => 'string',
                        'default' => 'incomplete',
                    ),
                ),
            ),
        ) );

        // GET /journey-circles/{id} - Get single journey circle
        // PUT /journey-circles/{id} - Update journey circle
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\\d]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_item' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_item' ),
                'permission_callback' => array( $this, 'update_item_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'industries' => array(
                        'type' => 'array',
                    ),
                    'brain_content' => array(
                        'type' => 'array',
                    ),
                    'primary_problem_id' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'status' => array(
                        'type' => 'string',
                    ),
                ),
            ),
        ) );
    }

    /**
     * Permission checks
     */
    public function get_items_permissions_check( $request ) {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_campaigns' );
    }

    public function create_item_permissions_check( $request ) {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_campaigns' );
    }

    public function get_item_permissions_check( $request ) {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_campaigns' );
    }

    public function update_item_permissions_check( $request ) {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_campaigns' );
    }

    /**
     * Get journey circle(s) by service area ID.
     *
     * FIX: Returns empty JSON object {} instead of null when no circle exists,
     * preventing 'Unexpected end of JSON input' in the JS client.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_items( $request ) {
        $service_area_id = $request->get_param( 'service_area_id' );

        $result = $this->manager->get_by_service_area( $service_area_id );

        if ( is_wp_error( $result ) ) {
            // Not found is OK — means one doesn't exist yet.
            // Return empty object (not null) so response.json() doesn't fail.
            return new WP_REST_Response( new stdClass(), 200 );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Create a new journey circle.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_item( $request ) {
        $args = array(
            'service_area_id' => $request->get_param( 'service_area_id' ),
            'industries'      => $request->get_param( 'industries' ) ?? array(),
            'brain_content'   => $request->get_param( 'brain_content' ) ?? array(),
            'status'          => $request->get_param( 'status' ) ?? 'incomplete',
        );

        $result = $this->manager->create( $args );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        // Return the created journey circle
        $journey_circle = $this->manager->get( $result );

        if ( is_wp_error( $journey_circle ) ) {
            return new WP_REST_Response(
                array(
                    'code'    => 'creation_error',
                    'message' => 'Journey circle created but could not be retrieved.',
                ),
                500
            );
        }

        return new WP_REST_Response( $journey_circle, 201 );
    }

    /**
     * Get a single journey circle.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        $id = $request->get_param( 'id' );

        $result = $this->manager->get( $id );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ),
                404
            );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Update a journey circle.
     *
     * FIX: Removed wp_set_object_terms() for industries. Industries are string
     * arrays like "Agriculture|Crop Farming" — absint() on these returns 0,
     * corrupting the taxonomy. Post meta handles storage correctly.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $id = $request->get_param( 'id' );

        // Verify journey circle exists
        $existing = $this->manager->get( $id );
        if ( is_wp_error( $existing ) ) {
            return new WP_REST_Response(
                array(
                    'code'    => 'not_found',
                    'message' => 'Journey circle not found.',
                ),
                404
            );
        }

        // Update meta fields if provided
        $updated = false;

        if ( $request->has_param( 'industries' ) ) {
            $industries = $request->get_param( 'industries' );
            update_post_meta( $id, '_jc_industries', $industries );
            // Note: We no longer sync to taxonomy terms.
            // absint() on strings like "Agriculture|Crop Farming" returns 0,
            // which corrupted the jc_industry taxonomy. Post meta is the
            // authoritative source for industry data.
            $updated = true;
        }

        if ( $request->has_param( 'brain_content' ) ) {
            update_post_meta( $id, '_jc_brain_content', $request->get_param( 'brain_content' ) );
            $updated = true;
        }

        if ( $request->has_param( 'primary_problem_id' ) ) {
            update_post_meta( $id, '_jc_primary_problem_id', absint( $request->get_param( 'primary_problem_id' ) ) );
            $updated = true;
        }

        if ( $request->has_param( 'status' ) ) {
            wp_set_object_terms( $id, sanitize_text_field( $request->get_param( 'status' ) ), 'jc_status' );
            $updated = true;
        }

        if ( $updated ) {
            // Touch the post to update modified date
            wp_update_post( array( 'ID' => $id ) );
        }

        // Return updated journey circle
        $result = $this->manager->get( $id );

        return new WP_REST_Response( $result, 200 );
    }
}