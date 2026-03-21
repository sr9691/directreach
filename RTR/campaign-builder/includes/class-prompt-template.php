<?php
/**
 * Prompt Template Handler
 *
 * Manages prompt template structure, validation, and assembly for AI email generation.
 *
 * @package DirectReach
 * @subpackage RTR
 * @since 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPD_Prompt_Template {

    /**
     * Required template components in assembly order
     *
     * @var array
     */
    const COMPONENTS = array(
        'persona',
        'style_rules',
        'output_spec',
        'personalization_guidelines',
        'constraints',
        'examples',
        'context_instructions'
    );

    /**
     * Template data
     *
     * @var array
     */
    private $data;

    /**
     * Validation errors
     *
     * @var array
     */
    private $errors = array();

    /**
     * Constructor
     *
     * @param array $template_data Template data from database or input
     */
    public function __construct( $template_data = array() ) {
        $this->data = $template_data;
    }

public function validate() {
        $this->errors = array();

        // Check if prompt_template exists and is valid JSON
        if ( empty( $this->data['prompt_template'] ) ) {
            $this->errors[] = 'prompt_template is required';
            return false;
        }

        // Decode if string
        if ( is_string( $this->data['prompt_template'] ) ) {
            $raw_data = json_decode( $this->data['prompt_template'], true );
            
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $this->errors[] = 'Invalid JSON in prompt_template: ' . json_last_error_msg();
                return false;
            }
        } else {
            $raw_data = $this->data['prompt_template'];
        }

        // Normalize field names (supports both short and long names)
        $prompt_data = $this->normalize_field_names( $raw_data );

        // Validate structure - all 7 components must exist (can be empty)
        foreach ( self::COMPONENTS as $component ) {
            if ( ! isset( $prompt_data[ $component ] ) ) {
                $this->errors[] = sprintf( 'Missing required component: %s', $component );
            }
        }

        // Validate at least one component has content
        $has_content = false;
        foreach ( self::COMPONENTS as $component ) {
            if ( ! empty( $prompt_data[ $component ] ) && trim( $prompt_data[ $component ] ) !== '' ) {
                $has_content = true;
                break;
            }
        }

        if ( ! $has_content ) {
            $this->errors[] = 'At least one component must have content';
        }

        return empty( $this->errors );
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Assemble final prompt for LLM
     *
     * Builds prompt from components in fixed order, skipping empty ones.
     *
     * @return string Assembled prompt
     */
    public function assemble_prompt() {
        $prompt_data = $this->get_prompt_data();
        $sections = array();

        foreach ( self::COMPONENTS as $component ) {
            $content = isset( $prompt_data[ $component ] ) ? trim( $prompt_data[ $component ] ) : '';
            
            // Skip empty components
            if ( empty( $content ) ) {
                continue;
            }

            // Format component with header
            $header = $this->format_component_header( $component );
            $sections[] = $header . "\n" . $content;
        }

        $assembled = implode( "\n\n", $sections );

        // Log assembly for debugging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[DirectReach] Assembled prompt with %d components (%d chars)',
                count( $sections ),
                strlen( $assembled )
            ) );
        }

        return $assembled;
    }

    /**
     * Format component header for readability
     *
     * @param string $component Component name
     * @return string Formatted header
     */
    private function format_component_header( $component ) {
        $headers = array(
            'persona' => '## PERSONA',
            'style_rules' => '## STYLE RULES',
            'output_spec' => '## OUTPUT SPECIFICATION',
            'personalization_guidelines' => '## PERSONALIZATION GUIDELINES',
            'constraints' => '## CONSTRAINTS',
            'examples' => '## EXAMPLES',
            'context_instructions' => '## CONTEXT INSTRUCTIONS'
        );

        return isset( $headers[ $component ] ) ? $headers[ $component ] : '## ' . strtoupper( str_replace( '_', ' ', $component ) );
    }

    /**
     * Get prompt data as array (with field name normalization)
     *
     * @return array
     */
    private function get_prompt_data() {
        if ( is_string( $this->data['prompt_template'] ) ) {
            $prompt_data = json_decode( $this->data['prompt_template'], true );
        } else {
            $prompt_data = $this->data['prompt_template'];
        }

        // Normalize short field names to long names for backwards compatibility
        return $this->normalize_field_names( $prompt_data );
    }

    /**
     * Normalize short field names to standard long names
     *
     * Supports both short names (style, output, etc.) and long names (style_rules, output_spec, etc.)
     *
     * @param array $prompt_data Raw prompt data
     * @return array Normalized prompt data
     */
    private function normalize_field_names( $prompt_data ) {
        if ( ! is_array( $prompt_data ) ) {
            return $prompt_data;
        }

        $mapping = array(
            'style'           => 'style_rules',
            'output'          => 'output_spec',
            'personalization' => 'personalization_guidelines',
            'context'         => 'context_instructions',
        );

        foreach ( $mapping as $short => $long ) {
            if ( isset( $prompt_data[ $short ] ) && ! isset( $prompt_data[ $long ] ) ) {
                $prompt_data[ $long ] = $prompt_data[ $short ];
                unset( $prompt_data[ $short ] );
            }
        }

        return $prompt_data;
    }

    /**
     * Format visitor info for generation payload
     *
     * @param array $prospect Prospect data from wp_rtr_prospects
     * @param array $visitor_data Additional visitor data from wp_cpd_visitors
     * @return array Formatted visitor info
     */
    public function format_visitor_info( $prospect, $visitor_data = array() ) {
        // Extract first name from contact_name
        $contact_name = $prospect['contact_name'] ?? '';
        $name_parts = explode( ' ', trim( $contact_name ), 2 );
        $first_name = $name_parts[0];

        $visitor_info = array(
            'company_name' => $prospect['company_name'] ?? '',
            'contact_name' => $contact_name,
            'first_name' => $first_name,
            'contact_email' => $prospect['contact_email'] ?? '',
            'current_room' => $prospect['current_room'] ?? 'problem',
            'lead_score' => intval( $prospect['lead_score'] ?? 0 ),
            'days_in_room' => intval( $prospect['days_in_room'] ?? 0 ),
            'email_sequence_position' => intval( $prospect['email_sequence_position'] ?? 0 ),
        );

        // Add visitor data if available
        if ( ! empty( $visitor_data ) ) {
            $visitor_info['company_size'] = $visitor_data['company_size'] ?? null;
            $visitor_info['company_industry'] = $visitor_data['company_industry'] ?? null;
            $visitor_info['company_revenue'] = $visitor_data['company_revenue'] ?? null;
            $visitor_info['job_title'] = $visitor_data['job_title'] ?? null;
            $visitor_info['job_function'] = $visitor_data['job_function'] ?? null;
        }

        // Parse engagement data if exists
        if ( ! empty( $prospect['engagement_data'] ) ) {
            $engagement = is_string( $prospect['engagement_data'] ) 
                ? json_decode( $prospect['engagement_data'], true ) 
                : $prospect['engagement_data'];

            if ( is_array( $engagement ) ) {
                $visitor_info['recent_pages'] = $engagement['recent_pages'] ?? array();
                $visitor_info['page_view_count'] = $engagement['page_view_count'] ?? 0;
                $visitor_info['last_visit'] = $engagement['last_visit'] ?? null;
            }
        }

        // Remove null values to keep payload clean
        return array_filter( $visitor_info, function( $value ) {
            return $value !== null;
        });
    }

    /**
     * Format available URLs for generation payload
     *
     * @param array $content_links Content links from wp_rtr_room_content_links
     * @param array $sent_urls URLs already sent to this prospect
     * @return array Formatted URLs
     */
    public function format_available_urls( $content_links, $sent_urls = array() ) {
        $available = array();

        foreach ( $content_links as $link ) {
            // Skip if already sent
            if ( in_array( $link['link_url'], $sent_urls, true ) ) {
                continue;
            }

            // Skip if inactive
            if ( isset( $link['is_active'] ) && ! $link['is_active'] ) {
                continue;
            }

            $available[] = array(
                'id' => intval( $link['id'] ),
                'title' => $link['link_title'] ?? '',
                'url' => $link['link_url'] ?? '',
                'description' => $link['link_description'] ?? '',
                'summary' => $link['url_summary'] ?? '',
            );
        }

        // Sort by link_order if available
        usort( $available, function( $a, $b ) {
            $order_a = isset( $a['link_order'] ) ? intval( $a['link_order'] ) : 999;
            $order_b = isset( $b['link_order'] ) ? intval( $b['link_order'] ) : 999;
            return $order_a - $order_b;
        });

        return $available;
    }

    /**
     * Build complete generation payload
     *
     * @param array $prospect Prospect data
     * @param array $content_links Content links
     * @param array $visitor_data Optional visitor data
     * @return array Complete payload for AI generation
     */
    public function build_generation_payload( $prospect, $content_links, $visitor_data = array() ) {
        // Parse sent URLs
        $sent_urls = array();
        if ( ! empty( $prospect['urls_sent'] ) ) {
            $sent_urls = is_string( $prospect['urls_sent'] ) 
                ? json_decode( $prospect['urls_sent'], true ) 
                : $prospect['urls_sent'];
            
            if ( ! is_array( $sent_urls ) ) {
                $sent_urls = array();
            }
        }

        $payload = array(
            'prompt_template' => $this->assemble_prompt(),
            'visitor_info' => $this->format_visitor_info( $prospect, $visitor_data ),
            'available_urls' => $this->format_available_urls( $content_links, $sent_urls ),
            'template_metadata' => array(
                'template_id' => $this->data['id'] ?? null,
                'template_name' => $this->data['template_name'] ?? '',
                'room_type' => $this->data['room_type'] ?? '',
                'is_global' => (bool) ( $this->data['is_global'] ?? false ),
            ),
        );

        return $payload;
    }

    /**
     * Create from database row
     *
     * @param object|array $row Database row
     * @return CPD_Prompt_Template
     */
    public static function from_database( $row ) {
        $data = is_object( $row ) ? (array) $row : $row;
        return new self( $data );
    }

    /**
     * Get template ID
     *
     * @return int|null
     */
    public function get_id() {
        return isset( $this->data['id'] ) ? intval( $this->data['id'] ) : null;
    }

    /**
     * Get template name
     *
     * @return string
     */
    public function get_name() {
        return $this->data['template_name'] ?? '';
    }

    /**
     * Get room type
     *
     * @return string
     */
    public function get_room_type() {
        return $this->data['room_type'] ?? '';
    }

    /**
     * Is global template
     *
     * @return bool
     */
    public function is_global() {
        return (bool) ( $this->data['is_global'] ?? false );
    }

    /**
     * Get raw data
     *
     * @return array
     */
    public function get_data() {
        return $this->data;
    }
}