<?php
/**
 * AI Content REST Controller
 *
 * REST API endpoints for AI-powered title generation in Journey Circles.
 *
 * Part of Iteration 8: AI Title Recommendations
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DR_AI_Content_Controller
 *
 * Provides REST API endpoints for generating problem and solution titles
 * using AI (Google Gemini API).
 *
 * Endpoints:
 * - POST /directreach/v2/ai/generate-problem-titles
 * - POST /directreach/v2/ai/generate-solution-titles
 * - POST /directreach/v2/ai/check-status
 */
class DR_AI_Content_Controller extends WP_REST_Controller {

    /**
     * API namespace.
     *
     * @var string
     */
    protected $namespace = 'directreach/v2';

    /**
     * Route base for AI endpoints.
     *
     * @var string
     */
    protected $rest_base = 'ai';

    /**
     * AI Content Generator instance.
     *
     * @var DR_AI_Content_Generator
     */
    private $generator;

    /**
     * Constructor.
     */
    public function __construct() {
        // Load the generator class if not already loaded.
        if ( ! class_exists( 'DR_AI_Content_Generator' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'journey-circle/class-ai-content-generator.php';
        }
        $this->generator = new DR_AI_Content_Generator();
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        // POST /ai/generate-problem-titles
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/generate-problem-titles', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'generate_problem_titles' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => $this->get_problem_titles_args(),
            ),
        ) );

        // POST /ai/generate-solution-titles
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/generate-solution-titles', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'generate_solution_titles' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => $this->get_solution_titles_args(),
            ),
        ) );

        // GET /ai/check-status
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/check-status', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'check_status' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
        ) );

        // POST /ai/generate-outline
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/generate-outline', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'generate_outline' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
        ) );

        // POST /ai/generate-content
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/generate-content', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'generate_content' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
        ) );
    }

    // =========================================================================
    // PERMISSION CHECKS
    // =========================================================================

    /**
     * Check if the current user has permission to use AI endpoints.
     *
     * Requires valid nonce and manage_campaigns capability.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error True if authorized, WP_Error otherwise.
     */
    public function check_permissions( $request ) {
        // Verify nonce.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Invalid or missing security token. Please refresh the page.', 'directreach' ),
                array( 'status' => 403 )
            );
        }

        // Check capability.
        if ( ! current_user_can( 'manage_campaigns' ) ) {
            // Fallback to manage_options for installations without custom capability.
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    __( 'You do not have permission to access AI features.', 'directreach' ),
                    array( 'status' => 403 )
                );
            }
        }

        return true;
    }

    // =========================================================================
    // ENDPOINT CALLBACKS
    // =========================================================================

    /**
     * Generate problem title recommendations.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function generate_problem_titles( $request ) {
        // Check if AI is configured.
        if ( ! $this->generator->is_configured() ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Gemini API key is not configured. Please set it in DirectReach Settings > AI.', 'directreach' ),
                'code'    => 'api_not_configured',
                'titles'  => array(),
            ), 503 );
        }

        // Extract and prepare arguments.
        $args = array(
            'service_area_id'   => absint( $request->get_param( 'service_area_id' ) ),
            'service_area_name' => sanitize_text_field( $request->get_param( 'service_area_name' ) ?? '' ),
            'industries'        => $this->sanitize_array_param( $request->get_param( 'industries' ) ),
            'brain_content'     => $this->sanitize_brain_content_param( $request->get_param( 'brain_content' ) ),
            'existing_assets'   => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'force_refresh'     => (bool) $request->get_param( 'force_refresh' ),
            'previous_titles'   => $this->sanitize_array_param( $request->get_param( 'previous_titles' ) ),
        );

        // Generate titles.
        $result = $this->generator->generate_problem_titles( $args );

        if ( is_wp_error( $result ) ) {
            $status_code = 500;
            $error_data  = $result->get_error_data();
            if ( isset( $error_data['status'] ) ) {
                $status_code = $error_data['status'];
            }

            // Map specific error codes to HTTP status codes.
            $code_status_map = array(
                'api_not_configured' => 503,
                'api_timeout'        => 504,
                'api_rate_limited'   => 429,
                'api_unauthorized'   => 401,
                'missing_service_area' => 400,
            );

            $error_code = $result->get_error_code();
            if ( isset( $code_status_map[ $error_code ] ) ) {
                $status_code = $code_status_map[ $error_code ];
            }

            return new WP_REST_Response( array(
                'success' => false,
                'error'   => $result->get_error_message(),
                'code'    => $error_code,
                'titles'  => array(),
            ), $status_code );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'titles'  => $result,
            'count'   => count( $result ),
            'cached'  => false, // Could be enhanced to detect cached vs fresh.
        ), 200 );
    }

    /**
     * Generate solution title recommendations.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function generate_solution_titles( $request ) {
        // Check if AI is configured.
        if ( ! $this->generator->is_configured() ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Gemini API key is not configured. Please set it in DirectReach Settings > AI.', 'directreach' ),
                'code'    => 'api_not_configured',
                'titles'  => array(),
            ), 503 );
        }

        // Extract and prepare arguments.
        $args = array(
            'problem_id'        => absint( $request->get_param( 'problem_id' ) ),
            'problem_title'     => sanitize_text_field( $request->get_param( 'problem_title' ) ),
            'service_area_name' => sanitize_text_field( $request->get_param( 'service_area_name' ) ?? '' ),
            'brain_content'     => $this->sanitize_brain_content_param( $request->get_param( 'brain_content' ) ),
            'existing_assets'   => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'industries'        => $this->sanitize_array_param( $request->get_param( 'industries' ) ),
            'force_refresh'     => (bool) $request->get_param( 'force_refresh' ),
            'exclude_titles'    => $this->sanitize_array_param( $request->get_param( 'exclude_titles' ) ),
        );

        // Generate titles.
        $result = $this->generator->generate_solution_titles( $args );

        if ( is_wp_error( $result ) ) {
            $status_code = 500;
            $error_code  = $result->get_error_code();

            $code_status_map = array(
                'api_not_configured'  => 503,
                'api_timeout'         => 504,
                'api_rate_limited'    => 429,
                'api_unauthorized'    => 401,
                'missing_problem_title' => 400,
            );

            if ( isset( $code_status_map[ $error_code ] ) ) {
                $status_code = $code_status_map[ $error_code ];
            }

            return new WP_REST_Response( array(
                'success' => false,
                'error'   => $result->get_error_message(),
                'code'    => $error_code,
                'titles'  => array(),
            ), $status_code );
        }

        return new WP_REST_Response( array(
            'success'    => true,
            'titles'     => $result,
            'count'      => count( $result ),
            'problem_id' => absint( $request->get_param( 'problem_id' ) ),
        ), 200 );
    }

    /**
     * Check AI service status and configuration.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response Response object.
     */
    public function check_status( $request ) {
        $configured = $this->generator->is_configured();

        return new WP_REST_Response( array(
            'configured' => $configured,
            'model'      => $configured ? DR_AI_Content_Generator::DEFAULT_MODEL : null,
            'message'    => $configured
                ? __( 'AI service is configured and ready.', 'directreach' )
                : __( 'Gemini API key is not set. Please configure it in DirectReach Settings > AI.', 'directreach' ),
        ), 200 );
    }

    /**
     * Generate a content outline.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object.
     */
    public function generate_outline( $request ) {
        $args = array(
            'problem_title'     => $request->get_param( 'problem_title' ),
            'solution_title'    => $request->get_param( 'solution_title' ),
            'format'            => $request->get_param( 'format' ) ?: 'article_long',
            'brain_content'     => $request->get_param( 'brain_content' ) ?: array(),
            'industries'        => $request->get_param( 'industries' ) ?: array(),
            'existing_outline'  => $request->get_param( 'existing_outline' ) ?: '',
            'feedback'          => $request->get_param( 'feedback' ) ?: '',
            'service_area_id'   => $request->get_param( 'service_area_id' ) ?: 0,
            'focus'             => $request->get_param( 'focus' ) ?: '',
            'focus_instruction' => $request->get_param( 'focus_instruction' ) ?: '',
        );

        $result = $this->generator->generate_outline( $args );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => $result->get_error_message(),
            ), 503 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'outline' => $result['outline'],
        ), 200 );
    }

    /**
     * Generate full content from an outline.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object.
     */
    public function generate_content( $request ) {
        $args = array(
            'problem_title'     => $request->get_param( 'problem_title' ),
            'solution_title'    => $request->get_param( 'solution_title' ),
            'format'            => $request->get_param( 'format' ) ?: 'article_long',
            'outline'           => $request->get_param( 'outline' ) ?: '',
            'brain_content'     => $request->get_param( 'brain_content' ) ?: array(),
            'industries'        => $request->get_param( 'industries' ) ?: array(),
            'existing_content'  => $request->get_param( 'existing_content' ) ?: '',
            'feedback'          => $request->get_param( 'feedback' ) ?: '',
            'service_area_id'   => $request->get_param( 'service_area_id' ) ?: 0,
            'focus'             => $request->get_param( 'focus' ) ?: '',
            'focus_instruction' => $request->get_param( 'focus_instruction' ) ?: '',
        );

        $result = $this->generator->generate_content( $args );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => $result->get_error_message(),
            ), 503 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'content' => $result['content'],
        ), 200 );
    }

    // =========================================================================
    // ARGUMENT DEFINITIONS
    // =========================================================================

    /**
     * Get argument schema for problem title generation.
     *
     * @return array Argument definitions.
     */
    private function get_problem_titles_args() {
        return array(
            'service_area_id' => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'description'       => __( 'Service area ID.', 'directreach' ),
            ),
            'service_area_name' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Service area name (used if ID not provided).', 'directreach' ),
            ),
            'industries' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Array of industry names or IDs.', 'directreach' ),
            ),
            'brain_content' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Array of brain content items.', 'directreach' ),
            ),
            'force_refresh' => array(
                'required'    => false,
                'type'        => 'boolean',
                'default'     => false,
                'description' => __( 'Skip cache and generate fresh titles.', 'directreach' ),
            ),
            'existing_assets' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Array of existing content assets (URLs, files) from Step 3.', 'directreach' ),
<<<<<<< HEAD
            ),            
=======
            ),
            'previous_titles' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Previously generated titles to avoid on regeneration.', 'directreach' ),
            ),
>>>>>>> cf0726f453a50a07f273f20dc00d17b3253085d8
        );
    }

    /**
     * Get argument schema for solution title generation.
     *
     * @return array Argument definitions.
     */
    private function get_solution_titles_args() {
        return array(
            'problem_id' => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'description'       => __( 'Problem post ID.', 'directreach' ),
            ),
            'problem_title' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Problem title text.', 'directreach' ),
            ),
            'service_area_name' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Service area name for context.', 'directreach' ),
            ),
            'brain_content' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Array of brain content items.', 'directreach' ),
            ),
            'industries' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Industry names for context.', 'directreach' ),
            ),
            'force_refresh' => array(
                'required'    => false,
                'type'        => 'boolean',
                'default'     => false,
                'description' => __( 'Skip cache and generate fresh titles.', 'directreach' ),
            ),
            'existing_assets' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Array of existing content assets for context.', 'directreach' ),
<<<<<<< HEAD
            ),            
=======
            ),
            'exclude_titles' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Already-selected solution titles to avoid duplicating.', 'directreach' ),
            ),
>>>>>>> cf0726f453a50a07f273f20dc00d17b3253085d8
        );
    }

    // =========================================================================
    // SANITIZATION HELPERS
    // =========================================================================

    /**
     * Sanitize an array parameter (industries, etc).
     *
     * @param mixed $param Raw parameter value.
     * @return array Sanitized array.
     */
    private function sanitize_array_param( $param ) {
        if ( empty( $param ) ) {
            return array();
        }

        if ( is_string( $param ) ) {
            $param = json_decode( $param, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return array();
            }
        }

        if ( ! is_array( $param ) ) {
            return array();
        }

        return array_map( 'sanitize_text_field', $param );
    }

    /**
     * Sanitize brain content parameter.
     *
     * Each item should have 'type' and 'value' keys.
     *
     * @param mixed $param Raw parameter value.
     * @return array Sanitized brain content array.
     */
    private function sanitize_brain_content_param( $param ) {
        if ( empty( $param ) ) {
            return array();
        }

        if ( is_string( $param ) ) {
            $param = json_decode( $param, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return array();
            }
        }

        if ( ! is_array( $param ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $param as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['type'] ) ) {
                continue;
            }

            $clean_item = array(
                'type' => sanitize_text_field( $item['type'] ),
            );

            switch ( $clean_item['type'] ) {
                case 'url':
                    $clean_item['value'] = esc_url_raw( $item['value'] ?? '' );
                    break;

                case 'text':
                    $clean_item['value'] = wp_kses_post( $item['value'] ?? '' );
                    break;

                case 'file':
                    $clean_item['value']    = sanitize_file_name( $item['value'] ?? '' );
                    $clean_item['filename'] = sanitize_file_name( $item['filename'] ?? '' );
                    $clean_item['fileId']   = absint( $item['fileId'] ?? 0 );
                    break;

                default:
                    continue 2; // Skip unknown types.
            }

            $sanitized[] = $clean_item;
        }

        return $sanitized;
    }
<<<<<<< HEAD
=======

>>>>>>> cf0726f453a50a07f273f20dc00d17b3253085d8
    /**
     * Sanitize the existing_assets parameter.
     *
     * Existing assets follow a similar structure to brain content:
     * {type: 'url'|'file', value: '...', name: '...', mimeType: '...'}
     *
     * @param mixed $param Raw parameter value.
     * @return array Sanitized assets array.
     */
    private function sanitize_existing_assets_param( $param ) {
        if ( empty( $param ) ) {
            return array();
        }

        if ( is_string( $param ) ) {
            $param = json_decode( $param, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return array();
            }
        }

        if ( ! is_array( $param ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $param as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['type'] ) ) {
                continue;
            }

            $type = sanitize_text_field( $item['type'] );
            $clean_item = array(
                'type' => $type,
                'name' => sanitize_text_field( $item['name'] ?? '' ),
            );

            switch ( $type ) {
                case 'url':
                    $clean_item['value'] = esc_url_raw( $item['value'] ?? '' );
                    break;

                case 'file':
                    $clean_item['value']    = sanitize_file_name( $item['value'] ?? '' );
                    $clean_item['mimeType'] = sanitize_mime_type( $item['mimeType'] ?? '' );
                    $clean_item['fileId']   = absint( $item['fileId'] ?? 0 );
                    break;

                default:
                    $clean_item['value'] = wp_kses_post( $item['value'] ?? '' );
                    break;
            }

            $sanitized[] = $clean_item;
        }

        return $sanitized;
    }
}

/**
 * Register AI Content Controller routes on rest_api_init.
 */
add_action( 'rest_api_init', function() {
    $controller = new DR_AI_Content_Controller();
    $controller->register_routes();
} );
