<?php
/**
 * Email Generation REST Controller
 *
 * Handles REST API endpoints for AI-powered email generation and tracking.
 *
 * @package DirectReach
 * @subpackage RTR/API
 * @since 2.5.0
 */

namespace DirectReach\CampaignBuilder\API;

use WP_REST_Server;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Email_Generation_Controller extends WP_REST_Controller {

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
    protected $rest_base = 'emails';

    /**
     * AI Email Generator instance
     *
     * @var \CPD_AI_Email_Generator
     */
    private $generator;

    /**
     * Email Tracking Manager instance
     *
     * @var \CPD_Email_Tracking_Manager
     */
    private $tracking;

    /**
     * Rate Limiter instance
     *
     * @var \CPD_AI_Rate_Limiter
     */
    private $rate_limiter;

    /**
     * Constructor
     */
    public function __construct() {
        $this->generator = new \CPD_AI_Email_Generator();
        $this->tracking = new \CPD_Email_Tracking_Manager();
        $this->rate_limiter = new \CPD_AI_Rate_Limiter();
    }

    /**
     * Register routes
     */
    public function register_routes() {
        // Generate email
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/generate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'generate_email' ),
            'permission_callback' => array( $this, 'generate_permissions_check' ),
            'args' => array(
                'prospect_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param > 0;
                    },
                ),
                'room_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array( 'problem', 'solution', 'offer' ),
                ),
                'email_number' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param > 0;
                    },
                ),
                'force_regenerate' => array(         
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ),
            ),
        ));

        // Store externally-generated email (CIS pipeline)
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/store-external', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'store_external_email' ),
            'permission_callback' => array( $this, 'generate_permissions_check' ),
            'args' => array(
                'prospect_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'description' => 'The actual prospect ID (rtr_prospects.id), NOT visitor_id',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param > 0;
                    },
                ),
                'room_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array( 'problem', 'solution', 'offer' ),
                ),
                'email_number' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param >= 1 && $param <= 5;
                    },
                ),
                'subject' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'body_html' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'body_text' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                ),
                'url_included' => array(
                    'required' => false,
                    'type' => 'string',
                    'format' => 'uri',
                    'default' => null,
                ),
                'ai_prompt_tokens' => array(
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ),
                'ai_completion_tokens' => array(
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ),
            ),
        ));


        // Track copy (mark as sent)
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/track-copy', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'track_copy' ),
            'permission_callback' => array( $this, 'track_permissions_check' ),
            'args' => array(
                'email_tracking_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
                'prospect_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
                'url_included' => array(
                    'required' => false,
                    'type' => 'string',
                    'format' => 'uri',
                    'default' => '',
                ),
            ),
        ));

        // Track open (tracking pixel endpoint - future)
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/track-open/(?P<token>[a-zA-Z0-9]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'track_open' ),
            'permission_callback' => '__return_true', // Public endpoint
        ));


        register_rest_route($this->namespace, '/emails/test-prompt', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_prompt'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'prompt_template' => [
                    'type' => 'object',
                    'required' => true,
                    'description' => '7-component prompt structure',
                ],
                'campaign_id' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Campaign ID for content links',
                ],
                'room_type' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'problem',
                    'enum' => ['problem', 'solution', 'offer'],
                ],
            ],
        ]);

        // Get email tracking details by ID
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/tracking/(?P<tracking_id>[\d]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_tracking_details' ),
            'permission_callback' => array( $this, 'get_tracking_permissions_check' ),
            'args' => array(
                'tracking_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param > 0;
                    },
                ),
            ),
        ));
        
        // Get email tracking by prospect and email number
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/tracking/prospect/(?P<prospect_id>[\d]+)/email/(?P<email_number>[\d]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_tracking_by_prospect' ),
            'permission_callback' => array( $this, 'get_tracking_permissions_check' ),
            'args' => array(
                'prospect_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
                'email_number' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        // Get email states for prospect
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/states/(?P<prospect_id>[\d]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_email_states' ),
            'permission_callback' => array( $this, 'get_tracking_permissions_check' ),
            'args' => array(
                'prospect_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && $param > 0;
                    },
                ),
            ),
        ));        

    }

    /**
     * Generate email endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function generate_email( $request ) {
        // Extract and validate parameters
        $prospect_id = absint( $request->get_param( 'prospect_id' ) );
        $room_type = sanitize_text_field( $request->get_param( 'room_type' ) );
        $email_number = absint( $request->get_param( 'email_number' ) );
        
        // Validate parameters
        if ( ! $prospect_id ) {
            return new WP_Error(
                'invalid_prospect_id',
                __( 'Invalid prospect ID provided.', 'directreach' ),
                array( 'status' => 400 )
            );
        }
        
        if ( ! in_array( $room_type, array( 'problem', 'solution', 'offer' ), true ) ) {
            return new WP_Error(
                'invalid_room_type',
                __( 'Invalid room type. Must be: problem, solution, or offer.', 'directreach' ),
                array( 'status' => 400 )
            );
        }
        
        if ( $email_number < 1 || $email_number > 5 ) {
            return new WP_Error(
                'invalid_email_number',
                __( 'Invalid email number. Must be between 1 and 5.', 'directreach' ),
                array( 'status' => 400 )
            );
        }
        
        global $wpdb;
        $prospects_table = $wpdb->prefix . 'rtr_prospects';
        
        // Get prospect with campaign info
        $prospect = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.*, c.client_id, c.id as campaign_id
            FROM {$prospects_table} p
            INNER JOIN {$wpdb->prefix}dr_campaign_settings c ON p.campaign_id = c.id
            WHERE p.visitor_id = %d AND p.archived_at IS NULL",
            $prospect_id
        ) );
        
        if ( ! $prospect ) {
            return new WP_Error(
                'prospect_not_found',
                __( 'Prospect not found or has been archived.', 'directreach' ),
                array( 'status' => 404 )
            );
        }
        
        $actual_prospect_id = (int) $prospect->id;
        error_log( sprintf( '[DirectReach] ID: visitor=%d -> prospect=%d', $prospect_id, $actual_prospect_id ) );

        // Parse email states JSON
        $email_states = json_decode( $prospect->email_states, true );
        if ( ! is_array( $email_states ) ) {
            $email_states = array();
        }
        
        $state_key = "{$room_type}_{$email_number}";
        
        // Store original state for rollback on error
        $original_state = $email_states[ $state_key ] ?? 'pending';
        
        // Get force_regenerate parameter
        $force_regenerate = (bool) $request->get_param( 'force_regenerate' );

        // Check if email is already in "ready" state (skip if force_regenerate is true)
        if ( !$force_regenerate && isset( $email_states[ $state_key ] ) && $email_states[ $state_key ] === 'ready' ) {
            // Try to get existing email from tracking
            $existing_email = $this->get_existing_email( $actual_prospect_id, $room_type, $email_number );
            
            if ( $existing_email ) {
                // Return cached email
                return rest_ensure_response( array(
                    'success' => true,
                    'cached' => true,
                    'data' => $existing_email
                ) );
            }
            
            // Tracking record missing - force regenerate
            $original_state = 'pending';
            $email_states[ $state_key ] = 'pending';
            $wpdb->update(
                $prospects_table,
                array( 'email_states' => wp_json_encode( $email_states ) ),
                array( 'id' => $actual_prospect_id ),
                array( '%s' ),
                array( '%d' )
            );
        }
        
        // Set state to "pending"
        $email_states[ $state_key ] = 'pending';
        $wpdb->update(
            $prospects_table,
            array( 'email_states' => wp_json_encode( $email_states ) ),
            array( 'id' => $actual_prospect_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        try {
            // Call AI generator
            $result = $this->generator->generate_email(
                $actual_prospect_id,
                $prospect->campaign_id,
                $room_type,
                $email_number
            );
            
            // Check if generation failed
            if ( is_wp_error( $result ) ) {
                $error_message = $result->get_error_message();
                
                // Provide friendly message for common issues
                if ( strpos( $error_message, 'No templates available' ) !== false ) {
                    throw new \Exception( '⚠️ No valid email templates found. Please check that templates are properly configured in Campaign Builder with all required components: persona, style_rules, output_spec, personalization_guidelines, constraints, examples, and context_instructions.' );
                }
                
                throw new \Exception( $error_message );
            }

            if ( ! $result['success'] ) {
                throw new \Exception( $result['message'] ?? 'Email generation failed. Please try again or contact support.' );
            }
            
            // Generate tracking token
            $tracking_token = $this->generate_tracking_token();
            
            // Prepare tracking data with all required fields
            $tracking_data = array(
                'prospect_id' => $actual_prospect_id,  
                'visitor_id' => $prospect->visitor_id,
                'room_type' => $room_type,
                'email_number' => $email_number,
                'subject' => $result['subject'],
                'body_html' => $this->inject_tracking_pixel( $result['body_html'], $tracking_token ),
                'body_text' => $result['body_text'] ?? strip_tags( $result['body_html'] ),
                'tracking_token' => $tracking_token,
                'status' => 'generated',
                'generated_by_ai' => 1,
                'url_included' => isset( $result['selected_url'] ) ? 
                    ( is_array( $result['selected_url'] ) ? $result['selected_url']['url'] : $result['selected_url'] ) : 
                    null,
                'template_used' => isset( $result['template_used'] ) ? 
                    ( is_array( $result['template_used'] ) ? $result['template_used']['id'] : $result['template_used'] ) : 
                    null,
                'ai_prompt_tokens' => isset( $result['tokens_used']['prompt_tokens'] ) ? 
                    $result['tokens_used']['prompt_tokens'] : 0,
                'ai_completion_tokens' => isset( $result['tokens_used']['completion_tokens'] ) ? 
                    $result['tokens_used']['completion_tokens'] : 0,
            );
            
            // Create tracking record with enhanced error handling
            try {
                $tracking_id = $this->tracking->create_tracking_record( $tracking_data );
                
                if ( ! $tracking_id ) {
                    // Log detailed error for debugging
                    error_log( sprintf(
                        '[DirectReach] Failed to create tracking record for prospect %d, email %d. Last DB error: %s',
                        $prospect_id,
                        $email_number,
                        $wpdb->last_error ? $wpdb->last_error : 'No DB error reported'
                    ) );
                    
                    throw new \Exception( 'Unable to save email tracking data. Please try again.' );
                }
            } catch ( \Exception $tracking_exception ) {
                error_log( sprintf(
                    '[DirectReach] Exception creating tracking record: %s',
                    $tracking_exception->getMessage()
                ) );
                throw new \Exception( 'Failed to save email: ' . $tracking_exception->getMessage() );
            }
            
            // Set state to "ready"
            $email_states[ $state_key ] = 'ready';
            $wpdb->update(
                $prospects_table,
                array( 'email_states' => wp_json_encode( $email_states ) ),
                array( 'id' => $actual_prospect_id ),
                array( '%s' ),
                array( '%d' )
            );
            
            // Return success response
            return rest_ensure_response( array(
                'success' => true,
                'cached' => false,
                'data' => array(
                    'id' => $tracking_id,                      
                    'email_tracking_id' => $tracking_id,
                    'tracking_token' => $tracking_token,
                    'subject' => $result['subject'],
                    'body_html' => $result['body_html'],       
                    'body_text' => $result['body_text'] ?? strip_tags($result['body_html']),
                    'email_number' => $email_number,           
                    'room_type' => $room_type,                 
                    'url_included' => $result['selected_url'],
                    'template_used' => $result['template_used'] ?? null,
                    'tokens_used' => $result['tokens_used'] ?? null,
                    'generation_time_ms' => $result['generation_time_ms'] ?? null
                )
            ) );
            
        } catch ( \Exception $e ) {
            // ROLLBACK: Reset state to original
            $email_states[ $state_key ] = $original_state;
            $wpdb->update(
                $prospects_table,
                array( 'email_states' => wp_json_encode( $email_states ) ),
                array( 'id' => $actual_prospect_id ),
                array( '%s' ),
                array( '%d' )
            );
            
            // Determine error type for better user messaging
            $error_code = 'generation_failed';
            $error_message = $e->getMessage();
            $status_code = 500;
            
            if ( strpos( $error_message, 'No templates available' ) !== false ||
                 strpos( $error_message, 'Missing required component' ) !== false ||
                 strpos( $error_message, 'No valid email templates found' ) !== false ) {
                $error_code = 'template_error';
                $error_message = 'Template configuration error. Please ensure all templates have the required components: persona, style_rules, output_spec, personalization_guidelines, constraints, examples, and context_instructions.';
            } elseif ( strpos( $error_message, 'Failed to save email' ) !== false ||
                       strpos( $error_message, 'Unable to save email tracking data' ) !== false ) {
                $error_code = 'tracking_error';
                $error_message = 'Unable to save email tracking data. Please try again or contact support.';
            } elseif ( strpos( $error_message, 'rate limit' ) !== false ) {
                $error_code = 'rate_limit';
                $status_code = 429;
            }
            
            // Log detailed error
            error_log( sprintf(
                '[DirectReach] Email generation failed for prospect %d, email %d. Error: %s',
                $prospect_id,
                $email_number,
                $e->getMessage()
            ) );
            
            return new WP_Error(
                $error_code,
                $error_message,
                array( 
                    'status' => $status_code,
                    'details' => defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : null
                )
            );
        }
    }

    /**
     * Get existing email from tracking if available
     * 
     * @param int    $prospect_id   Prospect ID
     * @param string $room_type     Room type
     * @param int    $email_number  Email sequence number
     * @return array|null Email data or null if not found
     */
    private function get_existing_email( $prospect_id, $room_type, $email_number ) {
        global $wpdb;
        $tracking_table = $wpdb->prefix . 'rtr_email_tracking';
        
        $tracking = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, subject, body_html, body_text, url_included, tracking_token, 
                    template_used, ai_prompt_tokens, ai_completion_tokens, created_at
            FROM {$tracking_table} 
            WHERE prospect_id = %d 
            AND room_type = %s 
            AND email_number = %d 
            ORDER BY created_at DESC 
            LIMIT 1",
            $prospect_id,
            $room_type,
            $email_number
        ) );
        
        if ( ! $tracking ) {
            return null;
        }
        
        return array(
            'id' => (int) $tracking->id,                    
            'email_tracking_id' => (int) $tracking->id,
            'tracking_token' => $tracking->tracking_token,
            'subject' => $tracking->subject,
            'body_html' => $this->inject_tracking_pixel( $tracking->body_html, $tracking->tracking_token ),
            'body_text' => $tracking->body_text,            
            'email_number' => $email_number,                
            'room_type' => $room_type,                      
            'url_included' => $tracking->url_included,
            'template_used' => $tracking->template_used ? (int) $tracking->template_used : null,  
            'tokens_used' => array(                         
                'prompt_tokens' => (int) $tracking->ai_prompt_tokens,
                'completion_tokens' => (int) $tracking->ai_completion_tokens
            )
        );
    }

    /**
     * Track copy endpoint
     *
     * Marks email as copied/sent and updates prospect's sent URLs.
     * Uses transactions for data consistency.
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function track_copy( $request ) {
        global $wpdb;
        
        $email_tracking_id = (int) $request->get_param( 'email_tracking_id' );
        $prospect_id = (int) $request->get_param( 'prospect_id' );
        $url_included = $request->get_param( 'url_included' );

        // Start transaction for atomic updates
        $wpdb->query('START TRANSACTION');
        $sender_ip = $this->get_client_ip();
        
        try {
            // Get current prospect state (for potential rollback reference)
            $current_position = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT email_sequence_position FROM {$wpdb->prefix}rtr_prospects WHERE id = %d",
                    $prospect_id
                )
            );
            
            // Update email tracking record to 'copied' status
            $update_result = $this->tracking->update_status(
                $email_tracking_id,
                'copied',
                array( 
                    'copied_at' => current_time( 'mysql' ),
                    'sender_ip' => $sender_ip,
                )
            );

            if ( is_wp_error( $update_result ) ) {
                throw new Exception( $update_result->get_error_message() );
            }

            // Update prospect's sent URLs
            if ( ! empty( $url_included ) ) {
                $url_update = $this->update_prospect_sent_urls( $prospect_id, $url_included );
                
                if ( is_wp_error( $url_update ) ) {
                    throw new Exception( 'Failed to update sent URLs: ' . $url_update->get_error_message() );
                }
            }

            // Update prospect's email data (timestamp + increment sequence position)
            $email_data_update = $this->update_prospect_email_data( $prospect_id );
            
            if ( is_wp_error( $email_data_update ) ) {
                throw new Exception( 'Failed to update prospect data: ' . $email_data_update->get_error_message() );
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            $new_position = $current_position + 1;

            error_log( sprintf(
                '[DirectReach] Email copied: tracking_id=%d, prospect=%d, position=%d→%d, url=%s',
                $email_tracking_id,
                $prospect_id,
                $current_position,
                $new_position,
                $url_included ?? 'none'
            ));

            return rest_ensure_response( array(
                'success' => true,
                'message' => 'Email marked as copied',
                'data' => array(
                    'email_tracking_id' => $email_tracking_id,
                    'prospect_id' => $prospect_id,
                    'status' => 'copied',
                    'copied_at' => current_time( 'mysql' ),
                    'email_sequence_position' => $new_position,
                ),
            ));
            
        } catch ( Exception $e ) {
            // Rollback on any error
            $wpdb->query('ROLLBACK');
            
            error_log( '[DirectReach] track_copy failed: ' . $e->getMessage() );
            
            return new WP_Error(
                'copy_tracking_failed',
                $e->getMessage(),
                array( 'status' => 500 )
            );
        }
    }  

    /**
     * Track open endpoint (tracking pixel)
     *
     * Updates email status when tracking pixel is loaded.
     * Returns 1x1 transparent GIF.
     *
     * @param WP_REST_Request $request Request object
     * @return void (outputs GIF and exits)
     */
    public function track_open( $request ) {
        $token = sanitize_text_field( $request->get_param( 'token' ) );

        if ( empty( $token ) ) {
            error_log( '[DirectReach] Track open called with empty token' );
            return $this->return_tracking_pixel();
        }

        // Get current request IP
        $current_ip = $this->get_client_ip();
        
        // Get tracking record to check sender IP
        global $wpdb;
        $tracking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, sender_ip, opened_at FROM {$wpdb->prefix}rtr_email_tracking WHERE tracking_token = %s LIMIT 1",
                $token
            )
        );
        
        if ( ! $tracking ) {
            error_log( sprintf( '[DirectReach] Tracking token not found: %s', $token ) );
            return $this->return_tracking_pixel();
        }
        
        // If sender_ip is NULL, email hasn't been copied/sent yet - ignore all opens
        if ( empty( $tracking->sender_ip ) ) {
            error_log( sprintf( 
                '[DirectReach] Ignoring open - email not yet sent (no sender_ip) for token %s', 
                $token 
            ) );
            return $this->return_tracking_pixel();
        }
        
        // If sender_ip matches current IP, this is the sender previewing after copy - ignore
        if ( $tracking->sender_ip === $current_ip ) {
            error_log( sprintf( 
                '[DirectReach] Ignoring open from sender IP %s for token %s', 
                $current_ip, 
                $token 
            ) );
            return $this->return_tracking_pixel();
        }
        
        // Different IP - legitimate recipient open
        $result = $this->tracking->mark_as_opened( $token, $current_ip );
        
        if ( ! $result ) {
            error_log( sprintf(
                '[DirectReach] Failed to mark email as opened for token: %s',
                $token
            ) );
        } else {
            error_log( sprintf(
                '[DirectReach] Email opened by recipient IP %s (sender was %s) for token %s',
                $current_ip,
                $tracking->sender_ip ?? 'unknown',
                $token
            ) );
        }

        return $this->return_tracking_pixel();
    }

    /**
     * Store an externally-generated email (from CIS pipeline)
     *
     * Accepts pre-generated email content and stores it in rtr_email_tracking
     * exactly like generate_email() does, minus the PHP AI generation step.
     *
     * Reuses: generate_tracking_token(), inject_tracking_pixel(),
     *         create_tracking_record(), email_states update pattern
     *
     * Key difference from generate_email():
     *   - Accepts prospect_id (rtr_prospects.id), NOT visitor_id
     *   - Does not call CPD_AI_Email_Generator
     *   - Sets generated_by_ai = 1 with source marker
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function store_external_email( $request ) {
        global $wpdb;

        // Extract parameters — prospect_id here is the ACTUAL prospect ID
        $prospect_id  = absint( $request->get_param( 'prospect_id' ) );
        $room_type    = sanitize_text_field( $request->get_param( 'room_type' ) );
        $email_number = absint( $request->get_param( 'email_number' ) );
        $subject      = $request->get_param( 'subject' );
        $body_html    = $request->get_param( 'body_html' );
        $body_text    = $request->get_param( 'body_text' );
        $url_included = $request->get_param( 'url_included' );
        $prompt_tokens     = absint( $request->get_param( 'ai_prompt_tokens' ) );
        $completion_tokens = absint( $request->get_param( 'ai_completion_tokens' ) );

        $prospects_table = $wpdb->prefix . 'rtr_prospects';

        // Validate prospect exists (lookup by actual prospect ID, not visitor_id)
        $prospect = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, visitor_id, campaign_id, email_states
             FROM {$prospects_table}
             WHERE id = %d AND archived_at IS NULL",
            $prospect_id
        ) );

        if ( ! $prospect ) {
            return new WP_Error(
                'prospect_not_found',
                __( 'Prospect not found or has been archived.', 'directreach' ),
                array( 'status' => 404 )
            );
        }

        // Parse email states
        $email_states = json_decode( $prospect->email_states, true );
        if ( ! is_array( $email_states ) ) {
            $email_states = array();
        }

        $state_key = "{$room_type}_{$email_number}";

        // Store original state for rollback
        $original_state = $email_states[ $state_key ] ?? 'pending';

        // Check if already ready (don't overwrite unless forced)
        // CIS should handle force logic on its side; here we always allow overwrite
        // since CIS has already done the generation work

        // Set state to pending during storage
        $email_states[ $state_key ] = 'pending';
        $wpdb->update(
            $prospects_table,
            array( 'email_states' => wp_json_encode( $email_states ) ),
            array( 'id' => $prospect_id ),
            array( '%s' ),
            array( '%d' )
        );

        try {
            // Generate tracking token (reuses existing method)
            $tracking_token = $this->generate_tracking_token();

            // Fall back body_text to stripped HTML if not provided
            if ( empty( $body_text ) ) {
                $body_text = strip_tags( $body_html );
            }

            // Prepare tracking data — same schema as generate_email()
            $tracking_data = array(
                'prospect_id'          => $prospect_id,
                'visitor_id'           => (int) $prospect->visitor_id,
                'room_type'            => $room_type,
                'email_number'         => $email_number,
                'subject'              => $subject,
                'body_html'            => $this->inject_tracking_pixel( $body_html, $tracking_token ),
                'body_text'            => $body_text,
                'tracking_token'       => $tracking_token,
                'status'               => 'generated',
                'generated_by_ai'      => 1,
                'url_included'         => $url_included,
                'template_used'        => null,  // CIS doesn't use WP templates
                'ai_prompt_tokens'     => $prompt_tokens,
                'ai_completion_tokens' => $completion_tokens,
            );

            // Create tracking record (reuses existing tracking manager)
            $tracking_id = $this->tracking->create_tracking_record( $tracking_data );

            if ( ! $tracking_id ) {
                error_log( sprintf(
                    '[DirectReach CIS] Failed to create tracking record for prospect %d, email %s_%d. DB error: %s',
                    $prospect_id,
                    $room_type,
                    $email_number,
                    $wpdb->last_error ?: 'none'
                ) );
                throw new \Exception( 'Failed to create tracking record.' );
            }

            // Set state to ready (same pattern as generate_email)
            $email_states[ $state_key ] = 'ready';
            $wpdb->update(
                $prospects_table,
                array( 'email_states' => wp_json_encode( $email_states ) ),
                array( 'id' => $prospect_id ),
                array( '%s' ),
                array( '%d' )
            );

            error_log( sprintf(
                '[DirectReach CIS] Stored external email: prospect=%d, %s_%d, tracking_id=%d',
                $prospect_id,
                $room_type,
                $email_number,
                $tracking_id
            ) );

            // Return success — same shape as generate_email() for consistency
            return rest_ensure_response( array(
                'success' => true,
                'cached'  => false,
                'source'  => 'cis',
                'data'    => array(
                    'id'                 => $tracking_id,
                    'email_tracking_id'  => $tracking_id,
                    'tracking_token'     => $tracking_token,
                    'subject'            => $subject,
                    'body_html'          => $body_html,
                    'body_text'          => $body_text,
                    'email_number'       => $email_number,
                    'room_type'          => $room_type,
                    'url_included'       => $url_included,
                    'tokens_used'        => array(
                        'prompt_tokens'     => $prompt_tokens,
                        'completion_tokens' => $completion_tokens,
                    ),
                ),
            ) );

        } catch ( \Exception $e ) {
            // Rollback state
            $email_states[ $state_key ] = $original_state;
            $wpdb->update(
                $prospects_table,
                array( 'email_states' => wp_json_encode( $email_states ) ),
                array( 'id' => $prospect_id ),
                array( '%s' ),
                array( '%d' )
            );

            error_log( sprintf(
                '[DirectReach CIS] store_external_email failed: prospect=%d, %s_%d, error=%s',
                $prospect_id,
                $room_type,
                $email_number,
                $e->getMessage()
            ) );

            return new WP_Error(
                'store_failed',
                'Failed to store externally-generated email: ' . $e->getMessage(),
                array( 'status' => 500 )
            );
        }
    }


    /**
     * Get client IP address
     *
     * @return string IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy/Load balancer
            'HTTP_X_REAL_IP',            // Nginx proxy
            'REMOTE_ADDR',               // Direct connection
        );
        
        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = $_SERVER[ $key ];
                // Handle comma-separated list (X-Forwarded-For can have multiple IPs)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                // Validate IP
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        
        return '';
    }    

    /**
     * Return a 1x1 transparent tracking pixel
     *
     * @return WP_REST_Response
     */
    private function return_tracking_pixel() {
        // 1x1 transparent GIF (43 bytes)
        $pixel_data = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
        
        status_header( 200 );
        header( 'Content-Type: image/gif' );
        header( 'Content-Length: ' . strlen( $pixel_data ) );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        
        echo $pixel_data;
        exit;
    }

    /**
     * Build tracking pixel URL
     */
    private function build_tracking_pixel_url( $tracking_token ) {
        return rest_url( $this->namespace . '/' . $this->rest_base . '/track-open/' . $tracking_token );
    }

    /**
     * Build tracking pixel HTML
     */
    private function build_tracking_pixel_html( $tracking_token ) {
        $pixel_url = $this->build_tracking_pixel_url( $tracking_token );
        return sprintf(
            '<img src="%s" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;" />',
            esc_url( $pixel_url )
        );
    }

    /**
     * Inject tracking pixel into HTML body
     */
    private function inject_tracking_pixel( $body_html, $tracking_token ) {
        if ( empty( $tracking_token ) ) {
            return $body_html;
        }
        $pixel_html = $this->build_tracking_pixel_html( $tracking_token );
        
        if ( stripos( $body_html, '</body>' ) !== false ) {
            return preg_replace( '/<\/body>/i', $pixel_html . '</body>', $body_html, 1 );
        }
        return $body_html . $pixel_html;
    }


    /**
     * Get prospect data
     *
     * @param int $prospect_id Prospect ID
     * @return array|WP_Error Prospect data or error
     */
    private function get_prospect( $prospect_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'rtr_prospects';
        $prospect = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $prospect_id ),
            ARRAY_A
        );

        if ( ! $prospect ) {
            return new WP_Error(
                'prospect_not_found',
                'Prospect not found',
                array( 'status' => 404 )
            );
        }

        return $prospect;
    }

    /**
     * Update prospect's sent URLs
     *
     * @param int    $prospect_id Prospect ID
     * @param string $url URL to add
     * @return bool|WP_Error Success or error
     */
    private function update_prospect_sent_urls( $prospect_id, $url ) {
        global $wpdb;

        $table = $wpdb->prefix . 'rtr_prospects';

        // Get current sent URLs
        $current_urls_json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT urls_sent FROM {$table} WHERE id = %d",
                $prospect_id
            )
        );

        // Parse existing URLs
        $sent_urls = array();
        if ( ! empty( $current_urls_json ) ) {
            $sent_urls = json_decode( $current_urls_json, true );
            if ( ! is_array( $sent_urls ) ) {
                $sent_urls = array();
            }
        }

        // Add new URL if not already present
        if ( ! in_array( $url, $sent_urls, true ) ) {
            $sent_urls[] = $url;
        }

        // Update database
        $result = $wpdb->update(
            $table,
            array( 'urls_sent' => wp_json_encode( $sent_urls ) ),
            array( 'id' => $actual_prospect_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            return new WP_Error(
                'database_error',
                'Failed to update sent URLs: ' . $wpdb->last_error
            );
        }

        return true;
    }

    /**
     * Update prospect's email data (timestamp + increment sequence position)
     *
     * @param int $prospect_id Prospect ID
     * @return bool|WP_Error Success or error
     */
    private function update_prospect_email_data( $prospect_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'rtr_prospects';

        // Get current sequence position
        $current_position = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT email_sequence_position FROM {$table} WHERE id = %d",
                $prospect_id
            )
        );

        if ( $current_position === null ) {
            error_log( '[DirectReach] Prospect not found for email data update: ' . $prospect_id );
            return new WP_Error(
                'prospect_not_found',
                'Prospect not found',
                array( 'status' => 404 )
            );
        }

        // Increment sequence position and update timestamp
        $new_position = $current_position + 1;
        
        $result = $wpdb->update(
            $table,
            array( 
                'last_email_sent' => current_time( 'mysql' ),
                'email_sequence_position' => $new_position
            ),
            array( 'id' => $actual_prospect_id ),
            array( '%s', '%d' ),
            array( '%d' )
        );

        if ( false === $result ) {
            error_log( '[DirectReach] Failed to update prospect email data: ' . $wpdb->last_error );
            return new WP_Error(
                'database_error',
                'Failed to update prospect email data: ' . $wpdb->last_error
            );
        }

        // Log successful update
        error_log( sprintf(
            '[DirectReach] Updated prospect %d: position %d → %d',
            $prospect_id,
            $current_position,
            $new_position
        ));

        return true;
    }

    /**
     * Generate tracking token
     *
     * @return string Unique tracking token
     */
    private function generate_tracking_token() {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Permission check for generation
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function generate_permissions_check( $request ) {
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rest_forbidden',
                'You must be logged in to generate emails',
                array( 'status' => 403 )
            );
        }

        // Check basic capability - use a more permissive check for logged-in users
        if ( ! current_user_can( 'read' ) ) {
            return new WP_Error(
                'rest_forbidden',
                'You do not have permission to generate emails',
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Permission check for tracking
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function track_permissions_check( $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You must be logged in to track emails.', 'directreach' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }


    /**
     * Test prompt with mock data
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function test_prompt($request) {
        try {
            $params = $request->get_json_params();
            
            $prompt_template = $params['prompt_template'] ?? null;
            $campaign_id = isset($params['campaign_id']) ? intval($params['campaign_id']) : 0;
            $room_type = $params['room_type'] ?? 'problem';
            
            if (empty($prompt_template)) {
                return new \WP_Error(
                    'missing_prompt',
                    'Prompt template is required',
                    ['status' => 400]
                );
            }
            
            if (empty($campaign_id)) {
                return new \WP_Error(
                    'missing_campaign',
                    'Campaign ID is required',
                    ['status' => 400]
                );
            }
            
            // Verify campaign exists
            if (!$this->campaign_exists($campaign_id)) {
                return new \WP_Error(
                    'invalid_campaign',
                    'Campaign not found',
                    ['status' => 404]
                );
            }
            
            // Check rate limit
            $rate_limit_check = $this->rate_limiter->check_limit();
            if (is_wp_error($rate_limit_check)) {
                $this->log_action(
                    'ai_test_prompt',
                    'Rate limit exceeded for test prompt'
                );
                
                return $rate_limit_check;
            }
            
            // Generate test email
            $generation_start = microtime(true);
            $result = $this->generator->generate_email_for_test(
                $prompt_template,
                $campaign_id,
                $room_type
            );
            $generation_time = (microtime(true) - $generation_start) * 1000;
            
            if (is_wp_error($result)) {
                $this->log_action(
                    'ai_test_prompt',
                    sprintf(
                        'Test prompt failed - Campaign: %d, Room: %s, Error: %s',
                        $campaign_id,
                        $room_type,
                        $result->get_error_message()
                    )
                );
                
                return $result;
            }
            
            // Increment rate limiter
            $this->rate_limiter->increment();
            
            // Log successful test
            $this->log_action(
                'ai_test_prompt',
                sprintf(
                    'Test prompt executed - Campaign: %d, Room: %s, Tokens: %d, Cost: $%.4f',
                    $campaign_id,
                    $room_type,
                    $result['usage']['total_tokens'],
                    $result['usage']['cost']
                )
            );
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'subject' => $result['subject'],
                    'body_html' => $result['body_html'],
                    'body_text' => $result['body_text'],
                    'selected_url' => $result['selected_url'],
                    'mock_prospect' => $result['mock_prospect'],
                    'usage' => $result['usage'],
                ],
                'meta' => [
                    'generation_time_ms' => round($generation_time, 2),
                    'campaign_id' => $campaign_id,
                    'room_type' => $room_type,
                    'test_mode' => true,
                ],
            ], 200);
            
        } catch (\Exception $e) {
            error_log('Email Generation - Test Prompt Error: ' . $e->getMessage());
            
            $this->log_action(
                'ai_test_prompt',
                'Test prompt error: ' . $e->getMessage()
            );
            
            return new \WP_Error(
                'test_prompt_failed',
                'Failed to test prompt: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Check if campaign exists
     * 
     * @param int $campaign_id
     * @return bool
     */
    private function campaign_exists($campaign_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'dr_campaign_settings';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d",
            $campaign_id
        ));
        
        return $exists > 0;
    }

    /**
     * Get email tracking states for a prospect
     * Returns tracking data for all email states (pending, copied, opened, etc.)
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_email_states( $request ) {
        global $wpdb;
        
        // Frontend sends visitor_id as 'prospect_id'
        $visitor_id = (int) $request->get_param( 'prospect_id' );
        
        if ( ! $visitor_id ) {
            return new WP_Error(
                'missing_visitor_id',
                'visitor_id is required',
                array( 'status' => 400 )
            );
        }
        
        // GET THE ACTUAL PROSPECT_ID FROM VISITOR_ID
        $prospects_table = $wpdb->prefix . 'rtr_prospects';
        $prospect = $wpdb->get_row( 
            $wpdb->prepare(
                "SELECT * FROM {$prospects_table} WHERE visitor_id = %d AND archived_at IS NULL LIMIT 1",
                $visitor_id
            ),
            ARRAY_A
        );
        
        if ( ! $prospect ) {
            return new WP_Error(
                'prospect_not_found',
                'No active prospect found for this visitor',
                array( 'status' => 404 )
            );
        }
        
        // Extract actual prospect_id
        $actual_prospect_id = (int) $prospect['id'];
        
        // Get all email tracking records using ACTUAL prospect_id
        $tracking_table = $wpdb->prefix . 'rtr_email_tracking';
        $email_states = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT 
                    id as email_tracking_id,
                    email_number,
                    room_type,
                    subject,
                    status,
                    generated_by_ai,
                    template_used,
                    url_included,
                    copied_at,
                    sent_at,
                    opened_at,
                    clicked_at,
                    ai_prompt_tokens,
                    ai_completion_tokens,
                    created_at
                FROM {$tracking_table}
                WHERE prospect_id = %d
                ORDER BY email_number ASC, created_at DESC",
                $actual_prospect_id
            ), 
            ARRAY_A 
        );
        
        // Parse urls_sent JSON
        $urls_sent = array();
        if ( ! empty( $prospect['urls_sent'] ) ) {
            $urls_sent = json_decode( $prospect['urls_sent'], true );
            if ( ! is_array( $urls_sent ) ) {
                $urls_sent = array();
            }
        }
        
        // Build summary counts
        $summary = array(
            'total_emails' => count( $email_states ),
            'pending' => 0,
            'copied' => 0,
            'sent' => 0,
            'opened' => 0,
            'clicked' => 0,
        );
        
        foreach ( $email_states as $email ) {
            $status = $email['status'] ?? 'pending';
            if ( isset( $summary[ $status ] ) ) {
                $summary[ $status ]++;
            }
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'data' => array(
                'prospect_id' => $actual_prospect_id,
                'email_sequence_position' => (int) $prospect['email_sequence_position'],
                'last_email_sent' => $prospect['last_email_sent'],
                'urls_sent' => $urls_sent,
                'email_states' => $email_states,
                'summary' => $summary,
            ),
        ));
    } 

    /**
     * Check if user has admin permissions
     * 
     * @return bool
     */
    public function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Log action to wp_cpd_action_logs
     * 
     * @param string $action_type
     * @param string $description
     */
    private function log_action($action_type, $description) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cpd_action_logs';
        
        $wpdb->insert(
            $table_name,
            [
                'user_id' => get_current_user_id(),
                'action_type' => sanitize_text_field($action_type),
                'description' => sanitize_text_field($description),
                'timestamp' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );
        
        if ($wpdb->last_error) {
            error_log('Action Log Error: ' . $wpdb->last_error);
        }
    }

    /**
     * Get email tracking details by tracking ID
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_tracking_details( $request ) {
        global $wpdb;
        
        $tracking_id = (int) $request->get_param( 'tracking_id' );
        $table = $wpdb->prefix . 'rtr_email_tracking';
        
        $tracking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $tracking_id
            ),
            ARRAY_A
        );
        
        if ( ! $tracking ) {
            return new WP_Error(
                'not_found',
                'Email tracking record not found',
                array( 'status' => 404 )
            );
        }
        
        // Get prospect details
        $prospect = $this->get_prospect( (int) $tracking['prospect_id'] );
        if ( is_wp_error( $prospect ) ) {
            error_log( '[DirectReach] Failed to load prospect for tracking: ' . $prospect->get_error_message() );
            // Don't fail - just continue without prospect data
            $prospect = null;
        }
        
        // Get template details if used
        $template_info = null;
        if ( ! empty( $tracking['template_used'] ) ) {
            $template_info = $this->get_template_info( (int) $tracking['template_used'] );
        }
        
        // Format response
        $response_data = array(
            'id' => (int) $tracking['id'],
            'prospect_id' => (int) $tracking['prospect_id'],
            'email_number' => (int) $tracking['email_number'],
            'room_type' => $tracking['room_type'],
            'subject' => $tracking['subject'],
            'body_html' => $tracking['body_html'],
            'body_text' => $tracking['body_text'],
            'generated_by_ai' => (bool) $tracking['generated_by_ai'],
            'template_used' => $template_info,
            'ai_prompt_tokens' => (int) $tracking['ai_prompt_tokens'],
            'ai_completion_tokens' => (int) $tracking['ai_completion_tokens'],
            'url_included' => $tracking['url_included'],
            'copied_at' => $tracking['copied_at'],
            'sent_at' => $tracking['sent_at'],
            'opened_at' => $tracking['opened_at'],
            'clicked_at' => $tracking['clicked_at'],
            'status' => $tracking['status'],
            'tracking_token' => $tracking['tracking_token'],
        );
        
        // Add prospect context if available
        if ( $prospect ) {
            $response_data['prospect'] = array(
                'company_name' => $prospect['company_name'],
                'contact_name' => $prospect['contact_name'],
                'current_room' => $prospect['current_room'],
                'lead_score' => (int) $prospect['lead_score'],
            );
        }
        
        // Calculate token cost if AI generated
        if ( $tracking['generated_by_ai'] ) {
            $total_tokens = (int) $tracking['ai_prompt_tokens'] + (int) $tracking['ai_completion_tokens'];
            $cost = $this->calculate_token_cost(
                (int) $tracking['ai_prompt_tokens'],
                (int) $tracking['ai_completion_tokens']
            );
            
            $response_data['usage'] = array(
                'prompt_tokens' => (int) $tracking['ai_prompt_tokens'],
                'completion_tokens' => (int) $tracking['ai_completion_tokens'],
                'total_tokens' => $total_tokens,
                'cost' => $cost,
            );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'data' => $response_data,
        ));
    }

    /**
     * Get email tracking by prospect and email number
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_tracking_by_prospect( $request ) {
        global $wpdb;
        
        $prospect_id = (int) $request->get_param( 'prospect_id' );
        $email_number = (int) $request->get_param( 'email_number' );
        $table = $wpdb->prefix . 'rtr_email_tracking';
        
        // Get most recent tracking record for this prospect/email combination
        $tracking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT et.* FROM {$table} et
                INNER JOIN {$wpdb->prefix}rtr_prospects p ON et.prospect_id = p.id
                WHERE p.visitor_id = %d 
                AND et.email_number = %d 
                ORDER BY et.id DESC 
                LIMIT 1",
                $prospect_id,
                $email_number
            ),
            ARRAY_A
        );
        
        if ( ! $tracking ) {
            return new WP_Error(
                'not_found',
                'No email tracking found for this prospect and email number',
                array( 'status' => 404 )
            );
        }
        
        // Reuse the get_tracking_details logic by creating a mock request
        $mock_request = new WP_REST_Request( 'GET', $this->namespace . '/' . $this->rest_base . '/tracking/' . $tracking['id'] );
        $mock_request->set_param( 'tracking_id', $tracking['id'] );
        
        return $this->get_tracking_details( $mock_request );
    }

    /**
     * Get template info by ID
     *
     * @param int $template_id Template ID
     * @return array|null Template info or null
     */
    private function get_template_info( $template_id ) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rtr_email_templates';
        $template = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, template_name, is_global FROM {$table} WHERE id = %d",
                $template_id
            ),
            ARRAY_A
        );
        
        if ( ! $template ) {
            return null;
        }
        
        return array(
            'id' => (int) $template['id'],
            'name' => $template['template_name'],
            'is_global' => (bool) $template['is_global'],
        );
    }

    /**
     * Calculate token cost
     *
     * Gemini 1.5 Pro pricing (as of Oct 2024):
     * - Input: $0.00125 / 1K tokens
     * - Output: $0.005 / 1K tokens
     *
     * @param int $prompt_tokens Prompt tokens
     * @param int $completion_tokens Completion tokens
     * @return float Cost in USD
     */
    private function calculate_token_cost( $prompt_tokens, $completion_tokens ) {
        $input_cost = ( $prompt_tokens / 1000 ) * 0.00125;
        $output_cost = ( $completion_tokens / 1000 ) * 0.005;
        
        return round( $input_cost + $output_cost, 6 );
    }

    /**
     * Permission check for tracking endpoints
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function get_tracking_permissions_check( $request ) {
        // Allow if user is logged in and has dashboard access
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rest_forbidden',
                'You must be logged in to view email tracking',
                array( 'status' => 403 )
            );
        }
        
        // Check if user has access to RTR dashboard (same permission as viewing dashboard)
        if ( ! current_user_can( 'read' ) ) {
            return new WP_Error(
                'rest_forbidden',
                'You do not have permission to view email tracking',
                array( 'status' => 403 )
            );
        }
        
        return true;
    }
}