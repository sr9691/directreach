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
        // POST /ai/generate-primary-problems
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/generate-primary-problems', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'generate_primary_problems' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => array(
                    'service_area_id'   => array( 'type' => 'integer', 'default' => 0 ),
                    'service_area_name' => array( 'type' => 'string', 'default' => '' ),
                    'industries'        => array( 'type' => 'array', 'default' => array() ),
                    'brain_content'     => array( 'type' => 'array', 'default' => array() ),
                    'existing_assets'   => array( 'type' => 'array', 'default' => array() ),
                    'force_refresh'     => array( 'type' => 'boolean', 'default' => false ),
                ),
            ),
        ) );

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

        // POST /ai/extraction-status — check extraction status for asset file IDs.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/extraction-status', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'check_extraction_status' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => array(
                    'file_ids' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_array( $param );
                        },
                    ),
                ),
            ),
        ) );

        // POST /ai/extract-asset — trigger extraction for a single attachment.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/extract-asset', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'extract_asset' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => array(
                    'file_id' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param ) && absint( $param ) > 0;
                        },
                    ),
                ),
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

        // POST /ai/fast-track-content — generate full article using JourneyOS methodology.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/fast-track-content', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'fast_track_content' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args'                => array(
                    'problem_title'      => array( 'type' => 'string', 'required' => true ),
                    'solution_title'     => array( 'type' => 'string', 'required' => true ),
                    'focus'              => array(
                        'type'     => 'string',
                        'required' => true,
                        'enum'     => array( 'problem', 'solution' ),
                    ),
                    'brain_content'      => array( 'type' => 'array', 'default' => array() ),
                    'existing_assets'    => array( 'type' => 'array', 'default' => array() ),
                    'industries'         => array( 'type' => 'array', 'default' => array() ),
                    'service_area_id'    => array( 'type' => 'integer', 'default' => 0 ),
                    'content_set_titles' => array( 'type' => 'array', 'default' => array() ),
                    'evaluative_lens'    => array( 'type' => 'string', 'default' => '' ),
                ),
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

    // =========================================================================
    // STEP 5: PRIMARY PROBLEM
    // =========================================================================

    /**
     * Generate Primary Problem statement candidates.
     *
     * @since 2.2.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function generate_primary_problems( $request ) {
        // Extend PHP execution time — this prompt is large and Gemini needs time.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $args = array(
            'service_area_id'   => absint( $request->get_param( 'service_area_id' ) ),
            'service_area_name' => sanitize_text_field( $request->get_param( 'service_area_name' ) ?? '' ),
            'industries'        => $this->sanitize_array_param( $request->get_param( 'industries' ) ),
            'brain_content'     => $this->sanitize_brain_content_param( $request->get_param( 'brain_content' ) ),
            'existing_assets'   => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'force_refresh'     => (bool) $request->get_param( 'force_refresh' ),
        );

        // Enrich content.
        $args['brain_content']   = $this->enrich_brain_content_with_extracts( $args['brain_content'] );
        $args['existing_assets'] = $this->enrich_existing_assets( $args['existing_assets'] );

        $extraction_stats = $this->compute_extraction_stats( $args['existing_assets'] );

        error_log( '[JC API] generate_primary_problems — calling generator...' );

        // Generate.
        $result = $this->generator->generate_primary_problems( $args );

        error_log( '[JC API] generate_primary_problems — generator returned: ' . ( is_wp_error( $result ) ? 'WP_Error: ' . $result->get_error_message() : 'success (' . count( $result['statements'] ?? [] ) . ' statements)' ) );

        if ( is_wp_error( $result ) ) {
            $status_code = 500;
            $error_code  = $result->get_error_code();

            $code_status_map = array(
                'api_not_configured'  => 503,
                'api_timeout'         => 504,
                'api_rate_limited'    => 429,
                'api_unauthorized'    => 401,
                'missing_service_area' => 400,
            );

            if ( isset( $code_status_map[ $error_code ] ) ) {
                $status_code = $code_status_map[ $error_code ];
            }

            return new WP_REST_Response( array(
                'success'          => false,
                'error'            => $result->get_error_message(),
                'code'             => $error_code,
                'extraction_stats' => $extraction_stats,
            ), $status_code );
        }

        return new WP_REST_Response( array(
            'success'          => true,
            'statements'       => $result['statements'] ?? array(),
            'best_pick'        => $result['best_pick'] ?? null,
            'extracted_fields' => $result['extracted_fields'] ?? array(),
            'extraction_stats' => $extraction_stats,
        ), 200 );
    }

    // =========================================================================
    // STEP 6: PROBLEM TITLES
    // =========================================================================

    /**
     * Generate problem title recommendations for a given service area.
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
            'service_area_id'            => absint( $request->get_param( 'service_area_id' ) ),
            'service_area_name'          => sanitize_text_field( $request->get_param( 'service_area_name' ) ?? '' ),
            'primary_problem_statement'  => sanitize_text_field( $request->get_param( 'primary_problem_statement' ) ?? '' ),
            'industries'                 => $this->sanitize_array_param( $request->get_param( 'industries' ) ),
            'brain_content'              => $this->sanitize_brain_content_param( $request->get_param( 'brain_content' ) ),
            'existing_assets'            => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'force_refresh'              => (bool) $request->get_param( 'force_refresh' ),
            'previous_titles'            => $this->sanitize_array_param( $request->get_param( 'previous_titles' ) ),
        );

        // Enrich brain content with extracted text from storage.
        $args['brain_content']  = $this->enrich_brain_content_with_extracts( $args['brain_content'] );
        $args['existing_assets'] = $this->enrich_existing_assets( $args['existing_assets'] );

        // Compute extraction stats for frontend visibility.
        $extraction_stats = $this->compute_extraction_stats( $args['existing_assets'] );

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
                'success'          => false,
                'error'            => $result->get_error_message(),
                'code'             => $error_code,
                'titles'           => array(),
                'extraction_stats' => $extraction_stats,
            ), $status_code );
        }

        return new WP_REST_Response( array(
            'success'          => true,
            'titles'           => $result,
            'count'            => count( $result ),
            'cached'           => false,
            'extraction_stats' => $extraction_stats,
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

        // Enrich brain content with extracted text from storage.
        $args['brain_content']  = $this->enrich_brain_content_with_extracts( $args['brain_content'] );
        $args['existing_assets'] = $this->enrich_existing_assets( $args['existing_assets'] );

        // Compute extraction stats for frontend visibility.
        $extraction_stats = $this->compute_extraction_stats( $args['existing_assets'] );

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
                'success'          => false,
                'error'            => $result->get_error_message(),
                'code'             => $error_code,
                'titles'           => array(),
                'extraction_stats' => $extraction_stats,
            ), $status_code );
        }

        return new WP_REST_Response( array(
            'success'          => true,
            'titles'           => $result,
            'count'            => count( $result ),
            'problem_id'       => absint( $request->get_param( 'problem_id' ) ),
            'extraction_stats' => $extraction_stats,
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
            'existing_assets'   => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'industries'        => $request->get_param( 'industries' ) ?: array(),
            'existing_outline'  => $request->get_param( 'existing_outline' ) ?: '',
            'feedback'          => $request->get_param( 'feedback' ) ?: '',
            'service_area_id'   => $request->get_param( 'service_area_id' ) ?: 0,
            'focus'             => $request->get_param( 'focus' ) ?: '',
            'focus_instruction' => $request->get_param( 'focus_instruction' ) ?: '',
        );

        // Enrich brain content and existing assets with extracted text.
        if ( ! empty( $args['brain_content'] ) ) {
            $args['brain_content'] = $this->enrich_brain_content_with_extracts( $args['brain_content'] );
        }
        if ( ! empty( $args['existing_assets'] ) ) {
            $args['existing_assets'] = $this->enrich_existing_assets( $args['existing_assets'] );
        }

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
            'existing_assets'   => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'industries'        => $request->get_param( 'industries' ) ?: array(),
            'existing_content'  => $request->get_param( 'existing_content' ) ?: '',
            'feedback'          => $request->get_param( 'feedback' ) ?: '',
            'service_area_id'   => $request->get_param( 'service_area_id' ) ?: 0,
            'focus'             => $request->get_param( 'focus' ) ?: '',
            'focus_instruction' => $request->get_param( 'focus_instruction' ) ?: '',
        );

        // Enrich brain content and existing assets with extracted text.
        if ( ! empty( $args['brain_content'] ) ) {
            $args['brain_content'] = $this->enrich_brain_content_with_extracts( $args['brain_content'] );
        }
        if ( ! empty( $args['existing_assets'] ) ) {
            $args['existing_assets'] = $this->enrich_existing_assets( $args['existing_assets'] );
        }

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
    // FAST TRACK CONTENT
    // =========================================================================

    /**
     * Generate a full article using JourneyOS Fast Track methodology.
     *
     * Produces a complete problem or solution article directly (no outline step)
     * using enhanced prompts based on the JourneyOS prompt structure.
     *
     * @since 2.3.0
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response Response object.
     */
    public function fast_track_content( $request ) {
        // Extend PHP execution time — Fast Track articles use large prompts.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $args = array(
            'problem_title'      => $request->get_param( 'problem_title' ),
            'solution_title'     => $request->get_param( 'solution_title' ),
            'focus'              => $request->get_param( 'focus' ),
            'brain_content'      => $request->get_param( 'brain_content' ) ?: array(),
            'existing_assets'    => $this->sanitize_existing_assets_param( $request->get_param( 'existing_assets' ) ),
            'industries'         => $request->get_param( 'industries' ) ?: array(),
            'service_area_id'    => $request->get_param( 'service_area_id' ) ?: 0,
            'content_set_titles' => $request->get_param( 'content_set_titles' ) ?: array(),
            'evaluative_lens'    => $request->get_param( 'evaluative_lens' ) ?: '',
        );

        // Enrich brain content and existing assets with extracted text.
        if ( ! empty( $args['brain_content'] ) ) {
            $args['brain_content'] = $this->enrich_brain_content_with_extracts( $args['brain_content'] );
        }
        if ( ! empty( $args['existing_assets'] ) ) {
            $args['existing_assets'] = $this->enrich_existing_assets( $args['existing_assets'] );
        }

        $result = $this->generator->generate_fast_track_article( $args );

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
    // EXTRACTION STATUS
    // =========================================================================

    /**
     * Compute extraction statistics for a set of existing assets.
     *
     * Returns a summary object suitable for including in API responses
     * and for frontend status display.
     *
     * @since 2.1.0
     * @param array $items Enriched existing assets array.
     * @return array Extraction stats.
     */
    private function compute_extraction_stats( $items ) {
        if ( empty( $items ) || ! is_array( $items ) ) {
            return array(
                'total'     => 0,
                'extracted' => 0,
                'pending'   => 0,
                'failed'    => 0,
                'chars'     => 0,
                'items'     => array(),
            );
        }

        $total     = 0;
        $extracted = 0;
        $pending   = 0;
        $failed    = 0;
        $chars     = 0;
        $details   = array();

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $total++;
            $name           = $item['name'] ?? $item['value'] ?? '(unknown)';
            $extracted_text = isset( $item['extracted_text'] ) ? trim( $item['extracted_text'] ) : '';

            if ( ! empty( $extracted_text ) ) {
                $extracted++;
                $item_chars = strlen( $extracted_text );
                $chars += $item_chars;
                $details[] = array(
                    'name'   => $name,
                    'status' => 'extracted',
                    'chars'  => $item_chars,
                );
            } else {
                // Check if we can determine the actual status.
                $status = 'pending';
                $file_id = absint( $item['fileId'] ?? 0 );
                if ( $file_id > 0 ) {
                    $meta_status = get_post_meta( $file_id, '_jc_extraction_status', true );
                    if ( $meta_status === 'failed' ) {
                        $status = 'failed';
                        $failed++;
                    } else {
                        $pending++;
                    }
                } else {
                    $pending++;
                }

                $details[] = array(
                    'name'   => $name,
                    'status' => $status,
                    'chars'  => 0,
                );
            }
        }

        return array(
            'total'     => $total,
            'extracted' => $extracted,
            'pending'   => $pending,
            'failed'    => $failed,
            'chars'     => $chars,
            'items'     => $details,
        );
    }

    /**
     * Check extraction status for a list of asset file IDs.
     *
     * Called by the frontend to show extraction badges on each asset.
     *
     * @since 2.1.0
     * @param WP_REST_Request $request Request with 'file_ids' param.
     * @return WP_REST_Response Extraction status for each file ID.
     */
    public function check_extraction_status( $request ) {
        $file_ids = $request->get_param( 'file_ids' );
        if ( empty( $file_ids ) || ! is_array( $file_ids ) ) {
            return new WP_REST_Response( array( 'items' => array() ), 200 );
        }

        $results = array();
        foreach ( $file_ids as $id ) {
            $id = absint( $id );
            if ( $id <= 0 ) {
                continue;
            }

            $status    = get_post_meta( $id, '_jc_extraction_status', true ) ?: 'none';
            $extracted = get_post_meta( $id, '_jc_extracted_text', true );
            $chars     = ! empty( $extracted ) ? strlen( $extracted ) : 0;

            $results[] = array(
                'fileId' => $id,
                'status' => $status,
                'chars'  => $chars,
            );
        }

        return new WP_REST_Response( array( 'items' => $results ), 200 );
    }

    /**
     * Trigger extraction for a single attachment file.
     *
     * Called immediately after upload so the user gets real-time feedback
     * and the Next button can be gated on extraction completion.
     *
     * @since 2.1.0
     * @param WP_REST_Request $request Request with 'file_id' param.
     * @return WP_REST_Response Extraction result.
     */
    public function extract_asset( $request ) {
        $file_id = absint( $request->get_param( 'file_id' ) );

        // Verify this is a valid attachment.
        $post = get_post( $file_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'Invalid attachment ID.',
                'status'  => 'failed',
            ), 400 );
        }

        // Check if already extracted.
        $existing = get_post_meta( $file_id, '_jc_extracted_text', true );
        if ( ! empty( $existing ) ) {
            return new WP_REST_Response( array(
                'success' => true,
                'status'  => 'completed',
                'chars'   => strlen( $existing ),
            ), 200 );
        }

        // Lazy-load manager.
        if ( ! class_exists( 'Brain_Content_Manager' ) ) {
            $path = plugin_dir_path( dirname( __FILE__ ) ) . 'models/class-brain-content-manager.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }

        if ( ! class_exists( 'Brain_Content_Manager' ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'Brain Content Manager not available.',
                'status'  => 'failed',
            ), 500 );
        }

        $manager = new Brain_Content_Manager();
        $result  = $manager->extract_from_attachment( $file_id );

        if ( $result ) {
            $extracted = get_post_meta( $file_id, '_jc_extracted_text', true );
            $tone      = get_post_meta( $file_id, '_jc_tone_style_profile', true );

            return new WP_REST_Response( array(
                'success'      => true,
                'status'       => 'completed',
                'chars'        => strlen( $extracted ),
                'has_tone'     => ! empty( $tone ),
            ), 200 );
        }

        return new WP_REST_Response( array(
            'success' => false,
            'status'  => 'failed',
            'error'   => 'Extraction failed. Check server logs for details.',
        ), 200 );
    }

    // =========================================================================
    // CONTENT ENRICHMENT
    // =========================================================================

    /**
     * Enrich brain content items with stored extracted text.
     *
     * The frontend sends brain content as {type, value} objects.
     * This method looks up each item's stored extracted_text from
     * post meta and attaches it so the generator can use real content.
     *
     * @since 2.1.0
     * @param array $items Brain content or existing assets array.
     * @return array Enriched items with extracted_text added.
     */
    private function enrich_brain_content_with_extracts( $items ) {
        if ( empty( $items ) || ! is_array( $items ) ) {
            return $items;
        }

        foreach ( $items as &$item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            // Skip if already has extracted text (e.g., text type with inline content).
            if ( ! empty( $item['extracted_text'] ) && ! empty( $item['tone_style_profile'] ) ) {
                continue;
            }

            // Try to find the brain content post by value.
            $post_id = null;

            // If fileId is present, use it directly.
            if ( ! empty( $item['fileId'] ) ) {
                $post_id = absint( $item['fileId'] );
            } else {
                // Look up by content value.
                $post_id = $this->find_brain_content_post( $item );
            }

            if ( $post_id ) {
                if ( empty( $item['extracted_text'] ) ) {
                    $extracted = get_post_meta( $post_id, '_jc_extracted_text', true );
                    if ( ! empty( $extracted ) ) {
                        $item['extracted_text'] = $extracted;
                    }
                }

                // Also pull tone & style profile.
                if ( empty( $item['tone_style_profile'] ) ) {
                    $tone_profile = get_post_meta( $post_id, '_jc_tone_style_profile', true );
                    if ( ! empty( $tone_profile ) ) {
                        $item['tone_style_profile'] = $tone_profile;
                    }
                }
            }

            // If post exists but hasn't been extracted yet, trigger extraction now.
            if ( $post_id && empty( $item['extracted_text'] ) ) {
                $status = get_post_meta( $post_id, '_jc_extraction_status', true );
                if ( empty( $status ) || $status === 'pending' ) {
                    $manager = new Brain_Content_Manager();
                    $type    = $item['type'] ?? '';
                    $value   = $item['value'] ?? '';

                    if ( $type === 'url' && ! empty( $value ) ) {
                        $manager->extract_and_store_url( $post_id, $value );
                    } elseif ( $type === 'file' && ! empty( $value ) ) {
                        $manager->extract_and_store_file( $post_id, $value );
                    } elseif ( $type === 'text' && ! empty( $value ) ) {
                        $manager->extract_and_store( $post_id, 'text', $value );
                    }

                    // Re-read after extraction.
                    $extracted = get_post_meta( $post_id, '_jc_extracted_text', true );
                    if ( ! empty( $extracted ) ) {
                        $item['extracted_text'] = $extracted;
                    }

                    // Also re-read tone profile (generated during extraction).
                    $tone_profile = get_post_meta( $post_id, '_jc_tone_style_profile', true );
                    if ( ! empty( $tone_profile ) ) {
                        $item['tone_style_profile'] = $tone_profile;
                    }
                }
            }
        }
        unset( $item );

        return $items;
    }

    /**
     * Enrich existing asset items with extracted text and tone profiles.
     *
     * Unlike brain content (which lives in jc_brain_content posts), existing
     * assets are either WordPress attachment posts (files) or bare URLs with
     * no server-side post. This method handles both cases:
     *
     * - File assets: Uses the attachment ID to get the real file path, then
     *   runs extraction via Brain_Content_Manager::extract_from_attachment().
     * - URL assets: Fetches and extracts content on-the-fly, caching the
     *   result in a transient to avoid re-fetching on every request.
     *
     * @since 2.1.0
     * @param array $items Existing assets array from the frontend.
     * @return array Enriched items with extracted_text and tone_style_profile.
     */
    private function enrich_existing_assets( $items ) {
        if ( empty( $items ) || ! is_array( $items ) ) {
            return $items;
        }

        // Lazy-load the manager once.
        if ( ! class_exists( 'Brain_Content_Manager' ) ) {
            $path = plugin_dir_path( dirname( __FILE__ ) ) . 'models/class-brain-content-manager.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }

        $manager = class_exists( 'Brain_Content_Manager' ) ? new Brain_Content_Manager() : null;

        foreach ( $items as &$item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            // Skip if already enriched.
            if ( ! empty( $item['extracted_text'] ) ) {
                continue;
            }

            $type = $item['type'] ?? '';

            // === FILE ASSETS (WordPress attachments) ===
            if ( $type === 'file' && ! empty( $item['fileId'] ) ) {
                $attachment_id = absint( $item['fileId'] );

                // Check if we already extracted for this attachment.
                $extracted = get_post_meta( $attachment_id, '_jc_extracted_text', true );

                if ( empty( $extracted ) ) {
                    // Run extraction — this does the full two-pass pipeline.
                    if ( $manager ) {
                        $manager->extract_from_attachment( $attachment_id );
                        $extracted = get_post_meta( $attachment_id, '_jc_extracted_text', true );
                    }
                }

                if ( ! empty( $extracted ) ) {
                    $item['extracted_text'] = $extracted;
                }

                // Also pull tone profile.
                $tone = get_post_meta( $attachment_id, '_jc_tone_style_profile', true );
                if ( ! empty( $tone ) ) {
                    $item['tone_style_profile'] = $tone;
                }

                continue;
            }

            // === URL ASSETS ===
            if ( $type === 'url' && ! empty( $item['value'] ) ) {
                $url = $item['value'];

                // Check transient cache first (1 hour TTL).
                $cache_key = 'jc_asset_url_' . md5( $url );
                $cached    = get_transient( $cache_key );

                if ( false !== $cached && is_array( $cached ) ) {
                    $item['extracted_text'] = $cached['extracted_text'] ?? '';
                    if ( ! empty( $cached['tone_style_profile'] ) ) {
                        $item['tone_style_profile'] = $cached['tone_style_profile'];
                    }
                    continue;
                }

                // Fetch and extract on-the-fly.
                if ( $manager ) {
                    $raw_text = $manager->extract_url_text( $url );

                    if ( ! is_wp_error( $raw_text ) && ! empty( $raw_text ) ) {
                        // Clean and truncate for prompt use.
                        $raw_text = preg_replace( '/\s+/', ' ', trim( $raw_text ) );
                        $extracted = substr( $raw_text, 0, Brain_Content_Manager::MAX_EXTRACTED_LENGTH );

                        $item['extracted_text'] = $extracted;

                        // Cache for future requests.
                        set_transient( $cache_key, array(
                            'extracted_text' => $extracted,
                        ), HOUR_IN_SECONDS );
                    }
                }

                continue;
            }
        }
        unset( $item );

        return $items;
    }

    /**
     * Find a brain content post by its type and value.
     *
     * @since 2.1.0
     * @param array $item Brain content item with type and value.
     * @return int|null Post ID or null.
     */
    private function find_brain_content_post( $item ) {
        $type  = $item['type'] ?? '';
        $value = $item['value'] ?? '';

        if ( empty( $type ) || empty( $value ) ) {
            return null;
        }

        $meta_key = '';
        switch ( $type ) {
            case 'url':
                $meta_key = '_jc_url';
                break;
            case 'file':
                $meta_key = '_jc_file_path';
                break;
            case 'text':
                // For text, the content is in post_content. Check extraction status.
                // We can search by post type and match content.
                $posts = get_posts( array(
                    'post_type'   => 'jc_brain_content',
                    'post_status' => 'publish',
                    'numberposts' => 1,
                    'meta_query'  => array(
                        array(
                            'key'   => '_jc_content_type',
                            'value' => 'text',
                        ),
                        array(
                            'key'     => '_jc_extraction_status',
                            'value'   => 'completed',
                        ),
                    ),
                    's' => substr( $value, 0, 100 ), // Search by content prefix.
                ) );
                return ! empty( $posts ) ? $posts[0]->ID : null;
        }

        if ( ! empty( $meta_key ) ) {
            $posts = get_posts( array(
                'post_type'   => 'jc_brain_content',
                'post_status' => 'publish',
                'numberposts' => 1,
                'meta_query'  => array(
                    array(
                        'key'   => $meta_key,
                        'value' => $value,
                    ),
                ),
            ) );
            return ! empty( $posts ) ? $posts[0]->ID : null;
        }

        return null;
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
            'primary_problem_statement' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
                'description'       => __( 'Selected Primary Problem statement from Step 5.', 'directreach' ),
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
            ),
            'previous_titles' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Previously generated titles to avoid on regeneration.', 'directreach' ),
            ),
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
            ),
            'exclude_titles' => array(
                'required'    => false,
                'type'        => 'array',
                'default'     => array(),
                'description' => __( 'Already-selected solution titles to avoid duplicating.', 'directreach' ),
            ),
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
