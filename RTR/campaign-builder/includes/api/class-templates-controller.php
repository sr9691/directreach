<?php
/**
 * Templates REST Controller (Phase 2.5 - Structured Prompts)
 *
 * Handles REST API endpoints for AI prompt-based email templates.
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

class Templates_Controller extends WP_REST_Controller {

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
    protected $rest_base = 'templates';

    /**
     * Template resolver instance
     *
     * @var \Template_Resolver
     */
    private $resolver;

    /**
     * Constructor
     */
    public function __construct() {
        // Only initialize resolver if class exists (Phase 2.5)
        if ( class_exists( '\Template_Resolver' ) ) {
            $this->resolver = new \Template_Resolver();
        }
    }

    /**
     * Register routes
     */
    public function register_routes() {
        // List templates for a campaign
        register_rest_route( $this->namespace, '/campaigns/(?P<campaign_id>[\d]+)/' . $this->rest_base, array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_campaign_templates_merged' ), // CHANGED
                'permission_callback' => array( $this, 'check_permissions' ),
                'args' => array(
                    'campaign_id' => array(
                        'required' => true,
                        'type' => 'integer',
                    ),
                ),
            ),
            // ... rest of route
        ));

        // Get/update/delete single template
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_item' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array( $this, 'update_item' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args' => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array( $this, 'delete_item' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            ),
        ));

        // Get available templates for campaign/room 
        register_rest_route( $this->namespace, '/campaigns/(?P<campaign_id>[\d]+)/' . $this->rest_base . '/available', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_available_templates' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args' => array(
                'campaign_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
                'room_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array( 'problem', 'solution', 'offer' ),
                ),
            ),
        ));

        // List templates (global or campaign)
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_templates' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args' => array(
                    'is_global' => array(
                        'type' => 'integer',
                        'required' => false,
                        'default' => 0,
                    ),
                    'campaign_id' => array(
                        'type' => 'integer',
                        'required' => false,
                        'default' => 0,
                    ),
                ),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'check_permissions' ),
                'args' => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
            ),
        ));

        // Test prompt rendering 
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/test-prompt', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'test_prompt' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args' => array(
                'prompt_template' => array(
                    'required' => true,
                    'type' => 'object',
                ),
            ),
        ));        

    }

    /**
     * Get templates (supports both global and campaign queries)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_templates( $request ) {
        global $wpdb;

        $is_global = (int) $request->get_param( 'is_global' );
        $campaign_id = (int) $request->get_param( 'campaign_id' );
        $table = $wpdb->prefix . 'rtr_email_templates';

        // Build query based on parameters
        if ( $is_global === 1 || $campaign_id === 0 ) {
            // Fetch global templates
            $query = "SELECT * FROM {$table} WHERE campaign_id = 0 AND is_global = 1 ORDER BY room_type, template_order ASC, id ASC";
        } else {
            // Fetch campaign templates
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE campaign_id = %d AND is_global = 0 ORDER BY room_type, template_order ASC, id ASC",
                $campaign_id
            );
        }

        $results = $wpdb->get_results( $query, ARRAY_A );

        if ( $wpdb->last_error ) {
            return new WP_Error(
                'database_error',
                'Failed to fetch templates: ' . $wpdb->last_error,
                array( 'status' => 500 )
            );
        }

        // Group by room type
        $grouped = array(
            'problem' => array(),
            'solution' => array(),
            'offer' => array(),
        );

        foreach ( $results as $item ) {
            $room = $item['room_type'];
            if ( isset( $grouped[ $room ] ) ) {
                $grouped[ $room ][] = $this->prepare_item_for_response( $item, $request );
            }
        }

        // Flatten for response
        $templates = array_merge(
            $grouped['problem'],
            $grouped['solution'],
            $grouped['offer']
        );

        return rest_ensure_response( array(
            'success' => true,
            'data' => $templates,
            'grouped' => $grouped,
            'total' => count( $templates ),
        ));
    }


    /**
     * Get available templates for generation (Phase 2.5)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_available_templates( $request ) {
        if ( ! $this->resolver ) {
            return new WP_Error(
                'resolver_unavailable',
                'Template resolver not available',
                array( 'status' => 500 )
            );
        }

        $campaign_id = (int) $request->get_param( 'campaign_id' );
        $room_type = $request->get_param( 'room_type' );

        $templates = $this->resolver->get_available_templates( $campaign_id, $room_type );
        $stats = $this->resolver->get_template_stats( $campaign_id, $room_type );

        $formatted = array();
        foreach ( $templates as $template ) {
            $formatted[] = $this->prepare_item_for_response( $template->get_data(), $request );
        }

        return rest_ensure_response( array(
            'success' => true,
            'data' => $formatted,
            'meta' => $stats,
        ));
    }

    /**
     * Get single template
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        global $wpdb;

        $template_id = (int) $request->get_param( 'id' );
        $table = $wpdb->prefix . 'rtr_email_templates';

        $template = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ),
            ARRAY_A
        );

        if ( ! $template ) {
            return new WP_Error(
                'not_found',
                'Template not found',
                array( 'status' => 404 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'data' => $this->prepare_item_for_response( $template, $request ),
        ));
    }

    /**
     * Create template
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_item( $request ) {
        global $wpdb;

        $campaign_id = (int) $request->get_param( 'campaign_id' );
        $is_global = (int) $request->get_param( 'is_global' );
        $room_type = sanitize_text_field( $request->get_param( 'room_type' ) );

        // For global templates, campaign_id should be 0
        if ( $is_global === 1 ) {
            $campaign_id = 0;
        }

        // Check template limit (max 5 per room per type)
        $count = $this->get_template_count( $campaign_id, $room_type, $is_global );
        if ( $count >= 5 ) {
            return new WP_Error(
                'template_limit',
                'Maximum 5 templates per room',
                array( 'status' => 400 )
            );
        }

        // Validate and sanitize prompt template
        $prompt_template = $request->get_param( 'prompt_template' );
        $validated_prompt = $this->validate_and_sanitize_prompt( $prompt_template );
        
        if ( is_wp_error( $validated_prompt ) ) {
            return $validated_prompt;
        }

        // Prepare data for insertion
        $data = array(
            'campaign_id' => $campaign_id,
            'room_type' => $room_type,
            'template_name' => sanitize_text_field( $request->get_param( 'template_name' ) ),
            'prompt_template' => wp_json_encode( $validated_prompt ),
            'template_order' => (int) $request->get_param( 'template_order' ) ?: 0,
            'is_global' => $is_global,
        );

        $table = $wpdb->prefix . 'rtr_email_templates';
        $result = $wpdb->insert( $table, $data );

        if ( false === $result ) {
            return new WP_Error(
                'database_error',
                'Failed to create template: ' . $wpdb->last_error,
                array( 'status' => 500 )
            );
        }

        $template_id = $wpdb->insert_id;
        $template = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ),
            ARRAY_A
        );

        return rest_ensure_response( array(
            'success' => true,
            'data' => $this->prepare_item_for_response( $template, $request ),
            'message' => 'Template created successfully',
        ));
    }

    /**
     * Update template
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        global $wpdb;

        $template_id = (int) $request->get_param( 'id' );
        $table = $wpdb->prefix . 'rtr_email_templates';

        // Check if template exists
        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ),
            ARRAY_A
        );

        if ( ! $existing ) {
            return new WP_Error(
                'not_found',
                'Template not found',
                array( 'status' => 404 )
            );
        }

        // Build update data
        $data = array();

        if ( $request->has_param( 'template_name' ) ) {
            $data['template_name'] = sanitize_text_field( $request->get_param( 'template_name' ) );
        }

        if ( $request->has_param( 'room_type' ) ) {
            $data['room_type'] = sanitize_text_field( $request->get_param( 'room_type' ) );
        }

        if ( $request->has_param( 'prompt_template' ) ) {
            $prompt_template = $request->get_param( 'prompt_template' );
            $validated_prompt = $this->validate_and_sanitize_prompt( $prompt_template );
            
            if ( is_wp_error( $validated_prompt ) ) {
                return $validated_prompt;
            }
            
            $data['prompt_template'] = wp_json_encode( $validated_prompt );
        }

        if ( $request->has_param( 'template_order' ) ) {
            $data['template_order'] = (int) $request->get_param( 'template_order' );
        }

        if ( empty( $data ) ) {
            return new WP_Error(
                'no_data',
                'No data to update',
                array( 'status' => 400 )
            );
        }

        $result = $wpdb->update(
            $table,
            $data,
            array( 'id' => $template_id ),
            null,
            array( '%d' )
        );

        if ( false === $result ) {
            return new WP_Error(
                'database_error',
                'Failed to update template: ' . $wpdb->last_error,
                array( 'status' => 500 )
            );
        }

        $template = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ),
            ARRAY_A
        );

        return rest_ensure_response( array(
            'success' => true,
            'data' => $this->prepare_item_for_response( $template, $request ),
            'message' => 'Template updated successfully',
        ));
    }

    /**
     * Delete template
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item( $request ) {
        global $wpdb;

        $template_id = (int) $request->get_param( 'id' );
        $table = $wpdb->prefix . 'rtr_email_templates';

        $result = $wpdb->delete( $table, array( 'id' => $template_id ), array( '%d' ) );

        if ( false === $result ) {
            return new WP_Error(
                'database_error',
                'Failed to delete template: ' . $wpdb->last_error,
                array( 'status' => 500 )
            );
        }

        if ( 0 === $result ) {
            return new WP_Error(
                'not_found',
                'Template not found',
                array( 'status' => 404 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Template deleted successfully',
        ));
    }

    /**
     * Validate and sanitize prompt template
     *
     * @param mixed $prompt_template Prompt template data
     * @return array|WP_Error Validated array or error
     */
    private function validate_and_sanitize_prompt( $prompt_template ) {
        // Handle string input (JSON)
        if ( is_string( $prompt_template ) ) {
            $decoded = json_decode( $prompt_template, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new WP_Error(
                    'invalid_json',
                    'Invalid JSON in prompt_template: ' . json_last_error_msg(),
                    array( 'status' => 400 )
                );
            }
            $prompt_template = $decoded;
        }

        // Must be array
        if ( ! is_array( $prompt_template ) ) {
            return new WP_Error(
                'invalid_type',
                'prompt_template must be an object/array',
                array( 'status' => 400 )
            );
        }

        // Define expected fields (matching JavaScript field names)
        $fields = array(
            'persona',
            'style',
            'output',
            'personalization',
            'constraints',
            'examples',
            'context',
        );

        $validated = array();
        $has_content = false;

        foreach ( $fields as $field ) {
            $value = isset( $prompt_template[ $field ] ) ? $prompt_template[ $field ] : '';
            
            // Sanitize - allow more HTML for examples/content but still safe
            $sanitized = is_string( $value ) ? wp_kses_post( $value ) : '';
            $validated[ $field ] = $sanitized;
            
            // Check if has any content
            if ( ! empty( trim( $sanitized ) ) ) {
                $has_content = true;
            }
        }

        // At least one field must have content
        if ( ! $has_content ) {
            return new WP_Error(
                'empty_template',
                'At least one prompt section must have content',
                array( 'status' => 400 )
            );
        }

        return $validated;
    }

    /**
     * Get template count for campaign/room
     *
     * @param int $campaign_id Campaign ID
     * @param string $room_type Room type
     * @return int Template count
     */
    private function get_template_count( $campaign_id, $room_type, $is_global = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'rtr_email_templates';
        
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND room_type = %s AND is_global = %d",
                $campaign_id,
                $room_type,
                $is_global
            )
        );
    }

    /**
     * Prepare item for response
     *
     * @param array $item Template data
     * @param WP_REST_Request $request Request object
     * @return array Formatted response
     */
    public function prepare_item_for_response( $item, $request ) {
        // Decode prompt_template from JSON column
        $prompt_template = null;
        if ( ! empty( $item['prompt_template'] ) ) {
            $decoded = json_decode( $item['prompt_template'], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $prompt_template = $decoded;
            }
        }

        return array(
            'id' => (int) $item['id'],
            'campaign_id' => (int) $item['campaign_id'],
            'room_type' => $item['room_type'],
            'template_name' => $item['template_name'],
            'prompt_template' => $prompt_template,
            'template_order' => (int) $item['template_order'],
            'is_global' => (bool) $item['is_global'],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
        );
    }

    /**
     * Get endpoint args for item schema
     *
     * @param string $method HTTP method
     * @return array
     */
    public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {
        $args = array(
            'template_name' => array(
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'Template name',
            ),
            'room_type' => array(
                'type' => 'string',
                'required' => true,
                'enum' => array( 'problem', 'solution', 'offer' ),
                'description' => 'Room type for this template',
            ),
            'prompt_template' => array(
                'type' => 'object',
                'required' => false,
                'description' => 'Structured prompt with 7 components',
                'properties' => array(
                    'persona' => array( 'type' => 'string' ),
                    'style' => array( 'type' => 'string' ),
                    'output' => array( 'type' => 'string' ),
                    'personalization' => array( 'type' => 'string' ),
                    'constraints' => array( 'type' => 'string' ),
                    'examples' => array( 'type' => 'string' ),
                    'context' => array( 'type' => 'string' ),
                ),
            ),
            'template_order' => array(
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'description' => 'Display order (0-4)',
                'minimum' => 0,
                'maximum' => 4,
            ),
        );

        if ( $method === WP_REST_Server::CREATABLE ) {
            $args['campaign_id'] = array(
                'type' => 'integer',
                'required' => true,
                'description' => 'Campaign ID this template belongs to',
            );
        }

        return $args;
    }

    /**
     * Get merged templates for campaign view (Campaign + Global, max 5)
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_campaign_templates_merged( $request ) {
        global $wpdb;

        $campaign_id = (int) $request->get_param( 'campaign_id' );
        $table = $wpdb->prefix . 'rtr_email_templates';

        // Get campaign templates
        $campaign_query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE campaign_id = %d AND is_global = 0 ORDER BY template_order ASC, id ASC",
            $campaign_id
        );
        $campaign_templates = $wpdb->get_results( $campaign_query, ARRAY_A );

        // Get global templates
        $global_query = "SELECT * FROM {$table} WHERE campaign_id = 0 AND is_global = 1 ORDER BY template_order ASC, id ASC";
        $global_templates = $wpdb->get_results( $global_query, ARRAY_A );

        if ( $wpdb->last_error ) {
            return new WP_Error(
                'database_error',
                'Failed to fetch templates: ' . $wpdb->last_error,
                array( 'status' => 500 )
            );
        }

        // Group by room and merge
        $grouped = array(
            'problem' => array(),
            'solution' => array(),
            'offer' => array(),
        );

        foreach ( array( 'problem', 'solution', 'offer' ) as $room ) {
            $merged = $this->merge_templates_by_room(
                $campaign_templates,
                $global_templates,
                $room
            );
            
            foreach ( $merged as $template ) {
                $grouped[ $room ][] = $this->prepare_item_for_response( $template, $request );
            }
        }

        // Flatten for response
        $templates = array_merge(
            $grouped['problem'],
            $grouped['solution'],
            $grouped['offer']
        );

        return rest_ensure_response( array(
            'success' => true,
            'data' => $templates,
            'grouped' => $grouped,
            'total' => count( $templates ),
        ));
    }

    /**
     * Merge campaign and global templates for a specific room
     * 
     * @param array $campaign_templates Campaign templates
     * @param array $global_templates Global templates
     * @param string $room Room type
     * @return array Merged templates (max 5)
     */
    private function merge_templates_by_room( $campaign_templates, $global_templates, $room ) {
        // Filter by room
        $campaign_for_room = array_filter( $campaign_templates, function( $t ) use ( $room ) {
            return $t['room_type'] === $room;
        });

        $global_for_room = array_filter( $global_templates, function( $t ) use ( $room ) {
            return $t['room_type'] === $room;
        });

        // Merge: Campaign templates take precedence over globals with same order
        $merged = array();
        $used_orders = array();

        // Add campaign templates first
        foreach ( $campaign_for_room as $template ) {
            $merged[] = $template;
            $used_orders[] = (int) $template['template_order'];
        }

        // Add global templates that don't conflict with campaign template orders
        foreach ( $global_for_room as $template ) {
            $order = (int) $template['template_order'];
            if ( ! in_array( $order, $used_orders, true ) ) {
                $merged[] = $template;
            }
        }

        // Sort by template_order
        usort( $merged, function( $a, $b ) {
            $order_a = (int) $a['template_order'];
            $order_b = (int) $b['template_order'];
            
            if ( $order_a === $order_b ) {
                // Campaign templates come before global when order is same
                $is_global_a = (int) $a['is_global'];
                $is_global_b = (int) $b['is_global'];
                return $is_global_a - $is_global_b;
            }
            
            return $order_a - $order_b;
        });

        // Limit to 5
        return array_slice( $merged, 0, 5 );
    }


    /**
     * Test prompt with Gemini API
     */
    public function test_prompt( $request ) {
        $prompt_template = $request->get_param( 'prompt_template' );
        
        // Use existing AI Email Generator
        if ( ! class_exists( 'CPD_AI_Email_Generator' ) ) {
            return new WP_Error( 'ai_unavailable', 'AI Email Generator not available' );
        }
        
        $generator = new CPD_AI_Email_Generator();
        
        // Build a simple test prompt
        $test_prompt = $this->build_test_prompt_from_template( $prompt_template );
        
        // Call the existing Gemini API method
        $payload = array(
            'prompt_template' => $test_prompt,
            'visitor_info' => array(
                'company_name' => 'Acme Corp',
                'contact_name' => 'John Smith',
                'first_name' => 'John',
                'job_title' => 'Marketing Director',
                'current_room' => 'problem',
                'lead_score' => 75,
                'days_in_room' => 3,
                'email_sequence_position' => 1,
                'recent_pages' => array()
            ),
            'available_urls' => array(
                array(
                    'title' => 'Marketing ROI Guide',
                    'url' => 'https://example.com/roi-guide',
                    'summary' => 'Complete guide to measuring marketing ROI'
                )
            )
        );
        
        // Use reflection to call the private method
        $reflection = new ReflectionClass( $generator );
        $method = $reflection->getMethod( 'call_gemini_api' );
        $method->setAccessible( true );
        
        $result = $method->invoke( $generator, $payload );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'data' => $result,
        ));
    }

    private function build_test_prompt_from_template( $prompt_template ) {
        $sections = array();
        
        if ( ! empty( $prompt_template['persona'] ) ) {
            $sections[] = "PERSONA:\n" . $prompt_template['persona'];
        }
        if ( ! empty( $prompt_template['style'] ) ) {
            $sections[] = "STYLE:\n" . $prompt_template['style'];
        }
        if ( ! empty( $prompt_template['output'] ) ) {
            $sections[] = "OUTPUT:\n" . $prompt_template['output'];
        }
        if ( ! empty( $prompt_template['personalization'] ) ) {
            $sections[] = "PERSONALIZATION:\n" . $prompt_template['personalization'];
        }
        if ( ! empty( $prompt_template['constraints'] ) ) {
            $sections[] = "CONSTRAINTS:\n" . $prompt_template['constraints'];
        }
        if ( ! empty( $prompt_template['examples'] ) ) {
            $sections[] = "EXAMPLES:\n" . $prompt_template['examples'];
        }
        if ( ! empty( $prompt_template['context'] ) ) {
            $sections[] = "CONTEXT:\n" . $prompt_template['context'];
        }
        
        return implode( "\n\n", $sections );
    }

    /**
     * Check permissions
     *
     * @param WP_REST_Request $request Request object
     * @return bool
     */
    public function check_permissions( $request ) {
        return current_user_can( 'manage_options' );
    }
}