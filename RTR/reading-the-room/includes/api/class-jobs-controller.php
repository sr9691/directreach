<?php
/**
 * Jobs Controller (FIXED)
 *
 * REST API controller for automated job operations.
 * Handles nightly jobs, campaign matching, prospect creation, and room assignments.
  *
 * @package DirectReach
 * @subpackage ReadingTheRoom
 * @since 2.0.0
 * @version 2.5.0 - FIXED VERSION
 */

namespace DirectReach\ReadingTheRoom\API;

// FIXED: Added proper namespace imports
use DirectReach\ReadingTheRoom\Campaign_Matcher;
use DirectReach\ReadingTheRoom\Reading_Room_Database;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Jobs REST API Controller
 * FIXED: Added mode support for full recalculation
 */
class Jobs_Controller extends WP_REST_Controller {

    // FIXED: Added class constants for magic values
    private const LOG_PREFIX = '[RTR]';
    private const LOG_PREFIX_JOB = '[RTR JOB';
    
    private const MODE_INCREMENTAL = 'incremental';
    private const MODE_FULL = 'full';
    private const MODE_CLIENT = 'client';
    
    private const ROOM_HIERARCHY = [
        'none'     => 0,
        'problem'  => 1,
        'solution' => 2,
        'offer'    => 3,
    ];
    
    private const CACHE_TTL = 300; // 5 minutes
    
    /**
     * Namespace for REST routes
     *
     * @var string
     */
    protected $namespace = 'directreach/v2';

    /**
     * Database instance
     *
     * @var Reading_Room_Database
     */
    private Reading_Room_Database $db;

    /**
     * Campaign matcher instance
     *
     * @var Campaign_Matcher|null
     */
    private ?Campaign_Matcher $campaign_matcher = null;

    /**
     * Job start time
     *
     * @var float
     */
    private float $job_start_time;

    /**
     * Current job mode
     * FIXED: Added to track mode across internal methods
     *
     * @var string
     */
    private string $current_mode = self::MODE_INCREMENTAL;

    /**
     * Job statistics
     *
     * @var array<string,int>
     */
    private array $job_stats = [
        'campaigns_matched'   => 0,
        'prospects_created'   => 0,
        'prospects_updated'   => 0,
        'prospects_skipped'   => 0,
        'room_transitions'    => 0,
        'room_transitions_delayed' => 0,
        'scores_calculated'   => 0,
        'errors'              => 0,
        'visitors_processed'  => 0,
    ];

    /**
     * Scoring rules cache
     *
     * @var array<int,array>
     */
    private array $scoring_rules_cache = [];

    /**
     * Room thresholds cache with timestamps
     *
     * @var array<int,array>
     */
    private array $thresholds_cache = [];
    
    /**
     * Cache timestamps for TTL management
     *
     * @var array<int,int>
     */
    private array $cache_timestamps = [];

    /**
     * Constructor with dependency injection
     * 
     * @param Reading_Room_Database|null $db Database instance (required)
     * @throws \RuntimeException If database instance is invalid
     */
    public function __construct(?Reading_Room_Database $db = null) {
        if (!$db instanceof Reading_Room_Database) {
            global $wpdb;
            if (isset($wpdb) && $wpdb instanceof \wpdb) {
                $db = new Reading_Room_Database($wpdb);
            } else {
                throw new \RuntimeException('Database instance required for Jobs_Controller');
            }
        }
        
        $this->db = $db;
        
        if (class_exists(Campaign_Matcher::class)) {
            $this->campaign_matcher = new Campaign_Matcher($this->db);
        }
        
        error_log(self::LOG_PREFIX . ' Jobs_Controller instantiated');
    }

    /**
     * Register REST API routes
     * 
     * @return void
     */
    public function register_routes(): void {
        error_log(self::LOG_PREFIX . ' Registering Jobs_Controller routes...');

        // POST /jobs/run-nightly - Main nightly job (all operations)
        register_rest_route($this->namespace, '/jobs/run-nightly', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'run_nightly_job'],
                'permission_callback' => [$this, 'check_api_key_or_admin'],
                'args'                => [
                    'mode' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => [self::MODE_INCREMENTAL, self::MODE_FULL, self::MODE_CLIENT],
                        'default'           => self::MODE_INCREMENTAL,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'force_full' => [
                        'required'          => false,
                        'type'              => 'boolean',
                        'default'           => false,
                    ],
                    'client_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // POST /jobs/match-campaigns - Campaign attribution (Phase 3)
        register_rest_route($this->namespace, '/jobs/match-campaigns', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'match_campaigns'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => [
                    'mode' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => [self::MODE_INCREMENTAL, self::MODE_FULL],
                        'default'           => self::MODE_INCREMENTAL,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // POST /jobs/create-prospects - Create prospect records
        register_rest_route($this->namespace, '/jobs/create-prospects', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_prospects'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => [
                    'client_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'mode' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => [self::MODE_INCREMENTAL, self::MODE_FULL],
                        'default'           => self::MODE_INCREMENTAL,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // POST /jobs/calculate-scores - Calculate visitor scores
        register_rest_route($this->namespace, '/jobs/calculate-scores', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'calculate_scores'],
                'permission_callback' => [$this, 'check_api_key'],
                'args'                => [
                    'mode' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => [self::MODE_INCREMENTAL, self::MODE_FULL],
                        'default'           => self::MODE_INCREMENTAL,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // POST /jobs/assign-rooms - Assign rooms based on scores
        register_rest_route($this->namespace, '/jobs/assign-rooms', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'assign_rooms'],
                'permission_callback' => [$this, 'check_api_key'],
            ],
        ]);

        // GET /calculate-score - Get score breakdown for a single visitor or prospect
        register_rest_route($this->namespace, '/calculate-score', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_score_breakdown'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'visitor_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'prospect_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'client_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        },
                    ],
                ],
            ],
        ]);        

        error_log(self::LOG_PREFIX . ' Jobs_Controller routes registered successfully');
    }

    /**
     * Run nightly job (all operations in sequence)
     * FIXED: Now passes mode to all internal methods
     * ADDED: client_id parameter for client-specific processing
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response with stats or error
     */
    public function run_nightly_job($request): WP_REST_Response|WP_Error {
        $this->job_start_time = microtime(true);
        
        // Get mode from request, check force_full parameter
        $mode = $request->get_param('mode') ?: self::MODE_INCREMENTAL;
        $force_full = $request->get_param('force_full');
        $client_id = $request->get_param('client_id');
        
        // Override mode if force_full is true
        if ($force_full === true || $force_full === 'true' || $force_full === '1') {
            $mode = self::MODE_FULL;
        }
        
        // If client_id is provided, set mode to client
        if ($client_id) {
            $mode = self::MODE_CLIENT;
        }
        
        // FIXED: Store mode for access by internal methods
        $this->current_mode = $mode;
        
        // Log comprehensive job start with system state
        global $wpdb;
        
        // Validate client exists if client_id provided
        if ($client_id) {
            $client_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cpd_clients WHERE id = %d AND subscription_tier = 'premium'",
                $client_id
            ));
            
            if (!$client_exists) {
                return new WP_Error(
                    'invalid_client',
                    'Client not found or not premium',
                    ['status' => 404]
                );
            }
        }
        
        $system_stats = [
            'total_visitors' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rtr_prospects"),
            'visitors_with_campaigns' => $wpdb->get_var("SELECT COUNT(DISTINCT visitor_id) FROM {$wpdb->prefix}cpd_visitor_campaigns"),
            'visitors_with_scores' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cpd_visitors WHERE lead_score > 0"),
            'existing_prospects' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rtr_prospects WHERE archived_at IS NULL"),
            'active_campaigns' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dr_campaign_settings WHERE end_date > CURDATE()"),
        ];
        
        $this->log_job('nightly_job_start', sprintf(
            'Starting nightly job in %s mode%s at %s. System state: %s. Parameters: %s',
            $mode,
            $client_id ? " for client {$client_id}" : '',
            current_time('mysql'),
            json_encode($system_stats),
            json_encode($request->get_params())
        ));

        try {
            // Step 1: Match campaigns (Phase 3)
            $this->log_job('step_1_start', 'Starting campaign matching...');
            $match_result = $this->match_campaigns_internal($mode, $client_id);
            $this->job_stats['campaigns_matched'] = $match_result['matched'] ?? 0;
            $this->log_job('step_1_complete', sprintf(
                'Campaign matching complete. Matched: %d visitors to campaigns, Skipped: %d',
                $match_result['matched'] ?? 0,
                $match_result['skipped'] ?? 0
            ));

            // Step 2: Calculate scores (Scoring System)
            // FIXED: Pass mode to calculate_scores_internal
            $this->log_job('step_2_start', sprintf('Starting score calculation in %s mode...', $mode));
            $score_result = $this->calculate_scores_internal($mode, $client_id);
            $this->job_stats['scores_calculated'] = $score_result['calculated'] ?? 0;
            $this->log_job('step_2_complete', sprintf(
                'Score calculation complete. Calculated: %d scores out of %d visitors',
                $score_result['calculated'] ?? 0,
                $score_result['total'] ?? 0
            ));

            // Step 3: Create/update prospects
            // FIXED: Pass mode to create_prospects_internal
            $this->log_job('step_3_start', sprintf('Starting prospect creation/update in %s mode...', $mode));
            $prospect_result = $this->create_prospects_internal($client_id, $mode);
            $this->job_stats['prospects_created'] = $prospect_result['created'] ?? 0;
            $this->job_stats['prospects_updated'] = $prospect_result['updated'] ?? 0;
            $this->job_stats['prospects_skipped'] = $prospect_result['skipped'] ?? 0;
            $this->log_job('step_3_complete', sprintf(
                'Prospect creation/update complete. Created: %d, Updated: %d, Skipped: %d',
                $prospect_result['created'] ?? 0,
                $prospect_result['updated'] ?? 0,
                $prospect_result['skipped'] ?? 0
            ));

            // Step 4: Assign rooms
            $this->log_job('step_4_start', 'Starting room assignments...');
            $room_result = $this->assign_rooms_internal($client_id);
            $this->job_stats['room_transitions'] = $room_result['transitions'] ?? 0;
            $this->job_stats['room_transitions_delayed'] = $room_result['delayed'] ?? 0;
            $this->log_job('step_4_complete', sprintf(
                'Room assignment complete. Transitions: %d, Delayed: %d, Total: %d',
                $room_result['transitions'] ?? 0,
                $room_result['delayed'] ?? 0,
                $room_result['total'] ?? 0
            ));

            // Calculate job duration
            $duration = round(microtime(true) - $this->job_start_time, 2);

            // Add final system state snapshot
            $final_stats = [
                'total_visitors' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cpd_visitors"),
                'visitors_with_campaigns' => $wpdb->get_var("SELECT COUNT(DISTINCT visitor_id) FROM {$wpdb->prefix}cpd_visitor_campaigns"),
                'visitors_with_scores' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cpd_visitors WHERE lead_score > 0"),
                'existing_prospects' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rtr_prospects WHERE archived_at IS NULL"),
            ];

            $this->log_job('nightly_job_complete', sprintf(
                'Nightly job completed in %s seconds. Final state: %s. Stats: %s',
                $duration,
                json_encode($final_stats),
                json_encode($this->job_stats)
            ));

            return new WP_REST_Response([
                'success'   => true,
                'duration'  => $duration,
                'stats'     => $this->job_stats,
                'mode'      => $mode,
                'client_id' => $client_id,
            ], 200);

        } catch (\Exception $e) {
            $this->job_stats['errors']++;
            
            $this->log_job('nightly_job_error', sprintf(
                'Fatal error in nightly job: %s. Stack trace: %s',
                $e->getMessage(),
                $e->getTraceAsString()
            ), 'error');

            return new WP_Error(
                'job_failed',
                'Nightly job failed: ' . $e->getMessage(),
                ['status' => 500, 'stats' => $this->job_stats]
            );
        }
    }

    /**
     * Match campaigns to visitors
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response with match stats or error
     */
    public function match_campaigns($request): WP_REST_Response|WP_Error {
        $mode = $request->get_param('mode') ?: self::MODE_INCREMENTAL;

        try {
            $result = $this->match_campaigns_internal($mode);

            return new WP_REST_Response([
                'success' => true,
                'mode'    => $mode,
                'matched' => $result['matched'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'campaign_match_failed',
                'Campaign matching failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Create prospects from visitors
     * FIXED: Added mode parameter
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response with creation stats or error
     */
    public function create_prospects($request): WP_REST_Response|WP_Error {
        $client_id = $request->get_param('client_id');
        $mode = $request->get_param('mode') ?: self::MODE_INCREMENTAL;

        try {
            $result = $this->create_prospects_internal($client_id, $mode);

            return new WP_REST_Response([
                'success' => true,
                'mode'    => $mode,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'prospect_creation_failed',
                'Prospect creation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Calculate visitor scores
     * FIXED: Added mode parameter
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response with calculation stats or error
     */
    public function calculate_scores($request): WP_REST_Response|WP_Error {
        $mode = $request->get_param('mode') ?: self::MODE_INCREMENTAL;
        
        try {
            $result = $this->calculate_scores_internal($mode);

            return new WP_REST_Response([
                'success'    => true,
                'mode'       => $mode,
                'calculated' => $result['calculated'] ?? 0,
                'total'      => $result['total'] ?? 0,
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'score_calculation_failed',
                'Score calculation failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Assign rooms based on scores
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response with assignment stats or error
     */
    public function assign_rooms($request): WP_REST_Response|WP_Error {
        try {
            $result = $this->assign_rooms_internal();

            return new WP_REST_Response([
                'success'     => true,
                'transitions' => $result['transitions'] ?? 0,
                'delayed'     => $result['delayed'] ?? 0,
                'total'       => $result['total'] ?? 0,
            ], 200);

        } catch (\Exception $e) {
            return new WP_Error(
                'room_assignment_failed',
                'Room assignment failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /* =========================================================================
     * INTERNAL JOB METHODS
     * ======================================================================= */

    /**
     * Internal campaign matching logic
     *
     * @param string $mode Processing mode (incremental, full, or client).
     * @param int|null $client_id Optional client filter for client mode.
     * @return array{matched: int, skipped: int, total: int} Results.
     */
    private function match_campaigns_internal(string $mode = self::MODE_INCREMENTAL, ?int $client_id = null): array {
        if (!$this->campaign_matcher) {
            $this->log_job('campaign_match_error', 'Campaign matcher not initialized', 'error');
            throw new \Exception('Campaign matcher not available');
        }

        $matched = 0;
        $skipped = 0;

        global $wpdb;

        // Build client filter
        $client_where = '';
        if ($client_id) {
            $client_where = $wpdb->prepare(' AND cs.client_id = %d', $client_id);
        }

        // Get only PREMIUM CLIENT campaigns
        $premium_campaign_ids = $wpdb->get_col("
            SELECT cs.id 
            FROM {$wpdb->prefix}dr_campaign_settings cs
            INNER JOIN {$wpdb->prefix}cpd_clients cl ON cs.client_id = cl.id
            WHERE cl.subscription_tier = 'premium'
            AND cl.rtr_enabled = 1
            AND (cs.start_date IS NULL OR cs.start_date <= CURDATE())
            AND (cs.end_date IS NULL OR cs.end_date >= CURDATE())
            {$client_where}
        ");

        if (empty($premium_campaign_ids)) {
            $this->log_job('campaign_match_skip', 'No premium client campaigns found - skipping campaign matching');
            return [
                'matched' => 0,
                'skipped' => 0,
                'total' => 0,
            ];
        }

        $this->log_job('campaign_match_info', sprintf(
            'Found %d premium client campaigns: %s',
            count($premium_campaign_ids),
            implode(', ', $premium_campaign_ids)
        ));

        // Get unmatched visitors (those without campaign assignments)
        $where_clause = $mode === self::MODE_FULL
            ? "" 
            : "WHERE v.id NOT IN (SELECT visitor_id FROM {$wpdb->prefix}cpd_visitor_campaigns)";

        $visitors = $wpdb->get_results("
            SELECT v.* 
            FROM {$wpdb->prefix}cpd_visitors v
            {$where_clause}
            ORDER BY v.last_seen_at DESC
        ");

        $this->log_job('campaign_match_batch_start', sprintf(
            'Starting %s campaign matching for %d visitors (filtering for %d premium campaigns)',
            $mode,
            count($visitors),
            count($premium_campaign_ids)
        ));

        foreach ($visitors as $visitor) {
            try {
                $match = $this->campaign_matcher->match([
                    'visitor_id' => (int) $visitor->id
                ]);

                if ($match !== null && in_array((int)$match['id'], array_map('intval', $premium_campaign_ids))) {
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$wpdb->prefix}cpd_visitor_campaigns 
                        (visitor_id, campaign_id) VALUES (%d, %d)",
                        $visitor->id,
                        $match['id']
                    ));
                    $matched++;
                    
                    if ($matched <= 3) {
                        $this->log_job('campaign_match_success', sprintf(
                            'Visitor %d matched to premium campaign %d via %s',
                            $visitor->id,
                            $match['id'],
                            $match['match_method'] ?? 'unknown'
                        ));
                    }
                } else {
                    $skipped++;
                }

            } catch (\Exception $e) {
                $this->job_stats['errors']++;
                $this->log_job('campaign_match_visitor_error', sprintf(
                    'Campaign matching failed for visitor %d: %s',
                    $visitor->id,
                    $e->getMessage()
                ), 'error');
            }
        }

        $this->log_job('campaign_match_complete', sprintf(
            'Campaign matching complete. Matched: %d, Skipped: %d (not premium or no match)',
            $matched,
            $skipped
        ));

        return [
            'matched' => $matched,
            'skipped' => $skipped,
            'total'   => count($visitors),
        ];
    }

    /**
     * Internal score calculation logic
     * FIXED: Added mode parameter - full mode recalculates ALL visitors
     *
     * @param string $mode Processing mode (incremental, full, or client).
     * @param int|null $client_id Optional client filter for client mode.
     * @return array{calculated: int, total: int} Results.
     */
    private function calculate_scores_internal(string $mode = self::MODE_INCREMENTAL, ?int $client_id = null): array {
        global $wpdb;

        $calculated = 0;
        $failed = 0;
        $total = 0;

        // Check if RTR_Score_Calculator is available
        if (!class_exists('\RTR_Score_Calculator')) {
            $this->log_job('score_calculator_unavailable', 
                'RTR_Score_Calculator class not found. Scoring system module may not be loaded.', 
                'error'
            );
            return [
                'calculated' => 0,
                'total' => 0,
            ];
        }

        // Build client filter
        $client_where = '';
        if ($client_id) {
            $client_where = $wpdb->prepare(' AND cs.client_id = %d', $client_id);
        }

        // FIXED: Build WHERE clause based on mode
        if ($mode === self::MODE_FULL || $mode === self::MODE_CLIENT) {
            // Full/Client mode: recalculate ALL visitors (for the client if specified)
            $where_conditions = "1=1";
            $this->log_job('score_calculation_mode', sprintf(
                '%s mode: Will recalculate ALL visitor scores%s',
                strtoupper($mode),
                $client_id ? " for client {$client_id}" : ''
            ));
        } else {
            // Incremental mode: Only recalculate visitors that need it
            $where_conditions = "(v.lead_score IS NULL 
                OR v.lead_score = 0
                OR v.score_calculated_at IS NULL
                OR v.last_seen_at > v.score_calculated_at
                OR v.score_calculated_at < DATE_SUB(NOW(), INTERVAL 7 DAY))";
        }

        // Get visitors with campaign assignments that need scoring
        $visitors = $wpdb->get_results("
            SELECT DISTINCT v.id, v.visitor_id, vc.campaign_id, cs.client_id
            FROM {$wpdb->prefix}cpd_visitors v
            INNER JOIN {$wpdb->prefix}cpd_visitor_campaigns vc ON v.id = vc.visitor_id
            INNER JOIN {$wpdb->prefix}dr_campaign_settings cs ON vc.campaign_id = cs.id
            INNER JOIN {$wpdb->prefix}cpd_clients cl ON cs.client_id = cl.id
            WHERE {$where_conditions}
            AND cl.subscription_tier = 'premium'
            AND cl.rtr_enabled = 1
            {$client_where}
        ");

        $total = count($visitors);

        if ($total === 0) {
            $this->log_job('score_calculation_none', 'No visitors need score calculation');
            return [
                'calculated' => 0,
                'total' => 0,
            ];
        }

        $this->log_job('score_calculation_start', sprintf(
            'Starting %s score calculation for %d visitors',
            $mode,
            $total
        ));

        // Instantiate the score calculator
        try {
            $score_calculator = new \RTR_Score_Calculator();
        } catch (\Exception $e) {
            $this->log_job('score_calculator_init_failed', 
                'Failed to initialize RTR_Score_Calculator: ' . $e->getMessage(), 
                'error'
            );
            return [
                'calculated' => 0,
                'total' => $total,
            ];
        }

        foreach ($visitors as $visitor) {
            try {
                $client_id = (int) $visitor->client_id;
                $visitor_id = (int) $visitor->id;
                
                // Suppress errors and warnings from Score Calculator
                $old_error_level = error_reporting();
                error_reporting(0);
                
                // Use the Score Calculator to calculate and cache score
                $score_data = @$score_calculator->calculate_visitor_score($visitor_id, $client_id, true);
                
                // Restore error reporting
                error_reporting($old_error_level);
                
                if ($score_data !== false && isset($score_data['total_score'])) {
                    $calculated++;
                    $this->job_stats['scores_calculated']++;
                    
                    // Log first 3 calculations for debugging
                    if ($calculated <= 3) {
                        error_log(sprintf(
                            '[RTR Nightly Job] Calculated score %d for visitor_id: %d (RB2B: %s), client_id: %d',
                            $score_data['total_score'],
                            $visitor_id,
                            $visitor->visitor_id ?? 'unknown',
                            $client_id
                        ));
                    }
                } else {
                    $failed++;
                    
                    if ($failed <= 3) {
                        error_log(sprintf(
                            '[RTR Nightly Job] Failed to calculate score for visitor_id: %d (RB2B: %s), client_id: %d',
                            $visitor_id,
                            $visitor->visitor_id ?? 'unknown',
                            $client_id
                        ));
                    }
                }

            } catch (\Error $e) {
                $failed++;
                $this->job_stats['errors']++;
                
                if ($failed <= 3) {
                    $this->log_job('score_calculation_error', sprintf(
                        'Fatal error calculating score for visitor %d: %s',
                        $visitor->id,
                        $e->getMessage()
                    ), 'error');
                }
            } catch (\Exception $e) {
                $failed++;
                $this->job_stats['errors']++;
                
                if ($failed <= 3) {
                    $this->log_job('score_calculation_exception', sprintf(
                        'Exception calculating score for visitor %d: %s',
                        $visitor->id,
                        $e->getMessage()
                    ), 'error');
                }
            }
        }

        $this->log_job('score_calculation_complete', sprintf(
            'Score calculation finished. Success: %d, Failed: %d, Total: %d',
            $calculated,
            $failed,
            $total
        ));

        return [
            'calculated' => $calculated,
            'total' => $total,
        ];
    }


    /**
     * Internal prospect creation logic
     * FIXED: Added mode parameter - full mode updates ALL prospects
     *
     * @param int|null $client_id Optional client filter.
     * @param string $mode Processing mode (incremental or full).
     * @return array{created: int, updated: int, skipped: int, total: int} Results.
     */
    private function create_prospects_internal(?int $client_id = null, string $mode = self::MODE_INCREMENTAL): array {
        global $wpdb;

        $created = 0;
        $updated = 0;
        $skipped = 0;

        // Log the mode
        $this->log_job('prospect_creation_mode', sprintf(
            'Prospect creation running in %s mode (full mode = update all, incremental = update if diff >= 1)',
            $mode
        ));

        // Build WHERE clause for client filtering
        $where_client = '';
        if ($client_id) {
            $where_client = $wpdb->prepare('AND cs.client_id = %d', $client_id);
        }

        // Get visitors with campaign matches - GROUP BY ensures one per visitor
        $visitors = $wpdb->get_results("
            SELECT v.*, vc.campaign_id, cs.client_id
            FROM {$wpdb->prefix}cpd_visitors v
            INNER JOIN {$wpdb->prefix}cpd_visitor_campaigns vc ON v.id = vc.visitor_id
            INNER JOIN {$wpdb->prefix}dr_campaign_settings cs ON vc.campaign_id = cs.id
            INNER JOIN {$wpdb->prefix}cpd_clients cl ON cs.client_id = cl.id
            WHERE v.lead_score > 0
            AND cl.subscription_tier = 'premium'
            AND cl.rtr_enabled = 1
            {$where_client}
            ORDER BY v.lead_score DESC, v.last_seen_at DESC
        ");

        $this->log_job('prospect_creation_batch_start', sprintf(
            'Starting prospect creation/update for %d eligible visitors in %s mode',
            count($visitors),
            $mode
        ));

        foreach ($visitors as $visitor) {
            try {
                $existing = $wpdb->get_row($wpdb->prepare("
                    SELECT *
                    FROM {$wpdb->prefix}rtr_prospects
                    WHERE visitor_id = %d
                    AND campaign_id = %d
                    LIMIT 1
                ", $visitor->id, $visitor->campaign_id));

                if (!$existing) {
                    // Create new prospect
                    $thresholds = $this->get_room_thresholds($visitor->campaign_id);
                    $initial_room = $this->calculate_room_assignment($visitor->lead_score ?? 0, $thresholds);

                    $wpdb->insert(
                        $wpdb->prefix . 'rtr_prospects',
                        [
                            'visitor_id'              => $visitor->id,
                            'campaign_id'             => $visitor->campaign_id,
                            'contact_email'           => $visitor->email ?? '',
                            'company_name'            => $visitor->company_name ?? '',
                            'contact_name'            => trim(($visitor->first_name ?? '') . ' ' . ($visitor->last_name ?? '')),
                            'job_title'               => $visitor->job_title ?? '',
                            'lead_score'              => $visitor->lead_score ?? 0,
                            'current_room'            => $initial_room,
                            'days_in_room'            => 0,
                            'email_sequence_position' => 0,
                            'created_at'              => current_time('mysql'),
                            'updated_at'              => current_time('mysql'),
                        ],
                        ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s']
                    );

                    $created++;

                    /*
                    $this->log_job('prospect_created', sprintf(
                        'Created prospect for visitor %d (campaign: %d, room: %s, score: %d)',
                        $visitor->id,
                        $visitor->campaign_id,
                        $initial_room,
                        $visitor->lead_score ?? 0
                    ));
                    */

                } else {
                    // Update existing prospect
                    $visitor_score = $visitor->lead_score ?? 0;
                    $existing_score = $existing->lead_score ?? 0;
                    $score_diff = abs($visitor_score - $existing_score);
                    
                    // FIXED: In full mode, ALWAYS update. In incremental, only if diff >= 5
                    $should_update = ($mode === self::MODE_FULL) || ($score_diff >= 1);
                    
                    if ($should_update) {
                        $wpdb->update(
                            $wpdb->prefix . 'rtr_prospects',
                            [
                                'lead_score' => $visitor_score,
                                'updated_at' => current_time('mysql')
                            ],
                            ['id' => $existing->id],
                            ['%d', '%s'],
                            ['%d']
                        );
                        
                        $updated++;
                        
                        // Log updates in full mode
                        if ($mode === self::MODE_FULL && $updated <= 5) {
                            $this->log_job('prospect_updated', sprintf(
                                'Updated prospect %d (visitor: %d, old score: %d, new score: %d, diff: %d)',
                                $existing->id,
                                $visitor->id,
                                $existing_score,
                                $visitor_score,
                                $score_diff
                            ));
                        } elseif ($mode !== self::MODE_FULL) {
                            $this->log_job('prospect_updated', sprintf(
                                'Updated prospect %d (visitor: %d, new score: %d)',
                                $existing->id,
                                $visitor->id,
                                $visitor_score
                            ));
                        }
                    } else {
                        $skipped++;
                    }
                }

            } catch (\Exception $e) {
                $this->job_stats['errors']++;
                $this->log_job('prospect_creation_error', sprintf(
                    'Prospect creation failed for visitor %d: %s',
                    $visitor->id,
                    $e->getMessage()
                ), 'error');
            }
        }

        $this->log_job('prospect_creation_summary', sprintf(
            'Prospect sync complete. Created: %d, Updated: %d, Skipped: %d (mode: %s)',
            $created,
            $updated,
            $skipped,
            $mode
        ));

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped
        ];
    }

    /**
     * Internal room assignment logic
     *
     * @param int|null $client_id Optional client filter for client mode.
     * @return array{transitions: int, delayed: int, total: int} Results.
     */
    private function assign_rooms_internal(?int $client_id = null): array {
        global $wpdb;
        $transitions = 0;
        $delayed = 0;

        // Build client filter
        $client_where = '';
        if ($client_id) {
            $client_where = $wpdb->prepare(' AND cs.client_id = %d', $client_id);
        }

        // Get all active prospects
        $prospects = $wpdb->get_results("
            SELECT p.*, v.lead_score
            FROM {$wpdb->prefix}rtr_prospects p
            INNER JOIN {$wpdb->prefix}cpd_visitors v ON p.visitor_id = v.id
            INNER JOIN {$wpdb->prefix}dr_campaign_settings cs ON p.campaign_id = cs.id
            INNER JOIN {$wpdb->prefix}cpd_clients cl ON cs.client_id = cl.id
            WHERE p.archived_at IS NULL
            AND p.sales_handoff_at IS NULL
            AND cl.subscription_tier = 'premium'
            AND cl.rtr_enabled = 1
            {$client_where}
        ");

        $this->log_job('room_assignment_start', sprintf(
            'Starting room assignment for %d active prospects%s',
            count($prospects),
            $client_id ? " (client {$client_id})" : ''
        ));

        foreach ($prospects as $prospect) {
            try {
                // Get thresholds for this campaign
                $thresholds = $this->get_room_thresholds($prospect->campaign_id);

                // Calculate what room they should be in
                $calculated_room = $this->calculate_room_assignment(
                    $prospect->lead_score ?? 0,
                    $thresholds
                );

                // Check if room needs to change
                $should_change = false;
                if ($prospect->current_room !== $calculated_room) {
                    $should_change = true;
                }

                // Apply room change if approved
                if ($should_change) {
                    $wpdb->update(
                        $wpdb->prefix . 'rtr_prospects',
                        [
                            'current_room'     => $calculated_room,
                            'updated_at'       => current_time('mysql'),
                        ],
                        [
                            'id' => $prospect->id,
                        ],
                        ['%s', '%s'],
                        ['%d']
                    );

                    $this->log_room_transition(
                        $prospect->visitor_id,
                        $prospect->campaign_id,
                        $prospect->current_room,
                        $calculated_room,
                        'Automatic room assignment based on score'
                    );

                    $transitions++;
                }

            } catch (\Exception $e) {
                $this->job_stats['errors']++;
                
                $this->log_job('room_assignment_prospect_error', sprintf(
                    'Room assignment failed for prospect %d: %s',
                    $prospect->id,
                    $e->getMessage()
                ), 'error');
            }
        }

        return [
            'transitions' => $transitions,
            'delayed'     => $delayed,
            'total'       => count($prospects),
        ];
    }

    /**
     * Check if room change is downward movement (score dropped)
     *
     * @param string $from_room Current room.
     * @param string $to_room   Target room.
     * @return bool True if downward movement.
     */
    private function is_downward_movement(string $from_room, string $to_room): bool {
        $from_level = self::ROOM_HIERARCHY[$from_room] ?? 0;
        $to_level = self::ROOM_HIERARCHY[$to_room] ?? 0;
        
        return $to_level < $from_level;
    }

    /**
     * Get room thresholds for a campaign
     *
     * @param int $campaign_id Campaign ID.
     * @return array{problem_max: int, solution_max: int, offer_min: int} Thresholds.
     */
    private function get_room_thresholds(int $campaign_id): array {
        global $wpdb;

        // Get client_id from campaign
        $client_id = $wpdb->get_var($wpdb->prepare(
            "SELECT client_id FROM {$wpdb->prefix}dr_campaign_settings WHERE id = %d",
            $campaign_id
        ));

        // Check cache with TTL
        if (isset($this->thresholds_cache[$client_id])) {
            $cached_at = $this->cache_timestamps['threshold_' . $client_id] ?? 0;
            if (time() - $cached_at < self::CACHE_TTL) {
                return $this->thresholds_cache[$client_id];
            }
        }

        // Try to get client-specific thresholds
        $thresholds = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rtr_room_thresholds WHERE client_id = %d LIMIT 1",
            $client_id
        ));

        // Fall back to global defaults (client_id IS NULL)
        if (!$thresholds) {
            $thresholds = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}rtr_room_thresholds WHERE client_id IS NULL LIMIT 1"
            );
        }

        // Final fallback to hardcoded defaults
        if (!$thresholds) {
            $result = [
                'problem_max'  => 40,
                'solution_max' => 60,
                'offer_min'    => 61,
            ];
            
            $this->thresholds_cache[$client_id] = $result;
            $this->cache_timestamps['threshold_' . $client_id] = time();
            return $result;
        }

        // Extract threshold values
        $result = [
            'problem_max'  => (int) ($thresholds->problem_max ?? 40),
            'solution_max' => (int) ($thresholds->solution_max ?? 60),
            'offer_min'    => (int) ($thresholds->offer_min ?? 61),
        ];

        // Validate threshold logic
        $validation = $this->validate_thresholds($result);
        
        if (!$validation['valid']) {
            $this->log_job('threshold_validation_error', sprintf(
                'Invalid room thresholds for client %d: %s. Using hardcoded defaults.',
                $client_id,
                implode(', ', $validation['errors'])
            ), 'warning');

            $result = [
                'problem_max'  => 40,
                'solution_max' => 60,
                'offer_min'    => 61,
            ];
        }

        // Cache the result with timestamp
        $this->thresholds_cache[$client_id] = $result;
        $this->cache_timestamps['threshold_' . $client_id] = time();

        return $result;
    }

    /**
     * Validate room thresholds
     *
     * @param array $thresholds Thresholds to validate.
     * @return array{valid: bool, errors: array} Validation result.
     */
    private function validate_thresholds(array $thresholds): array {
        $errors = [];
        
        if ($thresholds['problem_max'] < 1) {
            $errors[] = 'problem_max must be at least 1';
        }
        
        if ($thresholds['solution_max'] <= $thresholds['problem_max']) {
            $errors[] = 'solution_max must be greater than problem_max';
        }
        
        if ($thresholds['offer_min'] <= $thresholds['solution_max']) {
            $errors[] = 'offer_min must be greater than solution_max';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Calculate room assignment based on score
     *
     * @param int $lead_score Lead score.
     * @param array $thresholds Room thresholds.
     * @return string Room name.
     */
    private function calculate_room_assignment(int $lead_score, array $thresholds): string {
        if ($lead_score < 0) {
            error_log(self::LOG_PREFIX . " Invalid negative score: {$lead_score}");
            return 'none';
        }
        
        // Offer room: score >= offer_min (default: 61+)
        if ($lead_score >= $thresholds['offer_min']) {
            return 'offer';
        }

        // Solution room: score > problem_max and < offer_min (default: 41-60)
        if ($lead_score > $thresholds['problem_max']) {
            return 'solution';
        }

        // Problem room: score between 1 and problem_max (default: 1-40)
        if ($lead_score >= 1) {
            return 'problem';
        }

        // None room: score is 0
        return 'none';
    }

    /**
     * Get score breakdown for a single visitor or prospect
     *
     * @param WP_REST_Request $request Request object with visitor_id/prospect_id and client_id
     * @return WP_REST_Response|WP_Error Score breakdown or error
     */
    public function get_score_breakdown($request) {
        global $wpdb;
        
        $visitor_id = (int) $request->get_param('visitor_id');
        $prospect_id = (int) $request->get_param('prospect_id');
        $client_id = (int) $request->get_param('client_id');

        // If prospect_id is provided, resolve it to visitor_id
        if ($prospect_id > 0 && $visitor_id === 0) {
            $prospect = $wpdb->get_row($wpdb->prepare(
                "SELECT visitor_id FROM {$wpdb->prefix}rtr_prospects WHERE id = %d",
                $prospect_id
            ));
            
            if ($prospect && $prospect->visitor_id) {
                $visitor_id = (int) $prospect->visitor_id;
            } else {
                return new WP_Error(
                    'prospect_not_found',
                    sprintf('Prospect %d not found', $prospect_id),
                    ['status' => 404]
                );
            }
        }

        if ($visitor_id === 0) {
            return new WP_Error(
                'missing_visitor_id',
                'Either visitor_id or prospect_id must be provided',
                ['status' => 400]
            );
        }

        // Check if RTR_Score_Calculator is available
        if (!class_exists('\RTR_Score_Calculator')) {
            return new WP_Error(
                'score_calculator_unavailable',
                'Score calculator module is not available',
                ['status' => 503]
            );
        }

        try {
            $score_calculator = new \RTR_Score_Calculator();
            $score_data = $score_calculator->calculate_visitor_score($visitor_id, $client_id, true);
            
            if ($score_data === false || !isset($score_data['total_score'])) {
                return new WP_Error(
                    'score_calculation_failed',
                    'Failed to calculate score for this visitor',
                    ['status' => 500]
                );
            }
            
            // Sync recalculated score back to the prospects table
            // so the card display stays consistent with the breakdown
            $wpdb->update(
                $wpdb->prefix . 'rtr_prospects',
                [
                    'lead_score'   => $score_data['total_score'],
                    'current_room' => $score_data['current_room'],
                    'updated_at'   => current_time('mysql'),
                ],
                ['visitor_id' => $visitor_id],
                ['%d', '%s', '%s'],
                ['%d']
            );

            $score_data['visitor_id'] = $visitor_id;
            $score_data['prospect_id'] = $prospect_id;
            $score_data['client_id'] = $client_id;
            
            return new WP_REST_Response($score_data, 200);

        } catch (\Exception $e) {
            error_log('[RTR] Score breakdown error: ' . $e->getMessage());
            return new WP_Error(
                'score_breakdown_error',
                'Error retrieving score breakdown: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }    

    /**
     * Log room transition
     *
     * @param int    $visitor_id  Visitor ID.
     * @param int    $campaign_id Campaign ID.
     * @param string $from_room   From room.
     * @param string $to_room     To room.
     * @param string $reason      Reason for transition.
     * @return void
     */
    private function log_room_transition(int $visitor_id, int $campaign_id, string $from_room, string $to_room, string $reason): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'rtr_room_progression',
            [
                'visitor_id'  => $visitor_id,
                'campaign_id' => $campaign_id,
                'from_room'   => $from_room,
                'to_room'     => $to_room,
                'reason'      => $reason,
                'transitioned_at'  => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Log job action
     *
     * @param string $action_type Action type.
     * @param string $description Description.
     * @param string $level       Log level (info, warning, error).
     * @return void
     */
    private function log_job(string $action_type, string $description, string $level = 'info'): void {
        global $wpdb;

        // Log to action logs table
        $wpdb->insert(
            $wpdb->prefix . 'cpd_action_logs',
            [
                'user_id'     => 0, // System job
                'action_type' => 'nightly_job_' . $action_type,
                'description' => $description,
                'timestamp'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );

        // Only log to error_log for warnings/errors or if WP_DEBUG is true
        if ($level !== 'info' || (defined('WP_DEBUG') && WP_DEBUG)) {
            $prefix = self::LOG_PREFIX_JOB . ' ' . strtoupper($level) . ']';
            //error_log("{$prefix} {$action_type}: {$description}");
        }
    }

    /**
     * Check API key OR admin permission
     * Allows both API key auth (for Make.com) and admin auth (for UI)
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if authenticated, WP_Error otherwise.
     */
    public function check_api_key_or_admin($request): bool|WP_Error {
        // First check if user is admin
        if (current_user_can('manage_options')) {
            return true;
        }
        
        // Fall back to API key check
        return $this->check_api_key($request);
    }

    /**
     * Check API key authentication
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if authenticated, WP_Error otherwise.
     */
    public function check_api_key($request): bool|WP_Error {
        $api_key = $request->get_header('X-API-Key');

        if (empty($api_key)) {
            return new WP_Error(
                'missing_api_key',
                'API key is required',
                ['status' => 401]
            );
        }

        $stored_key = get_option('cpd_api_key');

        if (empty($stored_key)) {
            return current_user_can('manage_options');
        }

        if ($api_key !== $stored_key) {
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                ['status' => 403]
            );
        }

        return true;
    }
}