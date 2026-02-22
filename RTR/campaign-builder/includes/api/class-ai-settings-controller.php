<?php
/**
 * AI Settings REST Controller
 * 
 * Manages AI configuration settings for email generation
 * Phase 2.5: Day 2
 *
 * @package DirectReach
 * @subpackage CampaignBuilder\API
 */

namespace DirectReach\CampaignBuilder\API;

if (!defined('ABSPATH')) {
    exit;
}

class AI_Settings_Controller extends \WP_REST_Controller {

    /**
     * Namespace for API routes
     */
    protected $namespace = 'directreach/v2';

    /**
     * Base path for settings routes
     */
    protected $rest_base = 'settings';

    /**
     * WordPress database instance
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // GET/PUT /settings/ai-config
        register_rest_route($this->namespace, '/' . $this->rest_base . '/ai-config', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_ai_config'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_ai_config'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => $this->get_update_config_args(),
            ],
        ]);

        // POST /settings/test-ai
        register_rest_route($this->namespace, '/' . $this->rest_base . '/test-ai', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_ai_connection'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'api_key' => [
                    'type' => 'string',
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Optional API key to test (uses stored if empty)',
                ],
            ],
        ]);

        // GET /settings/gemini-models
        register_rest_route($this->namespace, '/' . $this->rest_base . '/gemini-models', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_gemini_models'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/test-prompt', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_prompt'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'prompt_template' => [
                    'type' => 'object',
                    'required' => true,
                    'description' => '7-component prompt structure',
                ],
            ],
        ]);        
    }

    /**
     * Check if user has admin permissions
     */
    public function check_admin_permissions($request) {
        return current_user_can('manage_options');
    }

    /**
     * Get AI configuration
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_ai_config($request) {
        try {
            $config = [
                'enabled' => $this->is_ai_enabled(),
                'api_key_set' => $this->has_api_key(),
                'model' => get_option('dr_gemini_model', 'gemini-2.5-flash'),
                'temperature' => (float) get_option('dr_gemini_temperature', 0.7),
                'max_tokens' => (int) get_option('dr_gemini_max_tokens', 1000),
                'rate_limit_enabled' => $this->is_rate_limit_enabled(),
                'rate_limit' => (int) get_option('dr_rate_limit_per_hour', 100),
            ];

            return new \WP_REST_Response([
                'success' => true,
                'data' => $config,
            ], 200);

        } catch (\Exception $e) {
            error_log('AI Settings - Get Config Error: ' . $e->getMessage());
            return new \WP_Error(
                'get_config_failed',
                'Failed to retrieve AI configuration',
                ['status' => 500]
            );
        }
    }

    /**
     * Update AI configuration
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_ai_config($request) {
        try {
            $params = $request->get_json_params();
            $updated = [];

            // Update enabled status
            if (isset($params['enabled'])) {
                $enabled = (bool) $params['enabled'];
                update_option('dr_ai_enabled', $enabled);
                $updated[] = 'enabled';
            }

            // Update API key (encrypt before storing)
            if (isset($params['api_key']) && !empty($params['api_key'])) {
                $api_key = sanitize_text_field($params['api_key']);
                $this->store_api_key($api_key);
                $updated[] = 'api_key';
            }

            // Update model
            if (isset($params['model'])) {
                $model = sanitize_text_field($params['model']);
                if ($this->validate_model_name($model)) {
                    update_option('dr_gemini_model', $model);
                    $updated[] = 'model';
                }
            }

            // Update temperature
            if (isset($params['temperature'])) {
                $temperature = (float) $params['temperature'];
                if ($temperature >= 0.0 && $temperature <= 1.0) {
                    update_option('dr_gemini_temperature', $temperature);
                    $updated[] = 'temperature';
                }
            }

            // Update max tokens
            if (isset($params['max_tokens'])) {
                $max_tokens = (int) $params['max_tokens'];
                if ($max_tokens >= 100 && $max_tokens <= 8000) {
                    update_option('dr_gemini_max_tokens', $max_tokens);
                    $updated[] = 'max_tokens';
                }
            }

            // Update rate limit enabled
            if (isset($params['rate_limit_enabled'])) {
                $rate_limit_enabled = (bool) $params['rate_limit_enabled'];
                update_option('dr_rate_limit_enabled', $rate_limit_enabled);
                $updated[] = 'rate_limit_enabled';
            }

            // Update rate limit
            if (isset($params['rate_limit'])) {
                $rate_limit = (int) $params['rate_limit'];
                if ($rate_limit >= 10 && $rate_limit <= 1000) {
                    update_option('dr_rate_limit_per_hour', $rate_limit);
                    $updated[] = 'rate_limit';
                }
            }

            // Log action
            $this->log_action(
                'ai_settings_update',
                sprintf(
                    'Updated AI settings: %s',
                    implode(', ', $updated)
                )
            );

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'AI settings updated successfully',
                'updated' => $updated,
            ], 200);

        } catch (\Exception $e) {
            error_log('AI Settings - Update Config Error: ' . $e->getMessage());
            
            $this->log_action(
                'ai_settings_update',
                'Failed to update AI settings: ' . $e->getMessage()
            );

            return new \WP_Error(
                'update_config_failed',
                'Failed to update AI configuration',
                ['status' => 500]
            );
        }
    }

    /**
     * Test Gemini API connection
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function test_ai_connection($request, $model = null) {
        try {
            $params = $request->get_json_params();
            
            $api_key = isset($params['api_key']) && !empty($params['api_key']) 
                ? sanitize_text_field($params['api_key'])
                : $this->get_api_key();

            if (empty($api_key)) {
                $this->log_action(
                    'ai_api_test',
                    'Failed: No API key configured'
                );

                return new \WP_Error(
                    'no_api_key',
                    'No API key configured',
                    ['status' => 400]
                );
            }

            // Use provided model or get from storage
            $model = isset($params['model']) && !empty($params['model'])
                ? sanitize_text_field($params['model'])
                : get_option('dr_gemini_model', 'gemini-2.5-flash');

                // Test connection with simple API call
            $test_result = $this->test_gemini_connection($api_key, $model);

            if ($test_result['success']) {
                $this->log_action(
                    'ai_api_test',
                    sprintf(
                        'Connection successful - Model: %s',
                        $test_result['model']
                    )
                );

                return new \WP_REST_Response([
                    'success' => true,
                    'message' => 'Connection successful',
                    'model' => $test_result['model'],
                ], 200);
            } else {
                $this->log_action(
                    'ai_api_test',
                    'Connection failed: ' . $test_result['error']
                );

                return new \WP_Error(
                    'connection_failed',
                    $test_result['error'],
                    ['status' => 400]
                );
            }

        } catch (\Exception $e) {
            error_log('AI Settings - Test Connection Error: ' . $e->getMessage());
            
            $this->log_action(
                'ai_api_test',
                'Connection test error: ' . $e->getMessage()
            );

            return new \WP_Error(
                'test_failed',
                'Failed to test API connection',
                ['status' => 500]
            );
        }
    }

    /**
     * Get available Gemini models (no caching)
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_gemini_models($request) {
        try {
            $api_key = $this->get_api_key();
            
            if (empty($api_key)) {
                // Return fallback models if no API key
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'models' => $this->get_fallback_models(),
                        'source' => 'fallback',
                    ],
                ], 200);
            }

            // Fetch models from Gemini API
            $models = $this->fetch_available_models($api_key);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'models' => $models['models'],
                    'source' => $models['source'],
                ],
            ], 200);

        } catch (\Exception $e) {
            error_log('AI Settings - Get Models Error: ' . $e->getMessage());
            
            // Return fallback models on error
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'models' => $this->get_fallback_models(),
                    'source' => 'fallback',
                ],
            ], 200);
        }
    }

    /**
     * Fetch available models from Gemini API
     * 
     * @param string $api_key
     * @return array
     */
    private function fetch_available_models($api_key) {
        // Use v1beta to get all available models
        $endpoint = 'https://generativelanguage.googleapis.com/v1/models?key=' . $api_key;
        
        $response = wp_remote_get($endpoint, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('Gemini API - Model Fetch Error: ' . $response->get_error_message());
            return [
                'models' => $this->get_fallback_models(),
                'source' => 'fallback',
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200 || !isset($data['models']) || empty($data['models'])) {
            error_log('Gemini API - Invalid response or empty models list');
            return [
                'models' => $this->get_fallback_models(),
                'source' => 'fallback',
            ];
        }

        // Parse and filter Gemini models
        $parsed_models = $this->parse_gemini_models($data['models']);

        return [
            'models' => $parsed_models,
            'source' => 'api',
        ];
    }

    /**
     * Parse Gemini API models response
     * 
     * @param array $models
     * @return array
     */
    private function parse_gemini_models($models) {
        $parsed = [];

        foreach ($models as $model) {
            // Only include generateContent-capable models
            if (!isset($model['supportedGenerationMethods']) || 
                !in_array('generateContent', $model['supportedGenerationMethods'])) {
                continue;
            }

            $name = isset($model['name']) ? str_replace('models/', '', $model['name']) : '';
            $display_name = isset($model['displayName']) ? $model['displayName'] : $name;

            if (!empty($name)) {
                $parsed[] = [
                    'name' => $name,
                    'display_name' => $display_name,
                ];
            }
        }

        // If no models found, return fallback
        if (empty($parsed)) {
            return $this->get_fallback_models();
        }

        return $parsed;
    }

    /**
     * Get fallback model list
     * 
     * @return array
     */
    private function get_fallback_models() {
        return [
            [
                'name' => 'gemini-2.5-flash',
                'display_name' => 'Gemini 2.5 Flash (Recommended)',
            ],
            [
                'name' => 'gemini-2.5-pro',
                'display_name' => 'Gemini 2.5 Pro',
            ],
            [
                'name' => 'gemini-2.5-flash-lite',
                'display_name' => 'Gemini 2.5 Flash-Lite (Fastest)',
            ],
        ];
    }

    /**
     * Test Gemini API connection
     * 
     * @param string $api_key
     * @return array
     */
    private function test_gemini_connection($api_key, $model = null) {
        // Use provided model or get from storage
        error_log('TEST GEMINI CONNECTION: Model passed in param: ' . ($model ? $model : 'none'));
        $model = isset($params['model']) && !empty($params['model'])
            ? sanitize_text_field($params['model'])
            : get_option('dr_gemini_model', 'gemini-2.5-flash');
        error_log('TEST GEMINI CONNECTION: Model used: ' . $model);
        
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1/models/%s:generateContent?key=%s',
            $model,
            $api_key
        );

        $test_payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Hello, this is a connection test.'],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 50,
            ],
        ];

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($test_payload),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            // Try to extract meaningful error message
            $error_message = 'Unknown error';
            
            if (isset($data['error']['message'])) {
                $error_message = $data['error']['message'];
            } elseif (isset($data['error'])) {
                $error_message = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
            }
            
            return [
                'success' => false,
                'error' => $error_message,
            ];
        }

        // Success - check if we got a valid response
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'model' => $model,
                'response' => $data['candidates'][0]['content']['parts'][0]['text'],
            ];
        }

        return [
            'success' => true,
            'model' => $model,
        ];
    }

    /**
     * Store API key (encrypted)
     * 
     * @param string $api_key
     */
    private function store_api_key($api_key) {
        if (empty($api_key)) {
            return;
        }

        // Encrypt API key using AES-256-CBC
        $encryption_key = substr(hash('sha256', SECURE_AUTH_KEY), 0, 32);
        $iv = substr(hash('sha256', SECURE_AUTH_SALT), 0, 16);

        $encrypted = openssl_encrypt(
            $api_key,
            'AES-256-CBC',
            $encryption_key,
            0,
            $iv
        );

        // Store encrypted key
        update_option('dr_ai_api_key_encrypted', base64_encode($encrypted));
    }

    /**
     * Get API key (decrypted)
     * 
     * @return string
     */
    private function get_api_key() {
        $encrypted = get_option('dr_ai_api_key_encrypted', '');
        
        if (empty($encrypted)) {
            return '';
        }

        try {
            $encryption_key = substr(hash('sha256', SECURE_AUTH_KEY), 0, 32);
            $iv = substr(hash('sha256', SECURE_AUTH_SALT), 0, 16);

            $decrypted = openssl_decrypt(
                base64_decode($encrypted),
                'AES-256-CBC',
                $encryption_key,
                0,
                $iv
            );

            return $decrypted ? $decrypted : '';

        } catch (\Exception $e) {
            error_log('API Key Decryption Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Check if API key is set
     * 
     * @return bool
     */
    private function has_api_key() {
        $encrypted = get_option('dr_ai_api_key_encrypted', '');
        return !empty($encrypted);
    }

    /**
     * Check if AI is enabled
     * 
     * @return bool
     */
    private function is_ai_enabled() {
        return (bool) get_option('dr_ai_enabled', false);
    }

    /**
     * Check if rate limiting is enabled
     * 
     * @return bool
     */
    private function is_rate_limit_enabled() {
        return (bool) get_option('dr_rate_limit_enabled', true);
    }

    /**
     * Validate model name
     * 
     * @param string $model
     * @return bool
     */
    private function validate_model_name($model) {
        // Basic validation - starts with 'gemini'
        return strpos($model, 'gemini') === 0;
    }

    /**
     * Log action to wp_cpd_action_logs
     * 
     * @param string $action_type
     * @param string $description
     */
    private function log_action($action_type, $description) {
        $table_name = $this->wpdb->prefix . 'cpd_action_logs';
        
        $this->wpdb->insert(
            $table_name,
            [
                'user_id' => get_current_user_id(),
                'action_type' => sanitize_text_field($action_type),
                'description' => sanitize_text_field($description),
                'timestamp' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );

        if ($this->wpdb->last_error) {
            error_log('Action Log Error: ' . $this->wpdb->last_error);
        }
    }

    /**
     * Get arguments for update config endpoint
     * 
     * @return array
     */
    private function get_update_config_args() {
        return [
            'enabled' => [
                'type' => 'boolean',
                'required' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'api_key' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'model' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'temperature' => [
                'type' => 'number',
                'required' => false,
                'minimum' => 0.0,
                'maximum' => 1.0,
            ],
            'max_tokens' => [
                'type' => 'integer',
                'required' => false,
                'minimum' => 100,
                'maximum' => 8000,
            ],
            'rate_limit_enabled' => [
                'type' => 'boolean',
                'required' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'rate_limit' => [
                'type' => 'integer',
                'required' => false,
                'minimum' => 10,
                'maximum' => 1000,
            ],
        ];
    }

    /**
     * Test prompt with mock visitor data
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function test_prompt($request) {
        try {
            $params = $request->get_json_params();
            
            if (empty($params['prompt_template'])) {
                return new \WP_Error(
                    'missing_prompt',
                    'Prompt template is required',
                    ['status' => 400]
                );
            }
            
            // Validate prompt structure
            $required_components = [
                'persona', 'style', 'output', 'personalization',
                'constraints', 'examples', 'context'
            ];
            
            foreach ($required_components as $component) {
                if (!isset($params['prompt_template'][$component])) {
                    return new \WP_Error(
                        'invalid_prompt',
                        "Missing required component: {$component}",
                        ['status' => 400]
                    );
                }
            }
            
            // Check if at least one component has content
            $has_content = false;
            foreach ($params['prompt_template'] as $value) {
                if (!empty($value) && trim($value) !== '') {
                    $has_content = true;
                    break;
                }
            }
            
            if (!$has_content) {
                return new \WP_Error(
                    'empty_prompt',
                    'At least one prompt component must have content',
                    ['status' => 400]
                );
            }
            
            // Get mock visitor data
            $mock_visitor = $this->get_mock_visitor_data();
            
            if (is_wp_error($mock_visitor)) {
                return $mock_visitor;
            }
            
            // Create mock prospect with visitor data
            $mock_prospect = $this->create_mock_prospect($mock_visitor);
            
            // Build generation payload using prompt template
            $payload = $this->build_test_payload(
                $params['prompt_template'],
                $mock_prospect,
                $mock_visitor
            );
            
            // Call Gemini API
            $api_key = $this->get_api_key();
            if (empty($api_key)) {
                return new \WP_Error(
                    'no_api_key',
                    'Gemini API key not configured',
                    ['status' => 400]
                );
            }
            
            $generation_result = $this->call_gemini_for_test($api_key, $payload);
            
            if (is_wp_error($generation_result)) {
                $this->log_action(
                    'ai_test_prompt',
                    'Test prompt failed: ' . $generation_result->get_error_message()
                );
                
                return $generation_result;
            }
            
            // Log successful test
            $this->log_action(
                'ai_test_prompt',
                sprintf(
                    'Test prompt executed - Tokens: %d, Cost: $%.4f',
                    $generation_result['usage']['total_tokens'],
                    $generation_result['cost']
                )
            );
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'subject' => $generation_result['subject'],
                    'body_html' => $generation_result['body_html'],
                    'body_text' => $generation_result['body_text'],
                    'usage' => $generation_result['usage'],
                    'mock_visitor' => [
                        'company_name' => $mock_prospect['company_name'],
                        'contact_name' => $mock_prospect['contact_name'],
                        'job_title' => $mock_prospect['job_title'],
                    ],
                ],
            ], 200);
            
        } catch (\Exception $e) {
            error_log('AI Settings - Test Prompt Error: ' . $e->getMessage());
            
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
     * Get random visitor for testing
     * 
     * @return array|WP_Error
     */
    private function get_mock_visitor_data() {
        $visitor = $this->wpdb->get_row(
            "SELECT * FROM {$this->wpdb->prefix}cpd_visitors
            WHERE is_archived = 0
            AND company_name != ''
            ORDER BY RAND()
            LIMIT 1",
            ARRAY_A
        );
        
        if (!$visitor) {
            return new \WP_Error(
                'no_visitors',
                'No visitor data available for testing. Please ensure you have active visitors in the system.',
                ['status' => 404]
            );
        }
        
        return $visitor;
    }

    /**
     * Create mock prospect from visitor data
     * 
     * @param array $visitor
     * @return array
     */
    private function create_mock_prospect($visitor) {
        // Parse recent pages
        $recent_pages = [];
        if (!empty($visitor['recent_page_urls'])) {
            $urls = json_decode($visitor['recent_page_urls'], true);
            if (is_array($urls)) {
                $recent_pages = array_map(function($url) {
                    return [
                        'url' => $url,
                        'intent' => 'research', // Mock intent
                        'timestamp' => date('Y-m-d H:i:s', strtotime('-' . rand(1, 7) . ' days')),
                    ];
                }, array_slice($urls, 0, 5));
            }
        }
        
        return [
            'id' => 0, // Mock ID
            'company_name' => $visitor['company_name'],
            'contact_name' => trim($visitor['first_name'] . ' ' . $visitor['last_name']),
            'contact_email' => $visitor['email'] ?: '',
            'job_title' => $visitor['job_title'] ?: 'Unknown',
            'current_room' => 'problem',
            'lead_score' => 25,
            'days_in_room' => 3,
            'email_sequence_position' => 1,
            'urls_sent' => json_encode([]),
            'engagement_data' => json_encode([
                'recent_pages' => $recent_pages,
                'page_view_count' => intval($visitor['recent_page_count']),
                'last_visit' => $visitor['last_seen_at'],
            ]),
            // Additional visitor context
            'company_size' => $visitor['estimated_employee_count'] ?: null,
            'company_industry' => $visitor['industry'] ?: null,
            'company_revenue' => $visitor['estimated_revenue'] ?: null,
        ];
    }

    /**
     * Build test payload for Gemini
     * 
     * @param array $prompt_template
     * @param array $mock_prospect
     * @param array $mock_visitor
     * @return array
     */
    private function build_test_payload($prompt_template, $mock_prospect, $mock_visitor) {
        // Assemble prompt from components
        $assembled_prompt = $this->assemble_prompt($prompt_template);
        
        // Format visitor info
        $visitor_info = $this->format_visitor_context($mock_prospect);
        
        // Mock content links (for demonstration)
        $mock_urls = [
            [
                'id' => 1,
                'title' => 'Complete Marketing ROI Guide',
                'url' => 'https://example.com/guides/marketing-roi',
                'summary' => 'Comprehensive guide to measuring and improving marketing ROI',
            ],
            [
                'id' => 2,
                'title' => 'Attribution Best Practices',
                'url' => 'https://example.com/blog/attribution-best-practices',
                'summary' => 'Learn how to properly attribute conversions across multiple touchpoints',
            ],
        ];
        
        return [
            'prompt_template' => $assembled_prompt,
            'visitor_info' => $visitor_info,
            'available_urls' => $mock_urls,
        ];
    }

    /**
     * Assemble prompt from 7 components
     * 
     * @param array $components
     * @return string
     */
    private function assemble_prompt($components) {
        $sections = [];
        
        $headers = [
            'persona' => '## PERSONA',
            'style' => '## STYLE RULES',
            'output' => '## OUTPUT SPECIFICATION',
            'personalization' => '## PERSONALIZATION GUIDELINES',
            'constraints' => '## CONSTRAINTS',
            'examples' => '## EXAMPLES',
            'context' => '## CONTEXT INSTRUCTIONS',
        ];
        
        foreach ($components as $key => $content) {
            $content = trim($content);
            if (empty($content)) {
                continue;
            }
            
            $header = isset($headers[$key]) ? $headers[$key] : '## ' . strtoupper($key);
            $sections[] = $header . "\n" . $content;
        }
        
        return implode("\n\n", $sections);
    }

    /**
     * Format visitor context for prompt
     * 
     * @param array $prospect
     * @return array
     */
    private function format_visitor_context($prospect) {
        $context = [
            'company_name' => $prospect['company_name'],
            'contact_name' => $prospect['contact_name'],
            'job_title' => $prospect['job_title'],
            'current_room' => $prospect['current_room'],
            'lead_score' => $prospect['lead_score'],
            'days_in_room' => $prospect['days_in_room'],
            'email_sequence_position' => $prospect['email_sequence_position'],
        ];
        
        // Add optional fields if present
        if (!empty($prospect['company_size'])) {
            $context['company_size'] = $prospect['company_size'];
        }
        if (!empty($prospect['company_industry'])) {
            $context['company_industry'] = $prospect['company_industry'];
        }
        if (!empty($prospect['company_revenue'])) {
            $context['company_revenue'] = $prospect['company_revenue'];
        }
        
        // Parse engagement data
        if (!empty($prospect['engagement_data'])) {
            $engagement = json_decode($prospect['engagement_data'], true);
            if (is_array($engagement) && !empty($engagement['recent_pages'])) {
                $context['recent_pages'] = $engagement['recent_pages'];
                $context['page_view_count'] = $engagement['page_view_count'] ?? 0;
            }
        }
        
        return $context;
    }

    /**
     * Call Gemini API for test generation
     * 
     * @param string $api_key
     * @param array $payload
     * @return array|WP_Error
     */
    private function call_gemini_for_test($api_key, $payload) {
        $model = get_option('dr_gemini_model', 'gemini-1.5-flash-latest');
        
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1/models/%s:generateContent?key=%s',
            $model,
            $api_key
        );
        
        // Build complete prompt
        $prompt_parts = [
            "=== EMAIL GENERATION INSTRUCTIONS ===",
            $payload['prompt_template'],
            "",
            "=== VISITOR INFORMATION ===",
            $this->format_visitor_for_prompt($payload['visitor_info']),
            "",
            "=== AVAILABLE CONTENT LINKS ===",
            $this->format_urls_for_prompt($payload['available_urls']),
            "",
            $this->get_output_format_instructions(),
        ];
        
        $complete_prompt = implode("\n", $prompt_parts);
        
        $request_body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $complete_prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => (float) get_option('dr_gemini_temperature', 0.7),
                'maxOutputTokens' => (int) get_option('dr_gemini_max_tokens', 1000),
            ],
        ];
        
        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($request_body),
        ]);
        
        if (is_wp_error($response)) {
            return new \WP_Error(
                'api_request_failed',
                'Failed to connect to Gemini API: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $data = json_decode($body, true);
            $error_message = isset($data['error']['message']) 
                ? $data['error']['message'] 
                : 'Unknown error';
            
            return new \WP_Error(
                'api_error',
                $error_message,
                ['status' => $status_code]
            );
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'invalid_response',
                'Invalid JSON response from Gemini API'
            );
        }
        
        return $this->parse_test_response($data);
    }

    /**
     * Format visitor info for prompt
     * 
     * @param array $info
     * @return string
     */
    private function format_visitor_for_prompt($info) {
        $lines = [];
        
        if (!empty($info['company_name'])) {
            $lines[] = "Company: {$info['company_name']}";
        }
        if (!empty($info['contact_name'])) {
            $lines[] = "Contact: {$info['contact_name']}";
        }
        if (!empty($info['job_title'])) {
            $lines[] = "Title: {$info['job_title']}";
        }
        
        $lines[] = "Current Stage: {$info['current_room']}";
        $lines[] = "Lead Score: {$info['lead_score']}";
        $lines[] = "Days in Current Stage: {$info['days_in_room']}";
        $lines[] = "Email Sequence Position: {$info['email_sequence_position']}";
        
        if (!empty($info['recent_pages'])) {
            $lines[] = "\nRecent Pages Visited:";
            foreach (array_slice($info['recent_pages'], 0, 5) as $page) {
                $intent = isset($page['intent']) ? " ({$page['intent']})" : '';
                $lines[] = "- {$page['url']}{$intent}";
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * Format URLs for prompt
     * 
     * @param array $urls
     * @return string
     */
    private function format_urls_for_prompt($urls) {
        $lines = [];
        $lines[] = "Select ONE of the following content links to include in the email:";
        $lines[] = "";
        
        foreach ($urls as $index => $url) {
            $lines[] = sprintf("[%d] %s", $index + 1, $url['title']);
            $lines[] = "    URL: {$url['url']}";
            if (!empty($url['summary'])) {
                $lines[] = "    About: {$url['summary']}";
            }
            $lines[] = "";
        }
        
        return implode("\n", $lines);
    }

    /**
     * Get output format instructions
     * 
     * @return string
     */
    private function get_output_format_instructions() {
        return <<<INSTRUCTIONS
    === OUTPUT FORMAT ===

    You must respond with ONLY a valid JSON object. Do not include any text before or after the JSON.
    DO NOT wrap the JSON in markdown code blocks or backticks.

    Required JSON structure:
    {
        "subject": "email subject line here",
        "body_html": "<p>HTML formatted email body here</p>",
        "body_text": "Plain text version of email here",
        "selected_url_index": 1,
        "reasoning": "Brief explanation of why you selected this URL"
    }

    CRITICAL REQUIREMENTS:
    - selected_url_index must be a number matching one of the available content links (1-based)
    - body_html must use proper HTML tags (<p>, <strong>, <em>, etc.)
    - body_text must be plain text only, no HTML
    - subject should be compelling and personalized
    - DO NOT include any text outside the JSON structure
    - DO NOT use markdown code fences (```)
    INSTRUCTIONS;
    }

    /**
     * Parse test response from Gemini
     * 
     * @param array $data
     * @return array|WP_Error
     */
    private function parse_test_response($data) {
        if (empty($data['candidates'][0]['content']['parts'][0]['text'])) {
            return new \WP_Error('empty_response', 'Empty response from Gemini API');
        }
        
        $response_text = $data['candidates'][0]['content']['parts'][0]['text'];
        
        // Remove markdown code fences if present
        $response_text = preg_replace('/^```json\s*/m', '', $response_text);
        $response_text = preg_replace('/^```\s*/m', '', $response_text);
        $response_text = trim($response_text);
        
        $parsed = json_decode($response_text, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Failed to parse Gemini JSON: ' . $response_text);
            return new \WP_Error(
                'invalid_json_response',
                'Could not parse JSON from Gemini response: ' . json_last_error_msg()
            );
        }
        
        // Validate required fields
        $required = ['subject', 'body_html', 'body_text'];
        foreach ($required as $field) {
            if (empty($parsed[$field])) {
                return new \WP_Error(
                    'missing_field',
                    "Missing required field in response: {$field}"
                );
            }
        }
        
        // Get token usage
        $usage = [
            'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
            'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
        ];
        
        // Calculate cost (Gemini 1.5 Flash pricing)
        $prompt_cost = ($usage['prompt_tokens'] / 1000) * 0.00125;
        $completion_cost = ($usage['completion_tokens'] / 1000) * 0.005;
        $total_cost = $prompt_cost + $completion_cost;
        
        return [
            'subject' => sanitize_text_field($parsed['subject']),
            'body_html' => wp_kses_post($parsed['body_html']),
            'body_text' => sanitize_textarea_field($parsed['body_text']),
            'reasoning' => isset($parsed['reasoning']) ? sanitize_text_field($parsed['reasoning']) : '',
            'usage' => $usage,
            'cost' => round($total_cost, 6),
        ];
    }    

}