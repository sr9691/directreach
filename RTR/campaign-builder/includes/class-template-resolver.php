<?php
/**
 * Template Resolver
 *
 * Handles template selection logic for AI email generation.
 * Loads and resolves templates based on campaign and global availability.
 *
 * @package DirectReach
 * @subpackage RTR
 * @since 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Template_Resolver {

    /**
     * WordPress database instance
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Templates table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'rtr_email_templates';
    }

    /**
     * Get available templates for a room
     *
     * Priority logic:
     * - If campaign has templates for this room: return only campaign templates
     * - If campaign has no templates: return global templates
     * - Returns ALL matching templates (no limit - AI will select best one)
     *
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Room type (problem, solution, offer)
     * @return array Array of CPD_Prompt_Template objects
     */
    public function get_available_templates( $campaign_id, $room_type ) {
        // Validate inputs
        if ( empty( $campaign_id ) || ! in_array( $room_type, array( 'problem', 'solution', 'offer' ), true ) ) {
            error_log( sprintf(
                '[DirectReach] Invalid parameters for get_available_templates: campaign_id=%s, room_type=%s',
                $campaign_id,
                $room_type
            ) );
            return array();
        }

        // First, try to load campaign templates
        $campaign_templates = $this->load_campaign_templates( $campaign_id, $room_type );

        // If campaign has templates, use only those
        if ( ! empty( $campaign_templates ) ) {
            error_log( sprintf(
                '[DirectReach] Using %d campaign template(s) for campaign %d, room %s',
                count( $campaign_templates ),
                $campaign_id,
                $room_type
            ) );
            return $campaign_templates;
        }

        // Otherwise, fall back to global templates
        $global_templates = $this->load_global_templates( $room_type );

        error_log( sprintf(
            '[DirectReach] Using %d global template(s) for campaign %d, room %s',
            count( $global_templates ),
            $campaign_id,
            $room_type
        ) );

        return $global_templates;
    }

    /**
     * Load campaign-specific templates
     *
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Room type
     * @return array Array of CPD_Prompt_Template objects
     */
    private function load_campaign_templates( $campaign_id, $room_type ) {
        // Query with JSON validation
        $query = $this->wpdb->prepare(
            "SELECT t.* 
            FROM {$this->table_name} t
            WHERE t.campaign_id = %d
            AND t.room_type = %s
            AND t.is_global = 0
            AND t.prompt_template IS NOT NULL
            AND t.prompt_template != ''
            AND t.prompt_template != '[]'
            AND t.prompt_template != '{}'
            ORDER BY t.template_order ASC, t.id ASC",
            $campaign_id,
            $room_type
        );

        $results = $this->wpdb->get_results( $query, ARRAY_A );

        if ( $this->wpdb->last_error ) {
            error_log( '[DirectReach] Database error loading campaign templates: ' . $this->wpdb->last_error );
            return array();
        }
        
        // Pre-filter results for valid JSON
        $valid_results = array();
        foreach ( $results as $row ) {
            $prompt_data = json_decode( $row['prompt_template'], true );
            
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                error_log( sprintf(
                    '[DirectReach] Template ID %d has invalid JSON: %s',
                    $row['id'],
                    json_last_error_msg()
                ) );
                continue;
            }
            
            // Check for minimum components (at least 5 of 7)
            $component_count = is_array( $prompt_data ) ? count( $prompt_data ) : 0;
            if ( $component_count < 5 ) {
                error_log( sprintf(
                    '[DirectReach] Template ID %d has only %d components (minimum 5 required)',
                    $row['id'],
                    $component_count
                ) );
                continue;
            }
            
            $valid_results[] = $row;
        }

        return $this->convert_to_template_objects( $valid_results );
    }

    /**
     * Load global templates
     *
     * @param string $room_type Room type
     * @return array Array of CPD_Prompt_Template objects
     */
    private function load_global_templates( $room_type ) {
        // Query with JSON validation
        $query = $this->wpdb->prepare(
            "SELECT t.*
            FROM {$this->table_name} t
            WHERE t.campaign_id = 0
            AND t.room_type = %s
            AND t.is_global = 1
            AND t.prompt_template IS NOT NULL
            AND t.prompt_template != ''
            AND t.prompt_template != '[]'
            AND t.prompt_template != '{}'
            ORDER BY t.template_order ASC, t.id ASC",
            $room_type
        );

        $results = $this->wpdb->get_results( $query, ARRAY_A );

        if ( $this->wpdb->last_error ) {
            error_log( '[DirectReach] Database error loading global templates: ' . $this->wpdb->last_error );
            return array();
        }
        
        // Pre-filter results for valid JSON
        $valid_results = array();
        foreach ( $results as $row ) {
            $prompt_data = json_decode( $row['prompt_template'], true );
            
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                error_log( sprintf(
                    '[DirectReach] Global template ID %d has invalid JSON: %s',
                    $row['id'],
                    json_last_error_msg()
                ) );
                continue;
            }
            
            // Check for minimum components (at least 5 of 7)
            $component_count = is_array( $prompt_data ) ? count( $prompt_data ) : 0;
            if ( $component_count < 5 ) {
                error_log( sprintf(
                    '[DirectReach] Global template ID %d has only %d components (minimum 5 required)',
                    $row['id'],
                    $component_count
                ) );
                continue;
            }
            
            $valid_results[] = $row;
        }

        return $this->convert_to_template_objects( $valid_results );
    }

    /**
     * Convert database rows to CPD_Prompt_Template objects
     *
     * @param array $results Database results
     * @return array Array of CPD_Prompt_Template objects
     */
    private function convert_to_template_objects( $results ) {
        if ( empty( $results ) ) {
            return array();
        }

        $templates = array();

        foreach ( $results as $row ) {
            $template = CPD_Prompt_Template::from_database( $row );
            
            // Validate template
            if ( ! $template->validate() ) {
                error_log( sprintf(
                    '[DirectReach] Invalid template (ID: %d): %s',
                    $row['id'] ?? 'unknown',
                    implode( ', ', $template->get_errors() )
                ) );
                continue;
            }

            $templates[] = $template;
        }

        return $templates;
    }

    /**
     * Get template by ID
     *
     * @param int $template_id Template ID
     * @return CPD_Prompt_Template|null
     */
    public function get_template_by_id( $template_id ) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d LIMIT 1",
            $template_id
        );

        $result = $this->wpdb->get_row( $query, ARRAY_A );

        if ( empty( $result ) ) {
            return null;
        }

        $template = CPD_Prompt_Template::from_database( $result );

        if ( ! $template->validate() ) {
            error_log( sprintf(
                '[DirectReach] Invalid template ID %d: %s',
                $template_id,
                implode( ', ', $template->get_errors() )
            ) );
            return null;
        }

        return $template;
    }

    /**
     * Get all global templates for a room
     *
     * Used by admin interface to manage global templates.
     *
     * @param string $room_type Room type
     * @return array Array of CPD_Prompt_Template objects
     */
    public function get_all_global_templates( $room_type = null ) {
        $query = "SELECT * FROM {$this->table_name}
                  WHERE campaign_id = 0 AND is_global = 1";

        if ( $room_type ) {
            $query .= $this->wpdb->prepare( " AND room_type = %s", $room_type );
        }

        $query .= " ORDER BY room_type ASC, template_order ASC, id ASC";

        $results = $this->wpdb->get_results( $query, ARRAY_A );

        return $this->convert_to_template_objects( $results );
    }

    /**
     * Get template count for campaign/room
     *
     * @param int    $campaign_id Campaign ID (0 for global)
     * @param string $room_type Room type
     * @param bool   $global_only Count only global templates
     * @return int Template count
     */
    public function get_template_count( $campaign_id, $room_type, $global_only = false ) {
        if ( $global_only ) {
            $query = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE campaign_id = 0
                AND room_type = %s
                AND is_global = 1",
                $room_type
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE campaign_id = %d
                AND room_type = %s",
                $campaign_id,
                $room_type
            );
        }

        return (int) $this->wpdb->get_var( $query );
    }

    /**
     * Check if campaign has custom templates
     *
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Optional room type filter
     * @return bool True if campaign has custom templates
     */
    public function has_campaign_templates( $campaign_id, $room_type = null ) {
        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE campaign_id = %d
            AND is_global = 0",
            $campaign_id
        );

        if ( $room_type ) {
            $query .= $this->wpdb->prepare( " AND room_type = %s", $room_type );
        }

        $count = (int) $this->wpdb->get_var( $query );

        return $count > 0;
    }

    /**
     * Get template statistics for campaign
     *
     * Returns metadata about template availability.
     *
     * @param int    $campaign_id Campaign ID
     * @param string $room_type Room type
     * @return array Statistics array
     */
    public function get_template_stats( $campaign_id, $room_type ) {
        $campaign_count = $this->get_template_count( $campaign_id, $room_type, false );
        $global_count = $this->get_template_count( 0, $room_type, true );

        $stats = array(
            'campaign_count' => $campaign_count,
            'global_count' => $global_count,
            'total_available' => $campaign_count > 0 ? $campaign_count : $global_count,
            'using_campaign' => $campaign_count > 0,
            'using_global' => $campaign_count === 0,
        );

        return $stats;
    }
}