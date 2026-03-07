<?php
/**
 * Campaigns REST Controller
 *
 * Handles CRUD operations for campaigns with UTM configuration
 * and settings inheritance from client or global defaults.
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
 * REST controller for campaign operations
 *
 * Endpoints:
 * - GET    /campaigns?client_id={id}  - List campaigns for client
 * - POST   /campaigns                 - Create campaign
 * - GET    /campaigns/{id}            - Get single campaign
 * - PUT    /campaigns/{id}            - Update campaign
 * - DELETE /campaigns/{id}            - Delete campaign
 */
class Campaigns_Controller extends REST_Controller {
    
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
    protected $rest_base = 'campaigns';
    
    /**
     * Register routes
     */
    public function register_routes() {
        // List campaigns for a client
        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_campaigns'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'client_id' => array(
                        'required' => true,
                        'type' => 'integer',
                        'description' => 'Client ID to get campaigns for',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    )
                )
            )
        ));
        
        // Create campaign
        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_campaign'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => $this->get_create_params()
            )
        ));
        
        // Get single campaign
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_campaign'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'type' => 'integer'
                    )
                )
            )
        ));
        
        // Update campaign
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_campaign'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => $this->get_update_params()
            )
        ));
        
        // Delete campaign
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_campaign'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'type' => 'integer'
                    )
                )
            )
        ));

        // Check if UTM campaign exists
        register_rest_route($this->namespace, '/' . $this->rest_base . '/check-utm', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'check_utm_exists'),
                'permission_callback' => array($this, 'check_permissions'),
                'args' => array(
                    'utm_campaign' => array(
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'exclude_id' => array(
                        'required' => false,
                        'type' => 'integer',
                        'default' => 0
                    )
                )
            )
        ));        
    }
    
    /**
     * Get campaigns for a client
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function get_campaigns($request) {
        global $wpdb;
        
        $client_id = $request->get_param('client_id');
        
        // Verify client exists and user has access
        $client = $this->get_client_if_authorized($client_id);
        if (is_wp_error($client)) {
            return $client;
        }
        
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        
        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE client_id = %d ORDER BY created_at DESC",
            $client_id
        ));
        
        if ($campaigns === false) {
            return new WP_Error(
                'database_error',
                'Failed to retrieve campaigns: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        // Enrich each campaign with settings inheritance info
        $campaigns_enriched = array_map(function($campaign) use ($client) {
            return $this->enrich_campaign_data($campaign, $client);
        }, $campaigns);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $campaigns_enriched,
            'count' => count($campaigns_enriched)
        ), 200);
    }
    
    /**
     * Create a new campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function create_campaign($request) {
        global $wpdb;
        
        $client_id = $request->get_param('client_id');
        $campaign_name = $request->get_param('campaign_name');
        $utm_campaign = strtolower($request->get_param('utm_campaign'));
        $campaign_description = $request->get_param('campaign_description');
        
        // Get dates or use defaults
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        
        // Default start date to today if not provided
        if (empty($start_date)) {
            $start_date = current_time('Y-m-d');
        }
        
        // Default end date to 10 years from start date if not provided
        if (empty($end_date)) {
            $start_datetime = new DateTime($start_date);
            $start_datetime->modify('+10 years');
            $end_date = $start_datetime->format('Y-m-d');
        }
        
        // Verify client exists and user has access
        $client = $this->get_client_if_authorized($client_id);
        if (is_wp_error($client)) {
            return $client;
        }
        
        // Check if utm_campaign already exists for this client
        if ($this->utm_campaign_exists($utm_campaign, $client_id)) {
            return new WP_Error(
                'duplicate_utm',
                'This UTM campaign value already exists for this client. Please use a unique UTM campaign.',
                array('status' => 400)
            );
        }
        
        // Generate unique campaign_id
        $campaign_id = $this->generate_campaign_id($client_id, $utm_campaign);
        
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'campaign_id' => $campaign_id,
                'client_id' => $client_id,
                'campaign_name' => $campaign_name,
                'utm_campaign' => $utm_campaign,
                'campaign_description' => $campaign_description,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error(
                'database_error',
                'Failed to create campaign: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        // Get the created campaign
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $wpdb->insert_id
        ));
        
        // Enrich with settings info
        $campaign_enriched = $this->enrich_campaign_data($campaign, $client);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $campaign_enriched,
            'message' => 'Campaign created successfully'
        ), 201);
    }
    
    /**
     * Get a single campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function get_campaign($request) {
        global $wpdb;
        
        $campaign_id = $request->get_param('id');
        
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $campaign_id
        ));
        
        if (!$campaign) {
            return new WP_Error(
                'campaign_not_found',
                'Campaign not found',
                array('status' => 404)
            );
        }
        
        // Verify user has access to this campaign's client
        $client = $this->get_client_if_authorized($campaign->client_id);
        if (is_wp_error($client)) {
            return $client;
        }
        
        // Enrich with settings info
        $campaign_enriched = $this->enrich_campaign_data($campaign, $client);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $campaign_enriched
        ), 200);
    }
    
    /**
     * Update a campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function update_campaign($request) {
        global $wpdb;
        
        $campaign_id = $request->get_param('id');
        
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        
        // Get existing campaign
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $campaign_id
        ));
        
        if (!$campaign) {
            return new WP_Error(
                'campaign_not_found',
                'Campaign not found',
                array('status' => 404)
            );
        }
        
        // Verify user has access
        $client = $this->get_client_if_authorized($campaign->client_id);
        if (is_wp_error($client)) {
            return $client;
        }
        
        // Build update data
        $update_data = array();
        $update_format = array();
        
        if ($request->has_param('campaign_name')) {
            $update_data['campaign_name'] = $request->get_param('campaign_name');
            $update_format[] = '%s';
        }
        
        if ($request->has_param('utm_campaign')) {
            $utm_campaign = strtolower($request->get_param('utm_campaign'));
            // Check uniqueness if changing
            if ($utm_campaign !== $campaign->utm_campaign && 
                $this->utm_campaign_exists($utm_campaign, $campaign->client_id, $campaign_id)) {
                return new WP_Error(
                    'duplicate_utm',
                    'This UTM campaign value already exists for this client',
                    array('status' => 400)
                );
            }
            $update_data['utm_campaign'] = $utm_campaign;
            $update_format[] = '%s';
            
            // Regenerate campaign_id if UTM changed
            $update_data['campaign_id'] = $this->generate_campaign_id($campaign->client_id, $utm_campaign);
            $update_format[] = '%s';
        }
        
        if ($request->has_param('campaign_description')) {
            $update_data['campaign_description'] = $request->get_param('campaign_description');
            $update_format[] = '%s';
        }
        
        // Handle start_date - default to today if null/empty
        if ($request->has_param('start_date')) {
            $start_date = $request->get_param('start_date');
            if (empty($start_date) || $start_date === null || $start_date === 'null') {
                $start_date = date('Y-m-d'); // Default to today
            }
            $update_data['start_date'] = $start_date;
            $update_format[] = '%s';
        } else {
            // If not provided at all, also default to today
            $update_data['start_date'] = date('Y-m-d');
            $update_format[] = '%s';
        }

        // Handle end_date - default to 10 years from today if null/empty
        if ($request->has_param('end_date')) {
            $end_date = $request->get_param('end_date');
            if (empty($end_date) || $end_date === null || $end_date === 'null') {
                $end_date = date('Y-m-d', strtotime('+10 years')); // Default to 10 years from today
            }
            $update_data['end_date'] = $end_date;
            $update_format[] = '%s';
        } else {
            // If not provided at all, also default to 10 years from today
            $update_data['end_date'] = date('Y-m-d', strtotime('+10 years'));
            $update_format[] = '%s';
        }

        
        $update_data['updated_at'] = current_time('mysql');
        $update_format[] = '%s';
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $campaign_id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error(
                'database_error',
                'Failed to update campaign: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        // Get updated campaign
        $updated_campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $campaign_id
        ));
        
        // Enrich with settings info
        $campaign_enriched = $this->enrich_campaign_data($updated_campaign, $client);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $campaign_enriched,
            'message' => 'Campaign updated successfully'
        ), 200);
    }
    
    /**
     * Delete a campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function delete_campaign($request) {
        global $wpdb;
        
        $campaign_id = $request->get_param('id');
        
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        
        // Get campaign to verify access
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $campaign_id
        ));
        
        if (!$campaign) {
            return new WP_Error(
                'campaign_not_found',
                'Campaign not found',
                array('status' => 404)
            );
        }
        
        // Verify user has access
        $client = $this->get_client_if_authorized($campaign->client_id);
        if (is_wp_error($client)) {
            return $client;
        }
        
        // TODO: Check if campaign has active prospects before deleting
        // For now, allow deletion
        
        // Delete campaign
        $result = $wpdb->delete(
            $table_name,
            array('id' => $campaign_id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error(
                'database_error',
                'Failed to delete campaign: ' . $wpdb->last_error,
                array('status' => 500)
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Campaign deleted successfully'
        ), 200);
    }
    
    /**
     * Get create campaign parameters
     *
     * @return array Parameter definitions
     */
    private function get_create_params() {
        return array(
            'client_id' => array(
                'required' => true,
                'type' => 'integer',
                'description' => 'Client ID this campaign belongs to',
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                }
            ),
            'campaign_name' => array(
                'required' => true,
                'type' => 'string',
                'description' => 'Campaign name',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return !empty($param) && strlen($param) <= 255;
                }
            ),
            'utm_campaign' => array(
                'required' => true,
                'type' => 'string',
                'description' => 'UTM campaign parameter (unique per client)',
                'sanitize_callback' => array($this, 'sanitize_utm'),
                'validate_callback' => array($this, 'validate_utm_campaign')
            ),
            'campaign_description' => array(
                'required' => false,
                'type' => 'string',
                'description' => 'Campaign description',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default' => ''
            ),
            'start_date' => array(
                'required' => false,
                'type' => 'string',
                'description' => 'Campaign start date (YYYY-MM-DD). Defaults to today if not provided.',
                'validate_callback' => array($this, 'validate_date'),
                'default' => null
            ),
            'end_date' => array(
                'required' => false,
                'type' => 'string',
                'description' => 'Campaign end date (YYYY-MM-DD). Defaults to 10 years from start date if not provided.',
                'validate_callback' => array($this, 'validate_date'),
                'default' => null
            )
        );
    }
    
    /**
     * Get update campaign parameters
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
            'description' => 'Campaign ID'
        );
        
        return $params;
    }
    
    /**
     * Sanitize UTM parameter
     *
     * @param string $value UTM value
     * @return string Sanitized value
     */
    public function sanitize_utm($value) {
        // Convert to lowercase, keep only alphanumeric, hyphens, underscores
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_-]/', '', $value);
        return $value;
    }
    
    /**
     * Validate UTM campaign format
     *
     * @param string $value UTM value
     * @param WP_REST_Request $request Request object
     * @param string $param Parameter name
     * @return bool|WP_Error True if valid, error otherwise
     */
    public function validate_utm_campaign($value, $request, $param) {
        if (empty($value)) {
            return new WP_Error(
                'empty_utm',
                'UTM campaign cannot be empty',
                array('status' => 400)
            );
        }
        
        // UTM parameters should be lowercase, alphanumeric, hyphens and underscores only
        if (!preg_match('/^[a-z0-9_-]+$/', $value)) {
            return new WP_Error(
                'invalid_utm_format',
                'UTM campaign must be lowercase alphanumeric with hyphens or underscores only (e.g., "summer-sale-2025")',
                array('status' => 400)
            );
        }
        
        if (strlen($value) > 255) {
            return new WP_Error(
                'utm_too_long',
                'UTM campaign must be 255 characters or less',
                array('status' => 400)
            );
        }
        
        if (strlen($value) < 3) {
            return new WP_Error(
                'utm_too_short',
                'UTM campaign must be at least 3 characters',
                array('status' => 400)
            );
        }
        
        return true;
    }
    
    /**
     * Validate date format
     *
     * @param string $value Date value
     * @param WP_REST_Request $request Request object
     * @param string $param Parameter name
     * @return bool|WP_Error True if valid, error otherwise
     */
    public function validate_date($value, $request, $param) {
        // Empty or null is valid (optional field)
        if (empty($value) || $value === null) {
            return true;
        }
        
        // Allow null explicitly
        if ($value === 'null' || $value === null) {
            return true;
        }
        
        // Validate YYYY-MM-DD format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return new WP_Error(
                'invalid_date_format',
                'Date must be in YYYY-MM-DD format',
                array('status' => 400)
            );
        }
        
        // Validate it's a real date
        $parts = explode('-', $value);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return new WP_Error(
                'invalid_date',
                'Invalid date provided',
                array('status' => 400)
            );
        }
        
        return true;
    }
    
    /**
     * Check if UTM campaign exists for client
     *
     * @param string $utm_campaign UTM campaign value
     * @param int $client_id Client ID
     * @param int|null $exclude_id Campaign ID to exclude from check
     * @return bool True if exists
     */
    private function utm_campaign_exists($utm_campaign, $client_id, $exclude_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE utm_campaign = %s AND client_id = %d",
            $utm_campaign,
            $client_id
        );
        
        if ($exclude_id) {
            $sql .= $wpdb->prepare(" AND id != %d", $exclude_id);
        }
        
        return $wpdb->get_var($sql) > 0;
    }
    
    /**
     * Generate unique campaign ID
     *
     * @param int $client_id Client ID
     * @param string $utm_campaign UTM campaign
     * @return string Campaign ID
     */
    private function generate_campaign_id($client_id, $utm_campaign) {
        // Format: client-{id}-{utm-campaign}
        // Example: client-316-summer-sale-2025
        return "client-{$client_id}-{$utm_campaign}";
    }
    
    /**
     * Get client if user is authorized
     *
     * @param int $client_id Client ID
     * @return object|WP_Error Client object or error
     */
    private function get_client_if_authorized($client_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cpd_clients';
        
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $client_id
        ));
        
        if (!$client) {
            return new WP_Error(
                'client_not_found',
                'Client not found',
                array('status' => 404)
            );
        }
        
        // Check if client is premium
        if ($client->subscription_tier !== 'premium' || $client->rtr_enabled != 1) {
            return new WP_Error(
                'client_not_premium',
                'This client does not have premium access',
                array('status' => 403)
            );
        }
        
        return $client;
    }
    
    /**
     * Enrich campaign data with settings inheritance info
     *
     * Per Decision 7: Campaigns inherit from client overrides or global defaults
     * NO campaign-level storage of thresholds/scoring
     *
     * @param object $campaign Campaign database row
     * @param object $client Client database row
     * @return array Enriched campaign data
     */
    private function enrich_campaign_data($campaign, $client) {
        // Determine which settings this campaign will use
        $has_client_threshold_override = !empty($client->room_thresholds_override);
        $has_client_scoring_override = !empty($client->scoring_rules_override);
        
        // Get room thresholds (client override → global default)
        $room_thresholds = $has_client_threshold_override ?
            json_decode($client->room_thresholds_override, true) :
            json_decode(get_option('dr_default_room_thresholds', '{}'), true);
        
        // Get scoring rules (client override → global default)
        $scoring_rules = $has_client_scoring_override ?
            json_decode($client->scoring_rules_override, true) :
            json_decode(get_option('dr_default_scoring_rules', '{}'), true);
        
        // Determine source description for UI
        if ($has_client_threshold_override || $has_client_scoring_override) {
            $settings_source = 'client';
            $settings_source_name = $client->client_name;
        } else {
            $settings_source = 'global';
            $settings_source_name = 'Global Defaults';
        }
        
        return array(
            'id' => (int) $campaign->id,
            'campaign_id' => $campaign->campaign_id,
            'client_id' => (int) $campaign->client_id,
            'campaign_name' => $campaign->campaign_name,
            'utm_campaign' => $campaign->utm_campaign,
            'campaign_description' => $campaign->campaign_description ?? '',
            'start_date' => $campaign->start_date,
            'end_date' => $campaign->end_date,
            'created_at' => $campaign->created_at,
            'updated_at' => $campaign->updated_at,
            'settings' => array(
                'source' => $settings_source,
                'source_name' => $settings_source_name,
                'room_thresholds' => $room_thresholds,
                'scoring_rules' => $scoring_rules,
                'has_client_overrides' => $has_client_threshold_override || $has_client_scoring_override,
                'threshold_source' => $has_client_threshold_override ? 'client' : 'global',
                'scoring_source' => $has_client_scoring_override ? 'client' : 'global'
            )
        );
    }

    /**
     * Check if UTM campaign exists
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function check_utm_exists($request) {
        $utm_campaign = $request->get_param('utm_campaign');
        $exclude_id = $request->get_param('exclude_id');
        
        // Get client_id from the campaign being edited
        if ($exclude_id > 0) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'dr_campaign_settings';
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT client_id FROM {$table_name} WHERE id = %d",
                $exclude_id
            ));
            
            if (!$campaign) {
                return new WP_REST_Response(array(
                    'exists' => false
                ), 200);
            }
            
            $client_id = $campaign->client_id;
            $exists = $this->utm_campaign_exists($utm_campaign, $client_id, $exclude_id);
        } else {
            // For new campaigns, we can't check without a client_id
            // Return false (doesn't exist) since we can't validate
            return new WP_REST_Response(array(
                'exists' => false
            ), 200);
        }
        
        return new WP_REST_Response(array(
            'exists' => $exists
        ), 200);
    }    
  
}
