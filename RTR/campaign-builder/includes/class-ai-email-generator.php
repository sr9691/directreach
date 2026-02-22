<?php
/**
 * AI Email Generator
 *
 * Integrates with Google Gemini API to generate personalized emails
 * based on prompt templates and visitor context.
 *
 * @package DirectReach
 * @subpackage RTR
 * @since 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPD_AI_Email_Generator {

    /**
     * Settings manager instance
     *
     * @var CPD_AI_Settings_Manager
     */
    private $settings;

    /**
     * Rate limiter instance
     *
     * @var CPD_AI_Rate_Limiter
     */
    private $rate_limiter;

    /**
     * Template resolver instance
     *
     * @var Template_Resolver
     */
    private $resolver;

    /**
     * Last generation metadata
     *
     * @var array
     */
    private $last_generation_meta = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = new CPD_AI_Settings_Manager();
        $this->rate_limiter = new CPD_AI_Rate_Limiter();
        $this->resolver = new Template_Resolver();
    }

    /**
     * Generate email for prospect
     *
     * @param int    $prospect_id Prospect ID
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Room type
     * @param int    $email_number Email sequence number
     * @return array|WP_Error Generation result or error
     */
    public function generate_email( $prospect_id, $campaign_id, $room_type, $email_number ) {
        // Check if AI is enabled
        $ai_enabled = get_option( 'dr_ai_email_enabled', false );
        if ( ! $ai_enabled ) {
            return new WP_Error(
                'ai_disabled',
                'AI generation is currently disabled in settings',
                array( 'status' => 400 )
            );
        }


        // Check rate limit
        $rate_limit_check = $this->rate_limiter->check_limit();
        if ( is_wp_error( $rate_limit_check ) ) {
            error_log( '[DirectReach] Rate limit exceeded: ' . $rate_limit_check->get_error_message() );
            return $rate_limit_check;
        }

        // Load prospect data
        $prospect = $this->load_prospect_data( $prospect_id );
        if ( is_wp_error( $prospect ) ) {
            return $prospect;
        }

        // Load available templates
        $templates = $this->resolver->get_available_templates( $campaign_id, $room_type );
        if ( empty( $templates ) ) {
            return new WP_Error(
                'no_templates',
                'No templates available for this room',
                array( 'status' => 400 )
            );
        }

        // Select best template based on visitor behavior
        $selected_template = $this->select_template( $templates, $prospect );

        // Load content links
        $content_links = $this->load_content_links( $campaign_id, $room_type );

        // Filter out previously sent URLs
        $sent_urls = array();
        if ( ! empty( $prospect['urls_sent'] ) ) {
            $sent_urls = json_decode( $prospect['urls_sent'], true ) ?: array();
        }
        
        if ( ! empty( $sent_urls ) ) {
            $content_links = array_filter( $content_links, function( $link ) use ( $sent_urls ) {
                return ! in_array( $link['link_url'], $sent_urls, true );
            });
            $content_links = array_values( $content_links ); // Re-index array
        }

        // Build generation payload
        $payload = $selected_template->build_generation_payload(
            $prospect,
            $content_links
        );

        // Generate email via Gemini API
        $generation_start = microtime( true );
        $result = $this->call_gemini_api( $payload );
        $generation_time = ( microtime( true ) - $generation_start ) * 1000;

        if ( is_wp_error( $result ) ) {
            error_log( '[DirectReach] AI generation failed: ' . $result->get_error_message() );
            
            // Fallback to template
            return $this->fallback_to_template( $prospect_id, $campaign_id, $room_type );
        }

        // Increment rate limiter
        $this->rate_limiter->increment();

        // Store generation metadata
        $this->last_generation_meta = array(
            'generation_time_ms' => round( $generation_time, 2 ),
            'template_id' => $selected_template->get_id(),
            'template_name' => $selected_template->get_name(),
            'is_global' => $selected_template->is_global(),
            'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $result['usage']['total_tokens'] ?? 0,
            'cost' => $this->calculate_cost( $result['usage'] ?? array() ),
        );

        // Format response
        return array(
            'success' => true,
            'subject' => $result['subject'],
            'body_html' => $result['body_html'],
            'body_text' => $result['body_text'],
            'selected_url' => $result['selected_url'],
            'template_used' => array(
                'id' => $selected_template->get_id(),
                'name' => $selected_template->get_name(),
                'is_global' => $selected_template->is_global(),
            ),
            'tokens_used' => array(
                'prompt' => $this->last_generation_meta['prompt_tokens'],
                'completion' => $this->last_generation_meta['completion_tokens'],
                'total' => $this->last_generation_meta['total_tokens'],
                'cost' => $this->last_generation_meta['cost'],
            ),
            'generation_time_ms' => $this->last_generation_meta['generation_time_ms'],
        );
    }

    /**
    * Generate test email with mock data
    * 
    * Used for testing prompt templates before deployment.
    * Uses hardcoded mock prospect data for consistent comparisons.
    *
    * @param array  $prompt_template 7-component prompt structure
    * @param int    $campaign_id Campaign ID for content links
    * @param string $room_type Room type (problem/solution/offer)
    * @return array|WP_Error Generation result or error
    */
    public function generate_email_for_test( $prompt_template, $campaign_id, $room_type = 'problem' ) {

        // Check if AI is enabled
        $ai_enabled = get_option( 'dr_ai_email_enabled', false );
        if ( ! $ai_enabled ) {
            return new WP_Error(
                'ai_disabled',
                'AI generation is currently disabled in settings',
                array( 'status' => 400 )
            );
        }
        
        // Check rate limit (if enabled)
        $rate_limit_check = $this->rate_limiter->check_limit();
        if ( is_wp_error( $rate_limit_check ) ) {
            error_log( '[DirectReach] Test generation rate limit exceeded: ' . $rate_limit_check->get_error_message() );
            return $rate_limit_check;
        }

        // Validate prompt template structure
        $validation = $this->validate_prompt_template( $prompt_template );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Get mock prospect data
        $mock_prospect = $this->get_mock_prospect_data( $room_type );

        // Load real content links from campaign
        $content_links = $this->load_content_links( $campaign_id, $room_type );
        
        // If no links found, use generic fallback
        if ( empty( $content_links ) ) {
            $content_links = $this->get_fallback_content_links( $room_type );
        }

        // Create mock template object
        $mock_template = $this->create_mock_template( $prompt_template, $room_type );

        // Build generation payload
        $payload = $mock_template->build_generation_payload(
            $mock_prospect,
            $content_links
        );

        // Generate email via Gemini API
        $generation_start = microtime( true );
        $result = $this->call_gemini_api( $payload );
        $generation_time = ( microtime( true ) - $generation_start ) * 1000;

        if ( is_wp_error( $result ) ) {
            error_log( '[DirectReach] Test generation failed: ' . $result->get_error_message() );
            return $result;
        }

        // Increment rate limiter
        $this->rate_limiter->increment();
        
        // Record usage stats
        $cost = $this->calculate_cost( $result['usage'] ?? array() );
        $this->rate_limiter->record_generation( 
            $result['usage']['total_tokens'] ?? 0,
            $cost
        );

        // Store generation metadata
        $metadata = array(
            'generation_time_ms' => round( $generation_time, 2 ),
            'template_type' => 'test',
            'campaign_id' => $campaign_id,
            'room_type' => $room_type,
            'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $result['usage']['total_tokens'] ?? 0,
            'cost' => $this->calculate_cost( $result['usage'] ?? array() ),
            'mock_prospect_used' => true,
        );

        // Format response
        return array(
            'success' => true,
            'subject' => $result['subject'],
            'body_html' => $result['body_html'],
            'body_text' => $result['body_text'],
            'selected_url' => $result['selected_url'],
            'mock_prospect' => array(
                'company_name' => $mock_prospect['company_name'],
                'contact_name' => $mock_prospect['contact_name'],
                'job_title' => $mock_prospect['job_title'],
            ),
            'usage' => array(
                'prompt_tokens' => $metadata['prompt_tokens'],
                'completion_tokens' => $metadata['completion_tokens'],
                'total_tokens' => $metadata['total_tokens'],
                'cost' => $metadata['cost'],
            ),
            'generation_time_ms' => $metadata['generation_time_ms'],
            'metadata' => $metadata,
        );
    }

    /**
     * Validate prompt template structure
     * 
     * @param array $prompt_template
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    private function validate_prompt_template( $prompt_template ) {
        if ( ! is_array( $prompt_template ) ) {
            return new WP_Error(
                'invalid_prompt_template',
                'Prompt template must be an array'
            );
        }

        $required_components = array(
            'persona',
            'style',
            'output',
            'personalization',
            'constraints',
            'examples',
            'context'
        );

        foreach ( $required_components as $component ) {
            if ( ! isset( $prompt_template[ $component ] ) ) {
                return new WP_Error(
                    'missing_component',
                    sprintf( 'Missing required component: %s', $component )
                );
            }
        }

        // Check if at least one component has content
        $has_content = false;
        foreach ( $prompt_template as $value ) {
            if ( ! empty( $value ) && trim( $value ) !== '' ) {
                $has_content = true;
                break;
            }
        }

        if ( ! $has_content ) {
            return new WP_Error(
                'empty_prompt',
                'At least one prompt component must have content'
            );
        }

        return true;
    }

    /**
     * Get hardcoded mock prospect data
     * 
     * Consistent mock data for comparing prompt variations.
     *
     * @param string $room_type Current room
     * @return array Mock prospect data
     */
    private function get_mock_prospect_data( $room_type = 'problem' ) {
        // Base mock prospect
        $mock_prospect = array(
            'id' => 0,
            'company_name' => 'Acme Manufacturing Corp',
            'contact_name' => 'Sarah Johnson',
            'contact_email' => 'sjohnson@acmemfg.com',
            'job_title' => 'VP of Marketing',
            'current_room' => $room_type,
            'lead_score' => 35,
            'days_in_room' => 3,
            'email_sequence_position' => 1,
            'urls_sent' => json_encode( array() ),
            'company_size' => '201-500',
            'company_industry' => 'Manufacturing',
            'company_revenue' => '$10M - $50M',
        );

        // Room-specific engagement data
        $engagement_data = array(
            'page_view_count' => 8,
            'last_visit' => date( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
        );

        // Vary recent pages by room
        switch ( $room_type ) {
            case 'problem':
                $engagement_data['recent_pages'] = array(
                    array(
                        'url' => '/features/marketing-automation',
                        'intent' => 'research',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
                    ),
                    array(
                        'url' => '/case-studies/manufacturing',
                        'intent' => 'evaluation',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-3 hours' ) ),
                    ),
                    array(
                        'url' => '/blog/attribution-challenges',
                        'intent' => 'education',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
                    ),
                );
                break;

            case 'solution':
                $engagement_data['recent_pages'] = array(
                    array(
                        'url' => '/pricing',
                        'intent' => 'consideration',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ),
                    ),
                    array(
                        'url' => '/demo',
                        'intent' => 'evaluation',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
                    ),
                    array(
                        'url' => '/features/reporting',
                        'intent' => 'research',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
                    ),
                );
                break;

            case 'offer':
                $engagement_data['recent_pages'] = array(
                    array(
                        'url' => '/pricing/enterprise',
                        'intent' => 'purchase',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-30 minutes' ) ),
                    ),
                    array(
                        'url' => '/contact-sales',
                        'intent' => 'purchase',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ),
                    ),
                    array(
                        'url' => '/demo/request',
                        'intent' => 'evaluation',
                        'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
                    ),
                );
                break;
        }

        $mock_prospect['engagement_data'] = json_encode( $engagement_data );

        return $mock_prospect;
    }

    /**
     * Get fallback content links when campaign has none
     * 
     * @param string $room_type
     * @return array Generic content links
     */
    private function get_fallback_content_links( $room_type ) {
        $fallback_links = array(
            'problem' => array(
                array(
                    'id' => 0,
                    'link_title' => 'Complete Guide to Marketing Attribution',
                    'link_url' => 'https://example.com/guides/marketing-attribution',
                    'link_description' => 'Learn how to properly track and attribute marketing conversions across multiple touchpoints.',
                    'url_summary' => 'Comprehensive guide covering attribution models, implementation strategies, and common challenges in marketing attribution.',
                    'link_order' => 1,
                ),
                array(
                    'id' => 0,
                    'link_title' => '5 Marketing Challenges in Manufacturing',
                    'link_url' => 'https://example.com/blog/manufacturing-marketing-challenges',
                    'link_description' => 'Unique marketing challenges facing manufacturing companies and how to overcome them.',
                    'url_summary' => 'Article addressing manufacturing-specific marketing challenges including long sales cycles, technical audiences, and ROI measurement.',
                    'link_order' => 2,
                ),
            ),
            'solution' => array(
                array(
                    'id' => 0,
                    'link_title' => 'Marketing Automation ROI Calculator',
                    'link_url' => 'https://example.com/tools/roi-calculator',
                    'link_description' => 'Calculate the potential ROI of marketing automation for your business.',
                    'url_summary' => 'Interactive calculator helping businesses estimate time savings, cost reduction, and revenue impact of marketing automation.',
                    'link_order' => 1,
                ),
                array(
                    'id' => 0,
                    'link_title' => 'Customer Success Stories',
                    'link_url' => 'https://example.com/case-studies',
                    'link_description' => 'See how companies like yours achieved results with our platform.',
                    'url_summary' => 'Case studies from manufacturing, B2B services, and enterprise companies showing measurable improvements in marketing efficiency.',
                    'link_order' => 2,
                ),
            ),
            'offer' => array(
                array(
                    'id' => 0,
                    'link_title' => 'Enterprise Pricing & Features',
                    'link_url' => 'https://example.com/pricing/enterprise',
                    'link_description' => 'Custom solutions for large organizations with dedicated support.',
                    'url_summary' => 'Enterprise plan details including unlimited users, dedicated support, custom integrations, and SLA guarantees.',
                    'link_order' => 1,
                ),
                array(
                    'id' => 0,
                    'link_title' => 'Schedule a Demo',
                    'link_url' => 'https://example.com/demo/schedule',
                    'link_description' => 'See the platform in action with a personalized demo.',
                    'url_summary' => 'Book a live demonstration tailored to your specific use case and industry, with Q&A and implementation discussion.',
                    'link_order' => 2,
                ),
            ),
        );

        return isset( $fallback_links[ $room_type ] )
            ? $fallback_links[ $room_type ]
            : $fallback_links['problem'];
    }

    /**
     * Create mock template object from raw prompt data
     * 
     * @param array  $prompt_template
     * @param string $room_type
     * @return CPD_Prompt_Template
     */
    private function create_mock_template( $prompt_template, $room_type ) {
        $template_data = array(
            'id' => 0,
            'template_name' => 'Test Template',
            'room_type' => $room_type,
            'is_global' => false,
            'prompt_template' => $prompt_template,
        );

        return new CPD_Prompt_Template( $template_data );
    }    

    /**
     * Call Gemini API to generate email
     */
    private function call_gemini_api( $payload, $retry_with_fallback = true ) {
        $api_key = $this->settings->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Gemini API key not configured' );
        }

        $model = $this->settings->get_model();
        
        // DEBUG: Log the model being used
        error_log( '[DirectReach] Using Gemini model: ' . $model );
        
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $model,
            $api_key
        );

        // Build comprehensive prompt
        $prompt = $this->build_complete_prompt( $payload );

        // Prepare request body
        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => $this->settings->get_temperature(),
                'maxOutputTokens' => $this->settings->get_max_tokens(),
                'topP' => 0.8,
                'topK' => 40,
            ),
        );

        // Make API request
        $response = wp_remote_post( $endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'api_request_failed',
                'Failed to connect to Gemini API: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        // Handle 404 - model not found or not supported
        if ( $status_code === 404 && $retry_with_fallback ) {
            error_log( sprintf(
                '[DirectReach] Model %s not found/supported, falling back to gemini-2.5-flash',
                $model
            ));
            
            // Try again with known working model
            return $this->call_gemini_api_with_model( $payload, 'gemini-2.5-flash', false );
        }

        if ( $status_code !== 200 ) {
            error_log( sprintf(
                '[DirectReach] Gemini API error (status %d): %s',
                $status_code,
                $response_body
            ));
            
            // Parse and log the error details
            $error_data = json_decode( $response_body, true );
            if ( isset( $error_data['error']['message'] ) ) {
                error_log( '[DirectReach] Gemini error message: ' . $error_data['error']['message'] );
            }

            return new WP_Error(
                'api_error',
                sprintf( 'Gemini API returned status %d', $status_code ),
                array( 'status' => $status_code, 'body' => $response_body )
            );
        }

        $data = json_decode( $response_body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'invalid_response',
                'Invalid JSON response from Gemini API'
            );
        }

        // Parse response
        return $this->parse_gemini_response( $data, $payload );
    }

    /**
     * Call Gemini API with specific model (helper for fallback)
     *
     * @param array  $payload Generation payload
     * @param string $model Model name to use
     * @param bool   $retry_with_fallback Whether to retry on failure
     * @return array|WP_Error API response or error
     */
    private function call_gemini_api_with_model( $payload, $model, $retry_with_fallback = false ) {
        $api_key = $this->settings->get_api_key();
        
        error_log( '[DirectReach] Fallback: Using model ' . $model );
        
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $model,
            $api_key
        );

        // Build comprehensive prompt
        $prompt = $this->build_complete_prompt( $payload );

        // Prepare request body
        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => $this->settings->get_temperature(),
                'maxOutputTokens' => $this->settings->get_max_tokens(),
                'topP' => 0.8,
                'topK' => 40,
            ),
        );

        // Make API request
        $response = wp_remote_post( $endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'api_request_failed',
                'Failed to connect to Gemini API: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( $status_code !== 200 ) {
            error_log( sprintf(
                '[DirectReach] Fallback model also failed (status %d): %s',
                $status_code,
                $response_body
            ));

            return new WP_Error(
                'api_error',
                sprintf( 'Gemini API returned status %d', $status_code ),
                array( 'status' => $status_code, 'body' => $response_body )
            );
        }

        $data = json_decode( $response_body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'invalid_response',
                'Invalid JSON response from Gemini API'
            );
        }

        // Parse response
        return $this->parse_gemini_response( $data, $payload );
    }

    /**
     * Build complete prompt for Gemini
     *
     * @param array $payload Generation payload
     * @return string Complete prompt
     */
    private function build_complete_prompt( $payload ) {
        $sections = array();

        // Add template prompt
        $sections[] = "=== EMAIL GENERATION INSTRUCTIONS ===\n" . $payload['prompt_template'];

        // Add visitor context
        $sections[] = "=== VISITOR INFORMATION ===\n" . $this->format_visitor_context( $payload['visitor_info'] );

        // Add available URLs
        if ( ! empty( $payload['available_urls'] ) ) {
            $sections[] = "=== AVAILABLE CONTENT LINKS ===\n" . $this->format_urls_context( $payload['available_urls'] );
        }

        // Add output format instructions
        $sections[] = $this->get_output_format_instructions();

        return implode( "\n\n", $sections );
    }

    /**
     * Format visitor context for prompt
     *
     * @param array $visitor_info Visitor information
     * @return string Formatted context
     */
    private function format_visitor_context( $visitor_info ) {
        $lines = array();

        if ( ! empty( $visitor_info['company_name'] ) ) {
            $lines[] = "Company: {$visitor_info['company_name']}";
        }

        if ( ! empty( $visitor_info['contact_name'] ) ) {
            $lines[] = "Contact: {$visitor_info['contact_name']}";
        }

        if ( ! empty( $visitor_info['job_title'] ) ) {
            $lines[] = "Title: {$visitor_info['job_title']}";
        }

        $lines[] = "Current Stage: {$visitor_info['current_room']}";
        $lines[] = "Lead Score: {$visitor_info['lead_score']}";
        $lines[] = "Days in Current Stage: {$visitor_info['days_in_room']}";
        $lines[] = "Email Sequence Position: {$visitor_info['email_sequence_position']}";

        // Parse recent_pages if it's a JSON string
        $recent_pages = array();
        if ( ! empty( $visitor_info['recent_pages'] ) ) {
            if ( is_string( $visitor_info['recent_pages'] ) ) {
                // It's a JSON string, decode it
                $recent_pages = json_decode( $visitor_info['recent_pages'], true );
            } elseif ( is_array( $visitor_info['recent_pages'] ) ) {
                // Already an array
                $recent_pages = $visitor_info['recent_pages'];
            }
        }

        if ( ! empty( $recent_pages ) && is_array( $recent_pages ) ) {
            $lines[] = "\nRecent Pages Visited:";
            foreach ( array_slice( $recent_pages, 0, 5 ) as $page ) {
                if ( is_array( $page ) && isset( $page['url'] ) ) {
                    $intent = $page['intent'] ?? 'unknown intent';
                    $lines[] = "- {$page['url']} ({$intent})";
                } elseif ( is_string( $page ) ) {
                    // If it's just a URL string
                    $lines[] = "- {$page}";
                }
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Format URLs context for prompt
     *
     * @param array $urls Available URLs
     * @return string Formatted URLs
     */
    private function format_urls_context( $urls ) {
        $lines = array();
        $lines[] = "Select ONE of the following content links to include in the email:";
        $lines[] = "";

        foreach ( $urls as $index => $url ) {
            $lines[] = sprintf( "[%d] %s", $index + 1, $url['title'] );
            $lines[] = "    URL: {$url['url']}";
            
            if ( ! empty( $url['summary'] ) ) {
                $lines[] = "    About: {$url['summary']}";
            }
            
            $lines[] = "";
        }

        return implode( "\n", $lines );
    }

    /**
     * Get output format instructions
     *
     * @return string Format instructions
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
     * Parse Gemini API response
     *
     * @param array $data API response data
     * @param array $payload Original payload for URL lookup
     * @return array|WP_Error Parsed response or error
     */
    private function parse_gemini_response( $data, $payload ) {
        // Extract text from response
        if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return new WP_Error( 'empty_response', 'Empty response from Gemini API' );
        }

        $response_text = $data['candidates'][0]['content']['parts'][0]['text'];

        // Remove markdown code fences if present
        $response_text = preg_replace( '/^```json\s*/m', '', $response_text );
        $response_text = preg_replace( '/^```\s*/m', '', $response_text );
        $response_text = trim( $response_text );

        // Parse JSON
        $parsed = json_decode( $response_text, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( '[DirectReach] Failed to parse Gemini JSON response: ' . $response_text );
            return new WP_Error(
                'invalid_json_response',
                'Could not parse JSON from Gemini response: ' . json_last_error_msg()
            );
        }

        // Validate required fields
        $required = array( 'subject', 'body_html', 'body_text', 'selected_url_index' );
        foreach ( $required as $field ) {
            if ( empty( $parsed[ $field ] ) ) {
                return new WP_Error(
                    'missing_field',
                    sprintf( 'Missing required field in response: %s', $field )
                );
            }
        }

        // Lookup selected URL
        $url_index = (int) $parsed['selected_url_index'] - 1; // Convert to 0-based
        if ( ! isset( $payload['available_urls'][ $url_index ] ) ) {
            error_log( sprintf(
                '[DirectReach] Invalid URL index %d, defaulting to first URL',
                $url_index
            ));
            $url_index = 0;
        }

        $selected_url = $payload['available_urls'][ $url_index ] ?? null;

        // Get token usage
        $usage = array(
            'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
            'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
        );

        return array(
            'subject' => sanitize_text_field( $parsed['subject'] ),
            'body_html' => wp_kses_post( $parsed['body_html'] ),
            'body_text' => sanitize_textarea_field( $parsed['body_text'] ),
            'selected_url' => $selected_url,
            'reasoning' => isset( $parsed['reasoning'] ) ? sanitize_text_field( $parsed['reasoning'] ) : '',
            'usage' => $usage,
        );
    }

    /**
     * Select best template based on visitor behavior
     *
     * For now, returns first template. Future enhancement: intelligent selection.
     *
     * @param array $templates Available templates
     * @param array $prospect Prospect data
     * @return CPD_Prompt_Template Selected template
     */
    private function select_template( $templates, $prospect ) {
        // Simple selection: use first template
        // TODO: Implement intelligent selection based on:
        // - Lead score
        // - Recent page visits
        // - Email sequence position
        // - Days in room
        
        return $templates[0];
    }

    /**
     * Fallback to template-based email
     *
     * @param int    $prospect_id Prospect ID
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Room type
     * @return array Template-based email
     */
    private function fallback_to_template( $prospect_id, $campaign_id, $room_type ) {
        // Load prospect
        $prospect = $this->load_prospect_data( $prospect_id );
        if ( is_wp_error( $prospect ) ) {
            return $prospect;
        }

        // Load templates
        $templates = $this->resolver->get_available_templates( $campaign_id, $room_type );
        if ( empty( $templates ) ) {
            return new WP_Error( 'no_templates', 'No templates available' );
        }

        $template = $templates[0];

        // Load content links
        $content_links = $this->load_content_links( $campaign_id, $room_type );
        
        // Get sent URLs
        $sent_urls = array();
        if ( ! empty( $prospect['urls_sent'] ) ) {
            $sent_urls = json_decode( $prospect['urls_sent'], true ) ?: array();
        }

        // Select first unsent URL
        $selected_url = null;
        foreach ( $content_links as $link ) {
            if ( ! in_array( $link['link_url'], $sent_urls, true ) ) {
                $selected_url = array(
                    'id' => $link['id'],
                    'title' => $link['link_title'],
                    'url' => $link['link_url'],
                    'description' => $link['link_description'] ?? '',
                );
                break;
            }
        }

        // Build simple email from template
        $subject = "Follow up: {$prospect['company_name']}";
        $body_html = "<p>Hi {$prospect['contact_name']},</p>";
        $body_html .= "<p>I noticed you've been exploring our {$room_type} solutions.</p>";
        
        if ( $selected_url ) {
            $body_html .= "<p>I thought you might find this resource helpful: <a href=\"{$selected_url['url']}\">{$selected_url['title']}</a></p>";
        }
        
        $body_html .= "<p>Let me know if you have any questions.</p>";
        
        $body_text = wp_strip_all_tags( $body_html );

        return array(
            'success' => true,
            'fallback' => true,
            'subject' => $subject,
            'body_html' => $body_html,
            'body_text' => $body_text,
            'selected_url' => $selected_url,
            'template_used' => array(
                'id' => $template->get_id(),
                'name' => $template->get_name(),
                'is_global' => $template->is_global(),
            ),
            'tokens_used' => array(
                'prompt' => 0,
                'completion' => 0,
                'total' => 0,
                'cost' => 0,
            ),
        );
    }

    /**
     * Load prospect data
     *
     * @param int $prospect_id Prospect ID
     * @return array|WP_Error Prospect data or error
     */
    private function load_prospect_data( $prospect_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'rtr_prospects';
        $prospect = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $prospect_id ),
            ARRAY_A
        );

        if ( ! $prospect ) {
            return new WP_Error( 'prospect_not_found', 'Prospect not found' );
        }

        return $prospect;
    }

    /**
     * Load content links
     *
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Room type
     * @return array Content links
     */
    private function load_content_links( $campaign_id, $room_type ) {
        global $wpdb;

        $table = $wpdb->prefix . 'rtr_room_content_links';
        $links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} 
                WHERE campaign_id = %d 
                AND room_type = %s 
                AND is_active = 1
                ORDER BY link_order ASC",
                $campaign_id,
                $room_type
            ),
            ARRAY_A
        );

        return $links ?: array();
    }

    /**
     * Calculate cost based on token usage
     *
     * Gemini 1.5 Pro pricing (as of Oct 2024):
     * - Input: $0.00125 / 1K tokens
     * - Output: $0.005 / 1K tokens
     *
     * @param array $usage Token usage
     * @return float Cost in USD
     */
    private function calculate_cost( $usage ) {
        $prompt_tokens = $usage['prompt_tokens'] ?? 0;
        $completion_tokens = $usage['completion_tokens'] ?? 0;

        $input_cost = ( $prompt_tokens / 1000 ) * 0.00125;
        $output_cost = ( $completion_tokens / 1000 ) * 0.005;

        return round( $input_cost + $output_cost, 6 );
    }

}