<?php
/**
 * Base REST Controller
 *
 * @package DirectReach_Campaign_Builder
 * @since 2.0.0
 */

namespace DirectReach\CampaignBuilder\API;

if (!defined('ABSPATH')) {
    exit;
}

abstract class REST_Controller {
    
    /**
     * REST API namespace
     */
    protected $namespace = 'directreach/v2';
    
    /**
     * Register routes
     */
    abstract public function register_routes();
    
    /**
     * Permission callback for all directreach/v2 endpoints.
     *
     * Accepts either:
     *   1. WordPress logged-in user with manage_options (admin UI)
     *   2. X-API-Key header matching directreach_journeyos_api_key (JourneyOS)
     */
    public function check_permissions() {
        if (current_user_can('manage_options')) {
            return true;
        }

        $stored_key = get_option('directreach_journeyos_api_key', '');
        if (empty($stored_key)) {
            return false;
        }

        $provided_key = isset($_SERVER['HTTP_X_API_KEY']) ? sanitize_text_field($_SERVER['HTTP_X_API_KEY']) : '';

        return hash_equals($stored_key, $provided_key);
    }
}
