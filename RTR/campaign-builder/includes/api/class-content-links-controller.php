<?php
/**
 * Content Links REST Controller
 *
 * Handles CRUD operations for campaign content links
 * with room-based organization and ordering.
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage REST_API
 * @since 2.0.0
 */

namespace DirectReach\CampaignBuilder\API;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller for content links operations
 *
 * Endpoints:
 * - GET    /campaigns/{id}/content-links  - List links for campaign
 * - POST   /campaigns/{id}/content-links  - Create link
 * - GET    /content-links/{id}            - Get single link
 * - PUT    /content-links/{id}            - Update link
 * - DELETE /content-links/{id}            - Delete link
 * - PUT    /content-links/reorder         - Update link order
 */
class Content_Links_Controller extends REST_Controller {
    
    /**
     * REST namespace
     *
     * @var string
     */
    protected $namespace = 'directreach/v2';
    
    /**
     * Resource name
     *
     * @var string
     */
    protected $rest_base = 'content-links';
    
    /**
     * Register routes
     */
    public function register_routes() {
        // List links for campaign
        register_rest_route($this->namespace, '/campaigns/(?P<campaign_id>[\d]+)/content-links', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_campaign_links'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'campaign_id' => array(
                        'required' => true,
                        'type' => 'integer'
                    )
                )
            )
        ));
        
        // Create link
        register_rest_route($this->namespace, '/campaigns/(?P<campaign_id>[\d]+)/content-links', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_link'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => $this->get_create_params()
            )
        ));
        
        // Get single link
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_link'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'type' => 'integer'
                    )
                )
            )
        ));
        
        // Update link
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_link'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => $this->get_update_params()
            )
        ));
        
        // Delete link
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_link'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'type' => 'integer'
                    )
                )
            )
        ));
        
        // Reorder links
        register_rest_route($this->namespace, '/' . $this->rest_base . '/reorder', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'reorder_links'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'links' => array(
                        'required' => true,
                        'type' => 'array',
                        'description' => 'Array of {id, link_order} objects'
                    )
                )
            )
        ));
    }
    
    /**
     * Get content links for a campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function get_campaign_links($request) {
        global $wpdb;
        
        $campaign_id = $request->get_param('campaign_id');
        
        // Verify campaign exists and user has access
        $campaign = $this->get_campaign_if_authorized($campaign_id);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        
        $table_name = $wpdb->prefix . 'rtr_room_content_links';
        
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE campaign_id = %d 
             ORDER BY room_type, link_order ASC, created_at ASC",
            $campaign_id
        ));
        
        if ($links === false) {
            return new WP_Error(
                'database_error',
                'Failed to retrieve content links: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        // Group by room
        $grouped = array(
            'problem' => array(),
            'solution' => array(),
            'offer' => array()
        );
        
        foreach ($links as $link) {
            $grouped[$link->room_type][] = $this->prepare_link_response($link);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $grouped,
            'meta' => array(
                'total' => count($links),
                'problem_count' => count($grouped['problem']),
                'solution_count' => count($grouped['solution']),
                'offer_count' => count($grouped['offer'])
            )
        ), 200);
    }
    
    /**
     * Create a new content link
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function create_link($request) {
        global $wpdb;
        
        $campaign_id = $request->get_param('campaign_id');
        
        // Verify campaign exists and user has access
        $campaign = $this->get_campaign_if_authorized($campaign_id);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        
        $room_type = $request->get_param('room_type');
        $link_title = $request->get_param('link_title');
        $link_url = $request->get_param('link_url');
        $url_summary = $request->get_param('url_summary');
        $link_description = $request->get_param('link_description');
        $is_active = $request->get_param('is_active');
        
        // Get next order number for this room
        $table_name = $wpdb->prefix . 'rtr_room_content_links';
        $max_order = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(link_order), -1) FROM {$table_name} 
             WHERE campaign_id = %d AND room_type = %s",
            $campaign_id,
            $room_type
        ));
        
        $link_order = $max_order + 1;
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'campaign_id' => $campaign_id,
                'room_type' => $room_type,
                'link_title' => $link_title,
                'link_url' => $link_url,
                'url_summary' => $url_summary,
                'link_description' => $link_description,
                'link_order' => $link_order,
                'is_active' => $is_active ? 1 : 1, // Default to active
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error(
                'database_error',
                'Failed to create content link: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        // Get the created link
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $wpdb->insert_id
        ));
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $this->prepare_link_response($link),
            'message' => 'Content link created successfully'
        ), 201);
    }
    
    /**
     * Get a single content link
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function get_link($request) {
        global $wpdb;
        
        $link_id = $request->get_param('id');
        
        $table_name = $wpdb->prefix . 'rtr_room_content_links';
        
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $link_id
        ));
        
        if (!$link) {
            return new WP_Error(
                'link_not_found',
                'Content link not found',
                array('status' => 404)
            );
        }
        
        // Verify user has access to this link's campaign
        $campaign = $this->get_campaign_if_authorized($link->campaign_id);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $this->prepare_link_response($link)
        ), 200);
    }
    
    /**
     * Update a content link
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function update_link($request) {
        global $wpdb;
        
        $link_id = $request->get_param('id');
        
        $table_name = $wpdb->prefix . 'rtr_room_content_links';
        
        // Get existing link
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $link_id
        ));
        
        if (!$link) {
            return new WP_Error(
                'link_not_found',
                'Content link not found',
                array('status' => 404)
            );
        }
        
        // Verify user has access
        $campaign = $this->get_campaign_if_authorized($link->campaign_id);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        
        // Build update data
        $update_data = array();
        $update_format = array();
        
        if ($request->has_param('link_title')) {
            $update_data['link_title'] = $request->get_param('link_title');
            $update_format[] = '%s';
        }
        
        if ($request->has_param('link_url')) {
            $update_data['link_url'] = $request->get_param('link_url');
            $update_format[] = '%s';
        }
        
        if ($request->has_param('url_summary')) {
            $update_data['url_summary'] = $request->get_param('url_summary');
            $update_format[] = '%s';
        }
        
        if ($request->has_param('link_description')) {
            $update_data['link_description'] = $request->get_param('link_description');
            $update_format[] = '%s';
        }
        
        if ($request->has_param('is_active')) {
            $update_data['is_active'] = $request->get_param('is_active') ? 1 : 0;
            $update_format[] = '%d';
        }
        
        $update_data['updated_at'] = current_time('mysql');
        $update_format[] = '%s';
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $link_id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error(
                'database_error',
                'Failed to update content link: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        // Get updated link
        $updated_link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $link_id
        ));
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $this->prepare_link_response($updated_link),
            'message' => 'Content link updated successfully'
        ), 200);
    }
    
    /**
     * Delete a content link
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function delete_link($request) {
        global $wpdb;
        
        $link_id = $request->get_param('id');
        
        $table_name = $wpdb->prefix . 'rtr_room_content_links';
        
        // Get link to verify access
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $link_id
        ));
        
        if (!$link) {
            return new WP_Error(
                'link_not_found',
                'Content link not found',
                array('status' => 404)
            );
        }
        
        // Verify user has access
        $campaign = $this->get_campaign_if_authorized($link->campaign_id);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        
        // Delete link
        $result = $wpdb->delete(
            $table_name,
            array('id' => $link_id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error(
                'database_error',
                'Failed to delete content link: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Content link deleted successfully'
        ), 200);
    }
    
    /**
     * Reorder content links
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function reorder_links($request) {
        global $wpdb;
        
        $links = $request->get_param('links');
        
        if (empty($links) || !is_array($links)) {
            return new WP_Error(
                'invalid_data',
                'Links array is required',
                array('status' => 400)
            );
        }
        
        $table_name = $wpdb->prefix . 'rtr_room_content_links';
        
        // Update each link's order
        foreach ($links as $link_data) {
            if (!isset($link_data['id']) || !isset($link_data['link_order'])) {
                continue;
            }
            
            $wpdb->update(
                $table_name,
                array(
                    'link_order' => (int) $link_data['link_order'],
                    'updated_at' => current_time('mysql')
                ),
                array('id' => (int) $link_data['id']),
                array('%d', '%s'),
                array('%d')
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Links reordered successfully'
        ), 200);
    }
    
    /**
     * Get create link parameters
     *
     * @return array Parameter definitions
     */
    private function get_create_params() {
        return array(
            'campaign_id' => array(
                'required' => true,
                'type' => 'integer',
                'description' => 'Campaign ID'
            ),
            'room_type' => array(
                'required' => true,
                'type' => 'string',
                'enum' => array('problem', 'solution', 'offer'),
                'description' => 'Room type'
            ),
            'link_title' => array(
                'required' => true,
                'type' => 'string',
                'description' => 'Link title',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return !empty($param) && strlen($param) <= 255;
                }
            ),
            'link_url' => array(
                'required' => true,
                'type' => 'string',
                'description' => 'Link URL',
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => array($this, 'validate_url')
            ),
            'url_summary' => array(
                'required' => true,
                'type' => 'string',
                'description' => 'URL summary for AI',
                'sanitize_callback' => 'sanitize_textarea_field'
            ),
            'link_description' => array(
                'required' => false,
                'type' => 'string',
                'description' => 'Link description (optional)',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => ''
            ),
            'is_active' => array(
                'required' => false,
                'type' => 'boolean',
                'description' => 'Active status',
                'default' => true
            )
        );
    }
    
    /**
     * Get update link parameters
     *
     * @return array Parameter definitions
     */
    private function get_update_params() {
        $params = $this->get_create_params();
        
        // Make all fields optional for updates
        foreach ($params as $key => $param) {
            if ($key !== 'id') {
                $params[$key]['required'] = false;
            }
        }
        
        // Add ID parameter
        $params['id'] = array(
            'required' => true,
            'type' => 'integer',
            'description' => 'Link ID'
        );
        
        return $params;
    }
    
    /**
     * Validate URL format
     *
     * @param string $value URL value
     * @param WP_REST_Request $request Request object
     * @param string $param Parameter name
     * @return bool|WP_Error True if valid, error otherwise
     */
    public function validate_url($value, $request, $param) {
        if (empty($value)) {
            return new WP_Error(
                'empty_url',
                'URL cannot be empty',
                array('status' => 400)
            );
        }
        
        // Basic URL validation
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return new WP_Error(
                'invalid_url',
                'Invalid URL format',
                array('status' => 400)
            );
        }
        
        // Must be HTTP or HTTPS
        if (!preg_match('/^https?:\/\//i', $value)) {
            return new WP_Error(
                'invalid_protocol',
                'URL must use HTTP or HTTPS protocol',
                array('status' => 400)
            );
        }
        
        if (strlen($value) > 500) {
            return new WP_Error(
                'url_too_long',
                'URL must be 500 characters or less',
                array('status' => 400)
            );
        }
        
        return true;
    }
    
    /**
     * Get campaign if user is authorized
     *
     * @param int $campaign_id Campaign ID
     * @return object|WP_Error Campaign object or error
     */
    private function get_campaign_if_authorized($campaign_id) {
        global $wpdb;
        
        $campaigns_table = $wpdb->prefix . 'dr_campaign_settings';
        $clients_table = $wpdb->prefix . 'cpd_clients';
        
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, cl.subscription_tier, cl.rtr_enabled 
             FROM {$campaigns_table} c
             INNER JOIN {$clients_table} cl ON c.client_id = cl.id
             WHERE c.id = %d",
            $campaign_id
        ));
        
        if (!$campaign) {
            return new WP_Error(
                'campaign_not_found',
                'Campaign not found',
                array('status' => 404)
            );
        }
        
        // Check if client is premium
        if ($campaign->subscription_tier !== 'premium' || $campaign->rtr_enabled != 1) {
            return new WP_Error(
                'client_not_premium',
                'This campaign\'s client does not have premium access',
                array('status' => 403)
            );
        }
        
        return $campaign;
    }
    
    /**
     * Prepare link for response
     *
     * @param object $link Link database row
     * @return array Prepared link data
     */
    private function prepare_link_response($link) {
        return array(
            'id' => (int) $link->id,
            'campaign_id' => (int) $link->campaign_id,
            'room_type' => $link->room_type,
            'link_title' => $link->link_title,
            'link_url' => $link->link_url,
            'url_summary' => $link->url_summary ?? '',
            'link_description' => $link->link_description ?? '',
            'link_order' => (int) $link->link_order,
            'is_active' => (bool) $link->is_active,
            'created_at' => $link->created_at,
            'updated_at' => $link->updated_at
        );
    }
    
    /**
     * Check if user has permission to manage content links
     *
     * @return bool
     */
    public function check_permissions(WP_REST_Request $request = null): bool|WP_Error {
        error_log('[RTR_AUTH] check_permission called');
        error_log('[RTR_AUTH] request is null: ' . ($request === null ? 'YES' : 'NO'));
        
        // Allow cookie-based auth (WordPress admin)
        if (current_user_can('edit_posts')) {
            error_log('[RTR_AUTH] Cookie auth passed');
            return true;
        }
        error_log('[RTR_AUTH] Cookie auth failed, checking X-API-Key');

        // Allow X-API-Key auth (external systems like CIS)
        if ($request) {
            $api_key = $request->get_header('X-API-Key');
            error_log('[RTR_AUTH] X-API-Key header value: ' . ($api_key ? substr($api_key, 0, 8) . '...' : 'EMPTY/NULL'));
            
            if (!empty($api_key)) {
                $stored_key = get_option('cpd_api_key');
                error_log('[RTR_AUTH] Stored key exists: ' . (!empty($stored_key) ? 'YES (starts with ' . substr($stored_key, 0, 8) . ')' : 'NO'));
                error_log('[RTR_AUTH] Keys match: ' . ($api_key === $stored_key ? 'YES' : 'NO'));
                
                if (!empty($stored_key) && $api_key === $stored_key) {
                    error_log('[RTR_AUTH] X-API-Key auth passed');
                    return true;
                }
            }
            
            // Also try checking all headers for debugging
            error_log('[RTR_AUTH] All request headers: ' . print_r($request->get_headers(), true));
        }

        error_log('[RTR_AUTH] All auth methods failed - returning 403');
        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to access this endpoint.',
            ['status' => 403]
        );
    }
}