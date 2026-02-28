<?php
/**
 * Reading Room REST API Controller
 *
 * Handles all REST endpoints for prospects, analytics, and campaign data.
 *
 * @package DirectReach
 * @subpackage ReadingTheRoom
 * @since 2.0.0
 */

declare(strict_types=1);

namespace DirectReach\ReadingTheRoom\API;

use DirectReach\ReadingTheRoom\Reading_Room_Database;
use DirectReach\ReadingTheRoom\API\ALeads_Enrichment;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class Reading_Room_Controller extends WP_REST_Controller
{
    /** @var Reading_Room_Database */
    private $db;

    /** @var \DirectReach\ReadingTheRoom\API\ALeads_Enrichment */
    private $enrichment;

    /** @var string */
    protected $namespace = 'directreach/v1/reading-room';

    /**
     * Constructor.
     *
     * @param Reading_Room_Database $db
     */
    public function __construct(Reading_Room_Database $db)
    {
        $this->db = $db;
        $this->enrichment = new \DirectReach\ReadingTheRoom\API\ALeads_Enrichment();

    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void
    {
        // Prospects endpoints
        register_rest_route($this->namespace, '/prospects', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_prospects'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'client_id'   => ['type' => 'integer', 'required' => false],
                    'campaign_id' => ['type' => 'integer', 'required' => false],
                    'room'        => ['type' => 'string', 'required' => false],
                    'page'        => ['type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1],
                    'per_page'    => ['type' => 'integer', 'required' => false, 'default' => 10, 'minimum' => 1, 'maximum' => 100],
                    'orderby'     => ['type' => 'string', 'required' => false, 'default' => 'lead_score', 'enum' => ['lead_score', 'created_at', 'updated_at', 'company_name']],
                    'order'       => ['type' => 'string', 'required' => false, 'default' => 'desc', 'enum' => ['asc', 'desc']],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_prospect'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)/archive', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'archive_prospect'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)/handoff', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handoff_prospect'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)/update-contact', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'update_prospect_contact'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'contact_name'  => ['type' => 'string', 'required' => true],
                    'contact_email' => ['type' => 'string', 'required' => false],
                    'job_title'     => ['type' => 'string', 'required' => false],
                ],
            ],
        ]);

        // A-Leads Enrichment endpoints
        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)/search-contacts', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'search_contacts_enrichment'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)/save-enrichment', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'save_enriched_contact'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'contact_name'  => ['type' => 'string', 'required' => true],
                    'contact_email' => ['type' => 'string', 'required' => false],
                    'job_title'     => ['type' => 'string', 'required' => false],
                ],
            ],
        ]);

        // Find Email endpoint
        register_rest_route($this->namespace, '/prospects/(?P<id>\d+)/find-email', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'find_email'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ]
                ]
            ]
        ]);

        register_rest_route(
            'directreach/v1',
            '/reading-room/prospects/(?P<id>\d+)/verify-email',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'verify_email' ),
                'permission_callback' => array( $this, 'check_permission' ),
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param );
                        }
                    ),
                    'email' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_email( $param );
                        },
                        'sanitize_callback' => 'sanitize_email'
                    )
                )
            )
        );       

        // Analytics endpoints
        register_rest_route($this->namespace, '/analytics/room-counts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_room_counts'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'client_id'   => ['type' => 'integer', 'required' => false],
                    'campaign_id' => ['type' => 'integer', 'required' => false],
                    'days'        => ['type' => 'integer', 'required' => false],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/analytics/campaign-stats', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_campaign_stats'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'room'      => ['type' => 'string', 'required' => true],
                    'client_id' => ['type' => 'integer', 'required' => false],
                    'days'      => ['type' => 'integer', 'default' => 30],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/analytics/room-trends', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_room_trends'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'room' => ['type' => 'string', 'required' => true],
                    'days' => ['type' => 'integer', 'default' => 30],
                ],
            ],
        ]);
        
        // Campaigns endpoint
        register_rest_route($this->namespace, '/campaigns', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_campaigns'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // Prospect details by visitor ID
        register_rest_route($this->namespace, '/prospects/(?P<visitor_id>[\w-]+)/details', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_prospect_details'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'visitor_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'The visitor ID to fetch details for',
                ),
            ),
        ));



    }

    /**
     * Get all prospects with optional filters.
     *
     */
    public function get_prospects(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $filters = [];
            error_log('Getting prospects with filters: ' . print_r($request->get_params(), true));
            if ($request->has_param('client_id') && !empty($request->get_param('client_id'))) {
                $filters['client_id'] = (int) $request->get_param('client_id');
            }
            error_log('Client ID filter: ' . ($filters['client_id'] ?? 'none'));
            if ($request->has_param('campaign_id') && !empty($request->get_param('campaign_id'))) {
                $filters['campaign_id'] = (int) $request->get_param('campaign_id');
            }
            error_log('Campaign ID filter: ' . ($filters['campaign_id'] ?? 'none'));

            if ($request->has_param('days') && !empty($request->get_param('days'))) {
                $filters['days'] = (int) $request->get_param('days');
            }
            error_log('Days filter: ' . ($filters['days'] ?? 'none'));
            
            // Get pagination parameters
            $page = max(1, (int) $request->get_param('page'));
            $per_page = max(1, min(100, (int) $request->get_param('per_page')));
            
            // Get sort parameters
            $orderby = $request->get_param('orderby') ?: 'lead_score';
            $order = $request->get_param('order') ?: 'desc';
            
            // Validate orderby field
            $allowed_orderby = ['lead_score', 'created_at', 'updated_at', 'company_name'];
            if (!in_array($orderby, $allowed_orderby)) {
                $orderby = 'lead_score';
            }
            
            // Validate order direction
            $order = strtolower($order) === 'asc' ? 'asc' : 'desc';
            
            $prospects = $this->db->get_prospects($filters);

            // Ensure each prospect has a room assignment
            foreach ($prospects as &$prospect) {
                if (empty($prospect['room'])) {
                    $prospect['room'] = $this->determine_prospect_room($prospect);
                }
            }

            // Filter by room if parameter is provided
            $requested_room = $request->get_param('room');
            if (!empty($requested_room)) {
                $prospects = array_values(array_filter($prospects, function($prospect) use ($requested_room) {
                    return ($prospect['room'] ?? '') === $requested_room;
                }));
            }

            // Sort prospects based on orderby and order parameters
            usort($prospects, function($a, $b) use ($orderby, $order) {
                $val_a = $a[$orderby] ?? '';
                $val_b = $b[$orderby] ?? '';
                
                // Handle numeric vs string comparison
                if (in_array($orderby, ['lead_score'])) {
                    $val_a = (int) $val_a;
                    $val_b = (int) $val_b;
                    $result = $val_a - $val_b;
                } elseif (in_array($orderby, ['created_at', 'updated_at'])) {
                    $result = strtotime($val_a) - strtotime($val_b);
                } else {
                    $result = strcasecmp((string) $val_a, (string) $val_b);
                }
                
                return $order === 'desc' ? -$result : $result;
            });
            
            // Calculate pagination metadata
            $total_count = count($prospects);
            $total_pages = ceil($total_count / $per_page);
            $offset = ($page - 1) * $per_page;
            
            // Apply pagination
            $paginated_prospects = array_slice($prospects, $offset, $per_page);

            // Add email states ONLY for paginated prospects
            foreach ($paginated_prospects as &$prospect) {
                $prospect['email_states'] = $this->get_email_states(
                    (int) $prospect['id'],
                    $prospect['room']
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => $paginated_prospects,
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $per_page,
                    'total_pages'  => $total_pages,
                    'total_count'  => $total_count,
                ],
            ], 200);

        } catch (\Exception $e) {
            error_log('[DirectReach][API] get_prospects error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve prospects',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single prospect by ID.
     */
    public function get_prospect(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        
        $prospect = $this->db->get_prospect($id);
        
        if (!$prospect) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Prospect not found',
            ], 404);
        }

        // FIX: Ensure room assignment
        if (empty($prospect['room'])) {
            $prospect['room'] = $this->determine_prospect_room($prospect);
        }

        // Add email states
        $prospect['email_states'] = $this->get_email_states(
            (int) $prospect['id'],
            $prospect['room']
        );

        return new WP_REST_Response([
            'success' => true,
            'data'    => $prospect,
        ], 200);
    }

    /**
     * Archive a prospect.
     */
    public function archive_prospect(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        
        try {
            // Get the prospect
            $prospect = $this->db->get_prospect($id);
            if (!$prospect) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Prospect not found',
                ], 404);
            }

            // Update prospect with archived_at timestamp
            $result = $this->db->save_prospect([
                'id'          => $id,
                'archived_at' => current_time('mysql'),
            ]);

            if (!$result) {
                throw new \Exception('Failed to update prospect');
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Prospect archived successfully',
            ], 200);

        } catch (\Exception $e) {
            error_log('[DirectReach][API] archive_prospect error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to archive prospect',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Hand off prospect to sales.
     */
    public function handoff_prospect(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        
        try {
            $prospect = $this->db->get_prospect($id);
            if (!$prospect) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Prospect not found',
                ], 404);
            }

            $result = $this->db->save_prospect([
                'id'                => $id,
                'current_room'      => 'sales',
                'sales_handoff_at'  => current_time('mysql'),
                'handoff_notes'     => 'Handed off from dashboard',
            ]);

            if (!$result) {
                throw new \Exception('Failed to update prospect');
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Prospect handed off to sales',
            ], 200);

        } catch (\Exception $e) {
            error_log('[DirectReach][API] handoff_prospect error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to hand off prospect',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed prospect information
     * 
     * Fetches comprehensive data from both rtr_prospects and cpd_visitors tables
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_prospect_details($request) {
        $visitor_id = $request->get_param('visitor_id');
        
        if (empty($visitor_id)) {
            return new WP_Error(
                'missing_visitor_id', 
                'Visitor ID is required', 
                array('status' => 400)
            );
        }
        
        global $wpdb;
        
        // Table names
        $prospects_table = $wpdb->prefix . 'rtr_prospects';
        $visitors_table = $wpdb->prefix . 'cpd_visitors';
        $campaigns_table = $wpdb->prefix . 'dr_campaign_settings';
        
        try {
            // Get visitor data first
            $visitor = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$visitors_table} WHERE id = %d",
                intval($visitor_id)
            ), ARRAY_A);
            
            // Get prospect data with campaign information - use visitor's lead_score and current_room
            $prospect = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    p.*,
                    c.campaign_name,
                    v.lead_score,
                    v.current_room
                FROM {$prospects_table} p
                LEFT JOIN {$campaigns_table} c ON p.campaign_id = c.id
                LEFT JOIN {$visitors_table} v ON p.visitor_id = v.id
                WHERE p.visitor_id = %s 
                AND p.archived_at IS NULL
                ORDER BY p.id DESC
                LIMIT 1",
                $visitor_id
            ), ARRAY_A);
            
            // Get intelligence data
            $intelligence = $wpdb->get_row($wpdb->prepare(
                "SELECT response_data, status, processing_time, created_at
                FROM {$wpdb->prefix}cpd_visitor_intelligence 
                WHERE visitor_id = %s 
                AND status = 'completed'
                ORDER BY id DESC 
                LIMIT 1",
                $visitor_id
            ), ARRAY_A);
            
            // If neither exists, return 404
            if (!$prospect && !$visitor) {
                return new WP_Error(
                    'not_found', 
                    'Prospect not found', 
                    array('status' => 404)
                );
            }
            
            // Parse JSON fields in prospect data
            if ($prospect) {
                $json_fields = array('email_states', 'engagement_data', 'urls_sent', 'handoff_notes');
                foreach ($json_fields as $field) {
                    if (!empty($prospect[$field]) && is_string($prospect[$field])) {
                        $decoded = json_decode($prospect[$field], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $prospect[$field] = $decoded;
                        }
                    }
                }
            }
            
            // Parse JSON fields in visitor data
            if ($visitor) {
                $json_fields = array('recent_page_urls', 'tags', 'filter_matches');
                foreach ($json_fields as $field) {
                    if (!empty($visitor[$field]) && is_string($visitor[$field])) {
                        $decoded = json_decode($visitor[$field], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $visitor[$field] = $decoded;
                        }
                    }
                }
            }

            // Add email states from tracking table
            if ($prospect && !empty($prospect['id']) && !empty($prospect['current_room'])) {
                $prospect['email_states'] = $this->get_email_states(
                    (int) $prospect['id'],
                    $prospect['current_room']
                );
            }
            
            // Parse intelligence response_data
            if ($intelligence && !empty($intelligence['response_data'])) {
                $decoded = json_decode($intelligence['response_data'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $intelligence['response_data'] = $decoded;
                }
            }
            
            // Compile response
            $response_data = array(
                'success' => true,
                'data' => array(
                    'prospect' => $prospect ?: (object)array(),
                    'visitor' => $visitor ?: array(),
                    'intelligence' => $intelligence ?: (object)array()
                )
            );
            
            // Log the access (optional)
            if (defined('RTR_LOG_PROSPECT_VIEWS') && RTR_LOG_PROSPECT_VIEWS === true) {
                $this->log_prospect_view($visitor_id, $request);
            }
            
            return rest_ensure_response($response_data);
            
        } catch (Exception $e) {
            error_log('Error fetching prospect details: ' . $e->getMessage());
            return new WP_Error(
                'database_error',
                'Failed to fetch prospect details',
                array('status' => 500)
            );
        }
    }


    /**
     * Get room counts with filters.
     *
     */
    public function get_room_counts(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            
            $filters = [];
            
            if ($request->has_param('client_id') && !empty($request->get_param('client_id'))) {
                $filters['client_id'] = (int) $request->get_param('client_id');
            }
            
            if ($request->has_param('campaign_id') && !empty($request->get_param('campaign_id'))) {
                $filters['campaign_id'] = (int) $request->get_param('campaign_id');
            }

            if ($request->has_param('days') && !empty($request->get_param('days'))) {
                $filters['days'] = (int) $request->get_param('days');
            }

            $prospects = $this->db->get_prospects($filters);

            // Initialize counts and analytics
            $counts = [
                'problem'  => 0,
                'solution' => 0,
                'offer'    => 0,
                'sales'    => 0,
            ];
            
            $analytics = [
                'problem' => [
                    'new_today' => 0,
                    'progress_rate' => 0,
                ],
                'solution' => [
                    'high_scores' => 0,
                    'open_rate' => 0,
                ],
                'offer' => [
                    'high_scores' => 0,
                    'click_rate' => 0,
                ],
                'sales' => [
                    'this_week' => 0,
                    'avg_days' => 0,
                ],
            ];
            
            // Track prospects by room for calculations
            $room_prospects = [
                'problem' => [],
                'solution' => [],
                'offer' => [],
                'sales' => [],
            ];
            
            $today = date('Y-m-d');
            $week_ago = date('Y-m-d', strtotime('-7 days'));

            foreach ($prospects as $prospect) {
                // Skip archived prospects
                if (!empty($prospect['archived_at'])) {
                    continue;
                }

                // Determine room
                $room = $this->determine_prospect_room($prospect);
                
                if (!isset($counts[$room])) {
                    continue;
                }
                
                $counts[$room]++;
                $room_prospects[$room][] = $prospect;
                
                // Calculate room-specific analytics
                $created_date = substr($prospect['created_at'], 0, 10);
                
                switch ($room) {
                    case 'problem':
                        // Count new today
                        if ($created_date === $today) {
                            $analytics['problem']['new_today']++;
                        }
                        break;
                        
                    case 'solution':
                        // Count high scores (>=50)
                        if (!empty($prospect['lead_score']) && $prospect['lead_score'] >= 50) {
                            $analytics['solution']['high_scores']++;
                        }
                        break;
                        
                    case 'offer':
                        // Count high scores (>=70)
                        if (!empty($prospect['lead_score']) && $prospect['lead_score'] >= 70) {
                            $analytics['offer']['high_scores']++;
                        }
                        break;
                        
                    case 'sales':
                        // Count handoffs this week
                        if (!empty($prospect['sales_handoff_at'])) {
                            $handoff_date = substr($prospect['sales_handoff_at'], 0, 10);
                            if ($handoff_date >= $week_ago) {
                                $analytics['sales']['this_week']++;
                            }
                        }
                        break;
                }
            }
            
            // Calculate progress rate for Problem room
            // (prospects that moved from problem to solution)
            if ($counts['problem'] > 0) {
                $table_progression = $wpdb->prefix . 'rtr_room_progression';
                $progress_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT visitor_id) 
                    FROM {$table_progression} 
                    WHERE from_room = 'problem' 
                    AND to_room = 'solution'
                    AND transitioned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" // Change created_at to transitioned_at
                ));
                
                if ($progress_count) {
                    $analytics['problem']['progress_rate'] = round(
                        ($progress_count / ($counts['problem'] + $progress_count)) * 100
                    );
                }
            }
            
            // Calculate email open rate for Solution room
            if ($counts['solution'] > 0) {
                $solution_visitor_ids = array_column($room_prospects['solution'], 'visitor_id');
                
                if (!empty($solution_visitor_ids)) {
                    $placeholders = implode(',', array_fill(0, count($solution_visitor_ids), '%d'));
                    $table_tracking = $wpdb->prefix . 'rtr_email_tracking';
                    
                    $email_stats = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COUNT(DISTINCT visitor_id) as total_sent,
                            COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL THEN visitor_id END) as total_opened
                        FROM {$table_tracking}
                        WHERE visitor_id IN ({$placeholders})
                        AND room_type = 'solution'
                        AND status IN ('sent', 'opened', 'clicked')",
                        $solution_visitor_ids
                    ));
                    
                    if ($email_stats && $email_stats->total_sent > 0) {
                        $analytics['solution']['open_rate'] = round(
                            ($email_stats->total_opened / $email_stats->total_sent) * 100
                        );
                    }
                }
            }
            
            // Calculate email click rate for Offer room
            if ($counts['offer'] > 0) {
                $offer_visitor_ids = array_column($room_prospects['offer'], 'visitor_id');
                
                if (!empty($offer_visitor_ids)) {
                    $placeholders = implode(',', array_fill(0, count($offer_visitor_ids), '%d'));
                    $table_tracking = $wpdb->prefix . 'rtr_email_tracking';
                    
                    $email_stats = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COUNT(DISTINCT visitor_id) as total_sent,
                            COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN visitor_id END) as total_clicked
                        FROM {$table_tracking}
                        WHERE visitor_id IN ({$placeholders})
                        AND room_type = 'offer'
                        AND status IN ('sent', 'opened', 'clicked')",
                        $offer_visitor_ids
                    ));
                    
                    if ($email_stats && $email_stats->total_sent > 0) {
                        $analytics['offer']['click_rate'] = round(
                            ($email_stats->total_clicked / $email_stats->total_sent) * 100
                        );
                    }
                }
            }
            
            // Calculate average days for Sales room
            if ($counts['sales'] > 0) {
                $sales_visitor_ids = array_column($room_prospects['sales'], 'visitor_id');
                
                if (!empty($sales_visitor_ids)) {
                    $placeholders = implode(',', array_fill(0, count($sales_visitor_ids), '%d'));
                    $table_progression = $wpdb->prefix . 'rtr_room_progression';
                    
                    $avg_days = $wpdb->get_var($wpdb->prepare(
                        "SELECT AVG(DATEDIFF(
                            (SELECT transitioned_at FROM {$table_progression} p2 
                            WHERE p2.visitor_id = p1.visitor_id 
                            AND p2.to_room = 'sales' 
                            ORDER BY p2.transitioned_at DESC LIMIT 1),
                            (SELECT transitioned_at FROM {$table_progression} p3 
                            WHERE p3.visitor_id = p1.visitor_id 
                            AND p3.to_room = 'offer' 
                            ORDER BY p3.transitioned_at ASC LIMIT 1)
                        )) as avg_days
                        FROM {$table_progression} p1
                        WHERE p1.visitor_id IN ({$placeholders})
                        AND p1.to_room = 'sales'
                        GROUP BY p1.visitor_id",
                        $sales_visitor_ids
                    ));
                    
                    if ($avg_days) {
                        $analytics['sales']['avg_days'] = round($avg_days, 1);
                    }
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => $counts,
                'analytics' => $analytics,
                'total'   => array_sum($counts),
            ], 200);

        } catch (\Exception $e) {
            error_log('[DirectReach][API] get_room_counts error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve room counts',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function get_room_trends(WP_REST_Request $request): WP_REST_Response
    {
        $room = $request->get_param('room');
        $days = (int) $request->get_param('days') ?: 30;
        
        global $wpdb;
        $table = $wpdb->prefix . 'rtr_prospects';
        
        // For sales room, check sales_handoff_at
        if ($room === 'sales') {
            $where = "sales_handoff_at IS NOT NULL AND archived_at IS NULL";
        } else {
            $where = $wpdb->prepare(
                "current_room = %s AND archived_at IS NULL AND sales_handoff_at IS NULL",
                $room
            );
        }

        $trends = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as date, COUNT(*) as count
                FROM {$table}
                WHERE {$where}
                AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC",
                $days
            ),
            ARRAY_A
        );

        // Same for summary stats
        $summary = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                AVG(lead_score) as avg_score
            FROM {$table}
            WHERE {$where}",
            ARRAY_A
        );
        
        // Calculate conversion rate (prospects moved to next room)
        $moved_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id)
            FROM {$wpdb->prefix}rtr_room_progression
            WHERE from_room = %s
            AND transitioned_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $room,
            $days
        ));
        
        $total = (int) ($summary['total'] ?? 0);
        $summary['conversion_rate'] = $total > 0 ? round((float)$moved_count / $total * 100, 1) : 0;
        $summary['avg_score'] = round((float)($summary['avg_score'] ?? 0), 1);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $trends,
            'summary' => $summary,
            'room' => $room,
            'days' => $days,
        ], 200);
    }


    private function get_room_thresholds(?int $client_id = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rtr_room_thresholds';
        
        // Try client-specific first, then global (NULL), then hardcoded defaults
        $thresholds = $wpdb->get_row($wpdb->prepare(
            "SELECT problem_max, solution_max, offer_min 
            FROM {$table} 
            WHERE client_id = %d OR client_id IS NULL 
            ORDER BY client_id DESC 
            LIMIT 1",
            $client_id
        ), ARRAY_A);
        
        // If no database entry exists, use hardcoded defaults
        if (!$thresholds) {
            return [
                'problem_max'   => 40,
                'solution_max'  => 60,
                'offer_min'     => 61,
            ];
        }
        
        return [
            'problem_max'   => (int) $thresholds['problem_max'],
            'solution_max'  => (int) $thresholds['solution_max'],
            'offer_min'     => (int) $thresholds['offer_min'],
        ];
    }

    /**
     * Get campaign statistics for a room.
     *
     */
    public function get_campaign_stats(WP_REST_Request $request): WP_REST_Response
    {
        $room      = sanitize_text_field($request->get_param('room'));
        $days      = (int) $request->get_param('days') ?: 30;
        $client_id = $request->has_param('client_id') 
            ? (int) $request->get_param('client_id') 
            : null;

        if (!in_array($room, ['problem', 'solution', 'offer', 'sales'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid room specified',
            ], 400);
        }

        try {
            $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            $filters = ['date_from' => $date_from];
            if ($client_id) {
                $filters['client_id'] = $client_id;
            }

            $prospects = $this->db->get_prospects($filters);

            $room_prospects = array_filter($prospects, function($p) use ($room) {
                return $this->determine_prospect_room($p) === $room;
            });

            $total = count($room_prospects);
            $new_count = 0;
            $avg_score = 0;

            if ($total > 0) {
                $today = date('Y-m-d');
                $score_sum = 0;

                foreach ($room_prospects as $p) {
                    $created = substr($p['created_at'] ?? '', 0, 10);
                    if ($created === $today) {
                        $new_count++;
                    }
                    $score_sum += (int) ($p['lead_score'] ?? 0);
                }

                $avg_score = round($score_sum / $total, 1);
            }

            $email_stats = $this->get_email_stats_for_room($room, $filters);

            $stats = [
                'room'            => $room,
                'total_prospects' => $total,
                'new_prospects'   => $new_count,
                'avg_score'       => $avg_score,
                'sent_emails'     => $email_stats['sent'] ?? 0,
                'opened_emails'   => $email_stats['opened'] ?? 0,
                'clicked_links'   => $email_stats['clicked'] ?? 0,
            ];

            switch ($room) {
                case 'problem':
                    $stats['progress_rate'] = $total > 0 ? round(($new_count / $total) * 100) : 0;
                    break;
                case 'solution':
                    $stats['high_scores'] = $avg_score > 70 ? round($total * 0.3) : round($total * 0.15);
                    $stats['open_rate'] = $stats['sent_emails'] > 0 
                        ? round(($stats['opened_emails'] / $stats['sent_emails']) * 100) 
                        : 0;
                    break;
                case 'offer':
                    $this_week_count = $this->count_prospects_this_week($room_prospects);
                    $stats['this_week'] = $this_week_count;
                    $stats['click_rate'] = $stats['sent_emails'] > 0 
                        ? round(($stats['clicked_links'] / $stats['sent_emails']) * 100) 
                        : 0;
                    break;
                case 'sales':
                    $this_week_sales = $this->count_prospects_this_week($room_prospects);
                    $stats['this_week'] = $this_week_sales;
                    $stats['avg_days'] = $this->calculate_avg_days_to_close($room_prospects);
                    break;
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => $stats,
            ], 200);

        } catch (\Exception $e) {
            error_log('[DirectReach][API] get_campaign_stats error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve campaign stats',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get campaigns list.
     */
    public function get_campaigns(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $campaigns = $this->db->get_campaigns(['status' => 'active']);

            return new WP_REST_Response([
                'success' => true,
                'data'    => $campaigns,
            ], 200);

        } catch (\Exception $e) {
            error_log('[DirectReach][API] get_campaigns error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to retrieve campaigns',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper: Determine which room a prospect belongs to.
     *
     */
    private function determine_prospect_room(array $prospect): string
    {
        // Check if handed off to sales
        if (!empty($prospect['sales_handoff_at'])) {
            return 'sales';
        }
        
        // Check current_room column
        if (!empty($prospect['current_room']) && in_array($prospect['current_room'], ['problem', 'solution', 'offer', 'sales'])) {
            return $prospect['current_room'];
        }

        // Get client_id from prospect data
        $client_id = isset($prospect['client_id']) ? (int) $prospect['client_id'] : null;
        
        // Get thresholds from database (client-specific or global)
        $thresholds = $this->get_room_thresholds($client_id);
        
        // Use lead score to determine room
        $score = (int) ($prospect['lead_score'] ?? 0);
        
        // Room assignment logic based on database thresholds
        if ($score >= $thresholds['offer_min']) {
            return 'offer';
        } elseif ($score > $thresholds['problem_max'] && $score <= $thresholds['solution_max']) {
            return 'solution';
        } elseif ($score >= 1 && $score <= $thresholds['problem_max']) {
            return 'problem';
        }
        
        return 'none';
    }


    /**
     * Update prospect contact information.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_prospect_contact(WP_REST_Request $request)
    {
        global $wpdb;
        
        $visitor_id = (int) $request->get_param('id');
        $contact_name = sanitize_text_field($request->get_param('contact_name'));
        $contact_email = sanitize_email($request->get_param('contact_email'));
        $job_title = sanitize_text_field($request->get_param('job_title'));

        // Validate email if provided
        if (!empty($contact_email) && !is_email($contact_email)) {
            return new WP_Error(
                'invalid_email',
                'Invalid email address provided',
                ['status' => 400]
            );
        }

        // Update cpd_visitors table
        $visitor_update = [];
        
        // Parse name into first_name and last_name
        $name_parts = explode(' ', $contact_name, 2);
        $visitor_update['first_name'] = $name_parts[0];
        $visitor_update['last_name'] = isset($name_parts[1]) ? $name_parts[1] : '';
        
        if (!empty($contact_email)) {
            $visitor_update['email'] = $contact_email;
        }
        
        if (!empty($job_title)) {
            $visitor_update['job_title'] = $job_title;
        }

        $visitor_updated = $wpdb->update(
            $wpdb->prefix . 'cpd_visitors',
            $visitor_update,
            ['id' => $visitor_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Update rtr_prospects table
        $prospect_update = [
            'contact_name' => $contact_name,
            'updated_at' => current_time('mysql')
        ];
        
        if (!empty($contact_email)) {
            $prospect_update['contact_email'] = $contact_email;
        }

        $prospect_updated = $wpdb->update(
            $wpdb->prefix . 'rtr_prospects',
            $prospect_update,
            ['visitor_id' => $visitor_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($visitor_updated === false || $prospect_updated === false) {
            return new WP_Error(
                'update_failed',
                'Failed to update contact information',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Contact information updated successfully',
            'data' => [
                'contact_name' => $contact_name,
                'contact_email' => $contact_email,
                'job_title' => $job_title
            ]
        ], 200);
    }

    /**
     * Search for contacts via A-Leads enrichment.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function search_contacts_enrichment(WP_REST_Request $request)
    {
        global $wpdb;
        
        $visitor_id = (int) $request->get_param('id');
        
        // Get visitor/prospect data
        $visitor = $wpdb->get_row($wpdb->prepare(
            "SELECT company_name, website FROM {$wpdb->prefix}cpd_visitors WHERE id = %d",
            $visitor_id
        ));
        
        if (!$visitor || empty($visitor->company_name)) {
            return new WP_Error(
                'no_company',
                'No company found for this prospect',
                ['status' => 404]
            );
        }
        
        // Search A-Leads
        require_once __DIR__ . '/class-aleads-enrichment.php';
        $enrichment = new \DirectReach\ReadingTheRoom\API\ALeads_Enrichment();
        
        $contacts = $enrichment->search_contacts($visitor->company_name);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'company_name' => $visitor->company_name,
                'contacts' => $contacts
            ]
        ], 200);
    }

    /**
     * Find email for a specific contact.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function find_email($request)
    {
        global $wpdb;
        
        $visitor_id = (int) $request['id'];
        $body = $request->get_json_params();
        
        try {
            // WORKFLOW 1: Enrichment Manager (has member_id from search)
            if (!empty($body['member_id'])) {
                $result = $this->enrichment->find_email_by_member_id(
                    $body['member_id'],
                    $body['first_name'],
                    $body['last_name'],
                    $body['company_domain']
                );
                
                if (!empty($result['email'])) {
                    $wpdb->update(
                        "{$wpdb->prefix}cpd_visitors",
                        ['email' => $result['email']],
                        ['id' => $visitor_id],
                        ['%s'], ['%d']
                    );
                    
                    $wpdb->update(
                        "{$wpdb->prefix}rtr_prospects",
                        ['contact_email' => $result['email']],
                        ['visitor_id' => $visitor_id],
                        ['%s'], ['%d']
                    );
                    
                    return new WP_REST_Response([
                        'success' => true,
                        'data' => [
                            'email' => $result['email'],
                            'confidence' => $result['confidence'] ?? null,
                            'source' => 'aleads'
                        ]
                    ], 200);
                }
                
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Email not found'
                ], 404);
            }
            
            // WORKFLOW 2: Prospect Info Modal
            $visitor = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cpd_visitors WHERE id = %d",
                $visitor_id
            ), ARRAY_A);
            
            if (!$visitor) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Visitor not found'
                ], 404);
            }
            
            if (!empty($visitor['email'])) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'email' => $visitor['email'],
                        'source' => 'existing'
                    ]
                ], 200);
            }
            
            if (empty($visitor['company_name'])) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Company name required'
                ], 400);
            }
            
            $contacts = $this->enrichment->search_contacts($visitor['company_name']);
            
            if (empty($contacts)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No contacts found'
                ], 404);
            }
            
            $matched_contact = $this->match_contact($visitor, $contacts);
            
            if (!$matched_contact || empty($matched_contact['member_id'])) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Email not found - try using Contact Search first'
                ], 404);
            }
            
            $result = $this->enrichment->find_email_by_member_id(
                $matched_contact['member_id'],
                $matched_contact['first_name'] ?? $visitor['first_name'],
                $matched_contact['last_name'] ?? $visitor['last_name'],
                $matched_contact['domain'] ?? ''
            );
            
            if (!empty($result['email'])) {
                $wpdb->update(
                    "{$wpdb->prefix}cpd_visitors",
                    ['email' => $result['email']],
                    ['id' => $visitor_id],
                    ['%s'], ['%d']
                );
                
                $wpdb->update(
                    "{$wpdb->prefix}rtr_prospects",
                    ['contact_email' => $result['email']],
                    ['visitor_id' => $visitor_id],
                    ['%s'], ['%d']
                );
                
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'email' => $result['email'],
                        'confidence' => $result['confidence'] ?? null,
                        'source' => 'aleads'
                    ]
                ], 200);
            }
            
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Email not found'
            ], 404);
            
        } catch (\Exception $e) {
            error_log('Find email error: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function match_contact($visitor, $contacts) {
        $first_name = $visitor['first_name'] ?? '';
        $last_name = $visitor['last_name'] ?? '';
        $job_title = $visitor['job_title'] ?? '';
        $linkedin_url = $visitor['linkedin_url'] ?? '';
        
        $matched_contact = null;
        $best_score = 0;
        
        foreach ($contacts as $contact) {
            $score = 0;
            
            // LinkedIn (100 pts)
            if (!empty($linkedin_url) && !empty($contact['linkedin'])) {
                $v_li = preg_replace('#^https?://(www\.)?linkedin\.com/in/#', '', strtolower(trim($linkedin_url)));
                $c_li = preg_replace('#^https?://(www\.)?linkedin\.com/in/#', '', strtolower(trim($contact['linkedin'])));
                if (rtrim($v_li, '/') === rtrim($c_li, '/')) {
                    $score += 100;
                }
            }
            
            // Name (50 pts)
            $first_match = !empty($first_name) && !empty($contact['first_name']) && 
                (stripos($contact['first_name'], $first_name) !== false || stripos($first_name, $contact['first_name']) !== false);
            $last_match = !empty($last_name) && !empty($contact['last_name']) && 
                (stripos($contact['last_name'], $last_name) !== false || stripos($last_name, $contact['last_name']) !== false);
            
            if ($first_match && $last_match) {
                $score += 50;
            } elseif ($first_match || $last_match) {
                $score += 25;
            }
            
            // Title (40 pts)
            if (!empty($job_title) && !empty($contact['job_title'])) {
                $score += $this->fuzzy_match_title($job_title, $contact['job_title']);
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $matched_contact = $contact;
            }
        }
        
        return ($best_score >= 50) ? $matched_contact : null;
    }

    private function fuzzy_match_title($t1, $t2) {
        $t1 = strtolower(trim($t1));
        $t2 = strtolower(trim($t2));
        
        if ($t1 === $t2) return 40;
        if (strpos($t1, $t2) !== false || strpos($t2, $t1) !== false) return 35;
        
        $words1 = array_filter(explode(' ', preg_replace('/\b(senior|junior|lead|vp|svp|the|of|and)\b/i', ' ', $t1)));
        $words2 = array_filter(explode(' ', preg_replace('/\b(senior|junior|lead|vp|svp|the|of|and)\b/i', ' ', $t2)));
        
        $matches = count(array_intersect($words1, $words2));
        $total = max(count($words1), count($words2));
        
        if ($total === 0) return 0;
        
        $overlap = $matches / $total;
        
        if ($overlap >= 0.7) return 30;
        if ($overlap >= 0.5) return 20;
        if ($overlap >= 0.3) return 10;
        
        return 0;
    }

    /**
     * Verify email address for a prospect
     */
    public function verify_email( WP_REST_Request $request ) {
        try {
            error_log('[RTR] Verify email endpoint called');
            
            // Get email from request body
            $email = $request->get_param('email');
            
            // Also try to get from JSON body if not in params
            if (empty($email)) {
                $body = $request->get_json_params();
                $email = isset($body['email']) ? $body['email'] : '';
            }
            
            error_log('[RTR] Email parameter: ' . print_r($email, true));
            
            // Validate email parameter
            if (empty($email) || !is_string($email)) {
                error_log('[RTR] Invalid email parameter');
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Valid email is required'
                ], 400);
            }
            
            // Sanitize email
            $email = sanitize_email($email);
            if (!is_email($email)) {
                error_log('[RTR] Invalid email format: ' . $email);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Invalid email format'
                ], 400);
            }

            error_log('[RTR] Verifying email: ' . $email);

            // Initialize enrichment class if not already done
            if (!$this->enrichment) {
                $this->enrichment = new ALeads_Enrichment();
            }

            // Call the enrichment verify method
            $visitor_id = (int) $request['id'];
            $result = $this->enrichment->verify_email($email, $visitor_id);

            error_log('[RTR] Verification result: ' . print_r($result, true));

            if ($result['success']) {
                return new WP_REST_Response($result, 200);
            } else {
                return new WP_REST_Response($result, 400);
            }

        } catch (Exception $e) {
            error_log('[RTR] Verify email error: ' . $e->getMessage());
            error_log('[RTR] Stack trace: ' . $e->getTraceAsString());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save enriched contact information.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function save_enriched_contact(WP_REST_Request $request)
    {
        global $wpdb;
        
        $visitor_id = (int) $request->get_param('id');
        $contact_name = sanitize_text_field($request->get_param('contact_name'));
        $contact_email = sanitize_email($request->get_param('contact_email'));
        $job_title = sanitize_text_field($request->get_param('job_title'));
        $company_name = sanitize_text_field($request->get_param('company_name'));
        $linkedin_url = esc_url_raw($request->get_param('linkedin_url'));
        $aleads_member_id = !empty($body['aleads_member_id']) ? sanitize_text_field($body['aleads_member_id']) : null;

        // Validate email if provided
        if (!empty($contact_email) && !is_email($contact_email)) {
            return new WP_Error(
                'invalid_email',
                'Invalid email address provided',
                ['status' => 400]
            );
        }

        // Update cpd_visitors table (has all fields)
        $visitor_update = [];
        
        // Parse name into first_name and last_name
        $name_parts = explode(' ', $contact_name, 2);
        $visitor_update['first_name'] = $name_parts[0];
        $visitor_update['last_name'] = isset($name_parts[1]) ? $name_parts[1] : '';
        
        if (!empty($contact_email)) {
            $visitor_update['email'] = $contact_email;
        }
        
        if (!empty($job_title)) {
            $visitor_update['job_title'] = $job_title;
        }

        if (!empty($company_name)) {
            $visitor_update['company_name'] = $company_name;
        }

        if (!empty($linkedin_url)) {
            $visitor_update['linkedin_url'] = $linkedin_url;
        }

        $format = array_fill(0, count($visitor_update), '%s');
        
        $visitor_updated = $wpdb->update(
            $wpdb->prefix . 'cpd_visitors',
            $visitor_update,
            ['id' => $visitor_id],
            $format,
            ['%d']
        );

        // Update rtr_prospects table (only has contact_name, contact_email, company_name)
        $prospect_update = [
            'contact_name' => $contact_name,
            'updated_at' => current_time('mysql')
        ];
        
        if (!empty($contact_email)) {
            $prospect_update['contact_email'] = $contact_email;
        }

        if (!empty($company_name)) {
            $prospect_update['company_name'] = $company_name;
        }

        if (!empty($job_title)) {
            $prospect_update['job_title'] = $job_title;
        }

        if (!empty($aleads_member_id)) {
            $prospect_update['aleads_member_id'] = $aleads_member_id;
        }

        $prospect_format = array_fill(0, count($prospect_update), '%s');

        $prospect_updated = $wpdb->update(
            $wpdb->prefix . 'rtr_prospects',
            $prospect_update,
            ['visitor_id' => $visitor_id],
            $prospect_format,
            ['%d']
        );

        if ($visitor_updated === false || $prospect_updated === false) {
            return new WP_Error(
                'update_failed',
                'Failed to update contact information',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Contact information saved successfully',
            'data' => [
                'contact_name' => $contact_name,
                'contact_email' => $contact_email,
                'job_title' => $job_title,
                'company_name' => $company_name,
                'linkedin_url' => $linkedin_url
            ]
        ], 200);
    }

    /**
     * Helper: Get email statistics for a room.
     */
    private function get_email_stats_for_room(string $room, array $filters): array
    {
        // Get prospects in this room
        $prospects = $this->db->get_prospects($filters);
        $room_prospect_ids = [];
        
        foreach ($prospects as $p) {
            if ($this->determine_prospect_room($p) === $room) {
                $room_prospect_ids[] = (int) $p['id'];
            }
        }

        if (empty($room_prospect_ids)) {
            return ['sent' => 0, 'opened' => 0, 'clicked' => 0];
        }

        // Query analytics for email events
        global $wpdb;
        $tables = $this->db->tables();
        $analytics_table = $tables['analytics'] ?? '';
        
        if (empty($analytics_table)) {
            return ['sent' => 0, 'opened' => 0, 'clicked' => 0];
        }

        $ids_str = implode(',', $room_prospect_ids);
        
        $sent = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT prospect_id) 
            FROM {$analytics_table} 
            WHERE prospect_id IN ({$ids_str}) 
            AND event_key = 'email_sent'
        ");

        $opened = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT prospect_id) 
            FROM {$analytics_table} 
            WHERE prospect_id IN ({$ids_str}) 
            AND event_key = 'email_opened'
        ");

        $clicked = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT prospect_id) 
            FROM {$analytics_table} 
            WHERE prospect_id IN ({$ids_str}) 
            AND event_key = 'email_clicked'
        ");

        return [
            'sent'    => $sent,
            'opened'  => $opened,
            'clicked' => $clicked,
        ];
    }

/**
     * Count prospects created this week.
     */
    private function count_prospects_this_week(array $prospects): int
    {
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $count = 0;
        
        foreach ($prospects as $p) {
            $created = substr($p['created_at'] ?? '', 0, 10);
            if ($created >= $week_start) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Calculate average days to close for sales.
     */
    private function calculate_avg_days_to_close(array $prospects): float
    {
        if (empty($prospects)) {
            return 0;
        }
        
        $total_days = 0;
        $count = 0;
        
        foreach ($prospects as $p) {
            if (!empty($p['created_at']) && !empty($p['updated_at'])) {
                $created = strtotime($p['created_at']);
                $updated = strtotime($p['updated_at']);
                $days = round(($updated - $created) / 86400, 1);
                
                if ($days > 0) {
                    $total_days += $days;
                    $count++;
                }
            }
        }
        
        return $count > 0 ? round($total_days / $count, 1) : 0;
    }

    /**
     * Get email states for a prospect from tracking table.
     *
     * @param int    $prospect_id Prospect ID
     * @param string $room_type   Current room type
     * @return array Email states for 5 emails
     */
    private function get_email_states(int $prospect_id, string $room_type): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rtr_email_tracking';
        
        $email_states = [];
        
        // Query tracking status for each of 5 emails
        for ($i = 1; $i <= 5; $i++) {
            $tracking = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    id,
                    status,
                    copied_at,
                    opened_at,
                    created_at
                FROM {$table}
                WHERE prospect_id = %d 
                AND room_type = %s
                AND email_number = %d
                ORDER BY created_at DESC
                LIMIT 1",
                $prospect_id,
                $room_type,
                $i
            ));
            
            if ($tracking) {
                // Determine state priority: opened > sent > ready > generating > failed > pending
                $state = 'pending';
                $timestamp = null;
                
                if (!empty($tracking->opened_at)) {
                    $state = 'opened';
                    $timestamp = $tracking->opened_at;
                } elseif (!empty($tracking->copied_at)) {
                    $state = 'sent';
                    $timestamp = $tracking->copied_at;
                } elseif ($tracking->status === 'completed' || $tracking->status === 'generated') {
                    $state = 'ready';
                    $timestamp = $tracking->created_at;
                } elseif ($tracking->status === 'generating' || $tracking->status === 'pending_generation') {
                    $state = 'generating';
                    $timestamp = $tracking->created_at;
                } elseif ($tracking->status === 'failed' || $tracking->status === 'error') {
                    $state = 'failed';
                    $timestamp = $tracking->created_at;
                }
                
                $email_states["email_$i"] = [
                    'state' => $state,
                    'timestamp' => $timestamp
                ];
            } else {
                // No tracking record = pending state
                $email_states["email_$i"] = [
                    'state' => 'pending',
                    'timestamp' => null
                ];
            }
        }
        
        return $email_states;
    }

    /**
     * Permission check.
     */
    public function check_permission(WP_REST_Request $request = null): bool|WP_Error
    {
        // Allow cookie-based auth (WordPress admin)
        if (current_user_can('edit_posts')) {
            return true;
        }

        // Allow X-API-Key auth (external systems like CIS)
        if ($request) {
            $api_key = $request->get_header('X-API-Key');
            if (!empty($api_key)) {
                $stored_key = get_option('cpd_api_key');
                if (!empty($stored_key) && $api_key === $stored_key) {
                    return true;
                }
            }
        }

        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to access this endpoint.',
            ['status' => 403]
        );
    }
}