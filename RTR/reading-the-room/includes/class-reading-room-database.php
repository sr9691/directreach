<?php
/**
 * Reading Room Database Layer
 *
 * Handles all database interactions for prospects, campaigns, and analytics.
 *
 * @package DirectReach
 * @subpackage ReadingTheRoom
 * @since 1.2.0
 */

declare(strict_types=1);

namespace DirectReach\ReadingTheRoom;

use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

final class Reading_Room_Database
{
    /** @var wpdb */
    private $db;

    /** @var string */
    private $table_prospects;

    /** @var string */
    private $table_emails;

    /** @var string */
    private $table_campaigns;

    /** @var string */
    private $table_analytics;

    /** @var string */
    private $table_clients;

    /** @var string */
    private $charset_collate;

    /** @var string */
    private $schema_version = '2.0.0';

    /**
     * Constructor.
     *
     * @param wpdb $wpdb
     */
    public function __construct(wpdb $wpdb)
    {
        global $wpdb;
        $this->db = $wpdb;
        $prefix = $wpdb->prefix;

        $this->table_prospects = "{$prefix}rtr_prospects";
        $this->table_emails    = "{$prefix}rtr_emails";
        $this->table_campaigns = "{$prefix}dr_campaign_settings"; // FIXED: Use correct campaign table
        $this->table_analytics = "{$prefix}rtr_analytics";
        $this->table_clients   = "{$prefix}cpd_clients";

        $this->charset_collate  = $this->db->get_charset_collate();
    }

    /**
     * Return the schema version for external checks.
     */
    public function get_schema_version(): string
    {
        return $this->schema_version;
    }


    /**
     * Best-effort transaction start. Safe to call even if not supported.
     */
    public function begin(): void
    {
        try {
            $this->db->query('START TRANSACTION');
        } catch (\Throwable $e) {
            error_log('[DirectReach][DB] begin transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * Commit transaction.
     */
    public function commit(): void
    {
        try {
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            error_log('[DirectReach][DB] commit failed: ' . $e->getMessage());
        }
    }

    /**
     * Rollback transaction.
     */
    public function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            error_log('[DirectReach][DB] rollback failed: ' . $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------------
     * Campaigns
     * -------------------------------------------------------------------*/

    /**
     * Upsert a campaign by name.
     *
     * @param string $name
     * @param array<string,mixed> $data Additional columns (status, metadata)
     * @return int Campaign ID
     */
    public function upsert_campaign(string $name, array $data = []): int
    {
        // FIXED: Use campaign_name column instead of name
        $existing = $this->db->get_var(
            $this->db->prepare("SELECT id FROM {$this->table_campaigns} WHERE campaign_name = %s LIMIT 1", $name)
        );

        $payload = [
            'campaign_name' => $name, // FIXED: Use campaign_name column
            'campaign_id'   => isset($data['campaign_id']) ? (string) $data['campaign_id'] : $name,
            'utm_campaign'  => isset($data['utm_campaign']) ? (string) $data['utm_campaign'] : '',
            'updated_at'    => current_time('mysql'),
        ];

        $formats = ['%s','%s','%s','%s'];

        if ($existing) {
            $result = $this->db->update($this->table_campaigns, $payload, ['id' => (int) $existing], $formats, ['%d']);
            if ($result === false) {
                error_log('[DirectReach][DB] upsert_campaign update failed: ' . $this->db->last_error);
            }
            return (int) $existing;
        }

        $payload['created_at'] = current_time('mysql');
        $result = $this->db->insert($this->table_campaigns, $payload, array_merge($formats, ['%s']));
        if ($result === false) {
            error_log('[DirectReach][DB] upsert_campaign insert failed: ' . $this->db->last_error);
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * Get campaigns.
     *
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function get_campaigns(array $args = []): array
    {
        $where  = [];
        $params = [];

        // Note: dr_campaign_settings doesn't have a status column by default
        // If you need status filtering, you'll need to add it to the schema

        if (!empty($args['name_like'])) {
            $where[]  = 'campaign_name LIKE %s'; // FIXED: Use campaign_name column
            $params[] = $this->prepare_like((string) $args['name_like']);
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql       = "SELECT * FROM {$this->table_campaigns} {$where_sql} ORDER BY updated_at DESC";

        $prepared = !empty($params) ? $this->db->prepare($sql, ...$params) : $sql;
        $rows     = $this->db->get_results($prepared, ARRAY_A);

        return $rows ?: [];
    }

    /* ---------------------------------------------------------------------
     * Prospects
     * -------------------------------------------------------------------*/

    /**
     * Insert or update a prospect (by id if provided).
     *
     * @param array<string,mixed> $data
     * @return int Prospect ID
     */
    public function save_prospect(array $data): int
    {
        // Build payload with only fields that exist in the actual rtr_prospects schema
        $payload = [
            'updated_at' => current_time('mysql'),
        ];
        
        $formats = ['%s']; // Start with updated_at format

        // Add fields only if they're provided (match actual rtr_prospects schema)
        if (isset($data['contact_email'])) {
            $payload['contact_email'] = (string) $data['contact_email'];
            $formats[] = '%s';
        }
        if (isset($data['contact_name'])) {
            $payload['contact_name'] = (string) $data['contact_name'];
            $formats[] = '%s';
        }
        if (isset($data['company_name'])) {
            $payload['company_name'] = (string) $data['company_name'];
            $formats[] = '%s';
        }
        if (isset($data['campaign_id'])) {
            $payload['campaign_id'] = (int) $data['campaign_id'];
            $formats[] = '%d';
        }
        if (isset($data['visitor_id'])) {
            $payload['visitor_id'] = (int) $data['visitor_id'];
            $formats[] = '%d';
        }
        if (isset($data['current_room'])) {
            $payload['current_room'] = (string) $data['current_room'];
            $formats[] = '%s';
        }
        if (isset($data['lead_score'])) {
            $payload['lead_score'] = (int) $data['lead_score'];
            $formats[] = '%d';
        }
        if (isset($data['days_in_room'])) {
            $payload['days_in_room'] = (int) $data['days_in_room'];
            $formats[] = '%d';
        }
        if (isset($data['email_sequence_position'])) {
            $payload['email_sequence_position'] = (int) $data['email_sequence_position'];
            $formats[] = '%d';
        }
        if (isset($data['email_states'])) {
            $payload['email_states'] = is_string($data['email_states']) 
                ? $data['email_states'] 
                : wp_json_encode($data['email_states']);
            $formats[] = '%s';
        }
        if (isset($data['email_sequence_state'])) {
            $payload['email_sequence_state'] = is_string($data['email_sequence_state']) 
                ? $data['email_sequence_state'] 
                : wp_json_encode($data['email_sequence_state']);
            $formats[] = '%s';
        }
        if (isset($data['urls_sent'])) {
            $payload['urls_sent'] = is_string($data['urls_sent']) 
                ? $data['urls_sent'] 
                : wp_json_encode($data['urls_sent']);
            $formats[] = '%s';
        }
        if (isset($data['last_email_sent'])) {
            $payload['last_email_sent'] = $data['last_email_sent'];
            $formats[] = '%s';
        }
        if (isset($data['next_email_due'])) {
            $payload['next_email_due'] = $data['next_email_due'];
            $formats[] = '%s';
        }
        if (isset($data['archived_at'])) {
            $payload['archived_at'] = $data['archived_at'];
            $formats[] = '%s';
        }
        if (isset($data['sales_handoff_at'])) {
            $payload['sales_handoff_at'] = $data['sales_handoff_at'];
            $formats[] = '%s';
        }
        if (isset($data['handoff_notes'])) {
            $payload['handoff_notes'] = (string) $data['handoff_notes'];
            $formats[] = '%s';
        }
        if (isset($data['engagement_data'])) {
            $payload['engagement_data'] = is_string($data['engagement_data']) 
                ? $data['engagement_data'] 
                : wp_json_encode($data['engagement_data']);
            $formats[] = '%s';
        }

        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $result = $this->db->update($this->table_prospects, $payload, ['id' => $id], $formats, ['%d']);
            if ($result === false) {
                error_log('[DirectReach][DB] save_prospect update failed: ' . $this->db->last_error);
                return 0;
            }
            return $id;
        }

        // For new inserts, add created_at
        $payload['created_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $this->db->insert($this->table_prospects, $payload, $formats);
        if ($result === false) {
            error_log('[DirectReach][DB] save_prospect insert failed: ' . $this->db->last_error);
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * Get a single prospect by ID.
     */
    public function get_prospect(int $id): ?array
    {
        $sql = $this->db->prepare("SELECT * FROM {$this->table_prospects} WHERE id = %d LIMIT 1", $id);
        $row = $this->db->get_row($sql, ARRAY_A);
        return $row ?: null;
    }

    /**
     * Get prospects with optional filters.
     *
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public function get_prospects(array $args = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($args['campaign_id'])) {
            $where[]  = 'p.campaign_id = %d'; 
            $params[] = (int) $args['campaign_id'];
        }

        if (!empty($args['client_id'])) {
            $where[]  = 'c.client_id = %d'; 
            $params[] = (int) $args['client_id'];
        }

        if (!empty($args['days'])) {
            $where[]  = 'p.updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)'; 
            $params[] = (int) $args['days'];
        }        

        $where[] = 'p.archived_at IS NULL';
        $where[] = '(v.is_archived = 0 OR v.is_archived IS NULL)';
        
        // Minimum threshold filter: exclude prospects where lead_score < minimum_threshold
        // Uses client-specific rules if available, falls back to global rules
        $where[] = "(
            v.lead_score >= COALESCE(
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(csr.rules_config, '$.minimum_threshold.required_score'))
                 FROM {$this->db->prefix}rtr_client_scoring_rules csr 
                 WHERE csr.client_id = c.client_id 
                 AND csr.room_type = 'problem'
                 AND JSON_EXTRACT(csr.rules_config, '$.minimum_threshold.enabled') = true
                 LIMIT 1),
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(gsr.rules_config, '$.minimum_threshold.required_score'))
                 FROM {$this->db->prefix}rtr_global_scoring_rules gsr 
                 WHERE gsr.room_type = 'problem'
                 AND JSON_EXTRACT(gsr.rules_config, '$.minimum_threshold.enabled') = true
                 LIMIT 1),
                0
            )
        )";
        
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "
            SELECT p.*, c.campaign_name AS campaign_name, c.client_id as client_id,
                   v.lead_score, v.current_room
            FROM {$this->table_prospects} p
            LEFT JOIN {$this->table_campaigns} c ON p.campaign_id = c.id
            LEFT JOIN {$this->db->prefix}cpd_visitors v ON p.visitor_id = v.id
            {$where_sql}
            ORDER BY p.updated_at DESC
        ";
        error_log('[DirectReach][DB] get_prospects SQL: ' . $sql);

        $prepared = !empty($params) ? $this->db->prepare($sql, ...$params) : $sql;
        $rows     = $this->db->get_results($prepared, ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Delete a prospect.
     */
    public function delete_prospect(int $id): bool
    {
        $result = $this->db->delete($this->table_prospects, ['id' => $id], ['%d']);
        return $result !== false;
    }

    /* ---------------------------------------------------------------------
     * Emails
     * -------------------------------------------------------------------*/

    /**
     * Save an email record.
     *
     * @param array<string,mixed> $data
     * @return int Email ID
     */
    public function save_email(array $data): int
    {
        $payload = [
            'prospect_id' => isset($data['prospect_id']) ? (int) $data['prospect_id'] : null,
            'campaign_id' => isset($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            'subject'     => isset($data['subject']) ? (string) $data['subject'] : null,
            'body'        => isset($data['body']) ? (string) $data['body'] : null,
            'status'      => isset($data['status']) ? (string) $data['status'] : 'pending',
            'metadata'    => isset($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'updated_at'  => current_time('mysql'),
        ];

        $formats = ['%d','%d','%s','%s','%s','%s','%s'];

        if (!empty($data['id'])) {
            $id     = (int) $data['id'];
            $result = $this->db->update($this->table_emails, $payload, ['id' => $id], $formats, ['%d']);
            if ($result === false) {
                error_log('[DirectReach][DB] save_email update failed: ' . $this->db->last_error);
                return 0;
            }
            return $id;
        }

        $payload['created_at'] = current_time('mysql');
        $result = $this->db->insert($this->table_emails, $payload, array_merge($formats, ['%s']));
        if ($result === false) {
            error_log('[DirectReach][DB] save_email insert failed: ' . $this->db->last_error);
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * Get emails for a prospect.
     */
    public function get_emails_for_prospect(int $prospect_id): array
    {
        $sql  = $this->db->prepare(
            "SELECT * FROM {$this->table_emails} WHERE prospect_id = %d ORDER BY created_at DESC",
            $prospect_id
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return $rows ?: [];
    }

    /* ---------------------------------------------------------------------
     * Analytics
     * -------------------------------------------------------------------*/

    /**
     * Log an analytics event.
     */
    public function log_event(array $data): int
    {
        $payload = [
            'prospect_id' => isset($data['prospect_id']) ? (int) $data['prospect_id'] : null,
            'event_key'   => (string) $data['event_key'],
            'event_value' => isset($data['event_value']) ? wp_json_encode($data['event_value']) : null,
            'url'         => isset($data['url']) ? (string) $data['url'] : null,
            'ip'          => isset($data['ip']) ? (string) $data['ip'] : null,
            'user_agent'  => isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            'occurred_at' => isset($data['occurred_at']) ? (string) $data['occurred_at'] : current_time('mysql'),
            'created_at'  => current_time('mysql'),
        ];

        $result = $this->db->insert(
            $this->table_analytics,
            $payload,
            ['%d','%s','%s','%s','%s','%s','%s','%s']
        );

        if ($result === false) {
            error_log('[DirectReach][DB] log_event failed: ' . $this->db->last_error);
            return 0;
        }

        return (int) $this->db->insert_id;
    }

    /**
     * Get analytics events with optional filters.
     */
    public function get_events(array $args = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($args['prospect_id'])) {
            $where[]  = 'a.prospect_id = %d';
            $params[] = (int) $args['prospect_id'];
        }

        if (!empty($args['event_key'])) {
            $where[]  = 'a.event_key = %s';
            $params[] = (string) $args['event_key'];
        }

        if (!empty($args['from_date'])) {
            $where[]  = 'a.occurred_at >= %s';
            $params[] = (string) $args['from_date'];
        }

        if (!empty($args['to_date'])) {
            $where[]  = 'a.occurred_at <= %s';
            $params[] = (string) $args['to_date'];
        }

        $limit = '';
        if (!empty($args['limit'])) {
            $limit = $this->db->prepare('LIMIT %d', (int) $args['limit']);
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "
            SELECT a.*, p.email, p.company
            FROM {$this->table_analytics} a
            LEFT JOIN {$this->table_prospects} p ON a.prospect_id = p.id
            {$where_sql}
            ORDER BY a.occurred_at DESC
            {$limit}
        ";

        $prepared = !empty($params) ? $this->db->prepare($sql, ...$params) : $sql;
        $rows     = $this->db->get_results($prepared, ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Install/upgrade database schema.
     * Call this on plugin activation or when schema version changes.
     *
     * @return bool Success
     */
    public function install_schema(): bool
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $current_version = get_option('rtr_db_version', '0.0.0');
        
        if (version_compare($current_version, $this->schema_version, '>=')) {
            return true; // Already up to date
        }
        
        $created = $this->create_tables();
        
        if ($created) {
            update_option('rtr_db_version', $this->schema_version);
            error_log('[DirectReach][DB] Schema installed/upgraded to version ' . $this->schema_version);
        }
        
        return $created;
    }

    /**
     * Create all required tables.
     * 
     * NOTE: This method does NOT create the campaigns table because it uses
     * the existing wp_dr_campaign_settings table from the main plugin.
     * Only creates RTR-specific tables (prospects, emails, analytics).
     *
     * @return bool Success
     */
    private function create_tables(): bool
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $success = true;
        
        // NOTE: We do NOT create campaigns table here - we use wp_dr_campaign_settings
        // which is created by the main CPD_Database class
        
        // Create prospects table (if not exists)
        // Note: The main database may already create this as wp_rtr_prospects
        // This is a safety check in case it's called independently
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            visitor_id mediumint(9) NOT NULL,
            current_room ENUM('problem', 'solution', 'offer') NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            contact_name VARCHAR(255),
            contact_email VARCHAR(255),
            lead_score INT DEFAULT 0,
            days_in_room INT DEFAULT 0,
            email_sequence_position INT DEFAULT 0,
            email_sequence_state TEXT NULL DEFAULT '[]' COMMENT 'JSON array tracking state of each email in sequence',
            urls_sent TEXT NULL DEFAULT '[]' COMMENT 'JSON array of sent content URLs',
            last_email_sent DATETIME,
            next_email_due DATE,
            engagement_data TEXT COMMENT 'JSON object with recent page visits',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            sales_handoff_at DATETIME NULL,
            handoff_notes TEXT NULL,
            INDEX idx_campaign_room (campaign_id, current_room),
            INDEX idx_visitor (visitor_id),
            INDEX idx_lead_score (lead_score),
            INDEX idx_email_due (next_email_due),
            INDEX idx_archived (archived_at),
            INDEX idx_sales_handoff (sales_handoff_at),
            FOREIGN KEY (campaign_id)
                REFERENCES {$wpdb->prefix}dr_campaign_settings(id)
                ON DELETE CASCADE,
            FOREIGN KEY (visitor_id)
                REFERENCES {$wpdb->prefix}cpd_visitors(id)
                ON DELETE CASCADE
        ) {$charset_collate};";
        
        $result = dbDelta($sql_prospects);
        if (empty($result)) {
            error_log('[DirectReach][DB] Failed to create prospects table');
            $success = false;
        }
        
        // Create emails table
        $sql_emails = "CREATE TABLE IF NOT EXISTS {$this->table_emails} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prospect_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned DEFAULT NULL,
            subject varchar(500) DEFAULT NULL,
            body longtext,
            status varchar(50) DEFAULT 'pending',
            sent_at datetime DEFAULT NULL,
            opened_at datetime DEFAULT NULL,
            clicked_at datetime DEFAULT NULL,
            metadata longtext,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY prospect_id (prospect_id),
            KEY campaign_id (campaign_id),
            KEY status (status),
            KEY sent_at (sent_at)
        ) {$this->charset_collate};";
        
        $result = dbDelta($sql_emails);
        if (empty($result)) {
            error_log('[DirectReach][DB] Failed to create emails table');
            $success = false;
        }
        
        // Create analytics table  
        $sql_analytics = "CREATE TABLE IF NOT EXISTS {$this->table_analytics} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prospect_id bigint(20) unsigned DEFAULT NULL,
            event_key varchar(100) NOT NULL,
            event_value longtext,
            url varchar(2048) DEFAULT NULL,
            ip varchar(45) DEFAULT NULL,
            user_agent varchar(500) DEFAULT NULL,
            occurred_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY prospect_id (prospect_id),
            KEY event_key (event_key),
            KEY occurred_at (occurred_at)
        ) {$this->charset_collate};";
        
        $result = dbDelta($sql_analytics);
        if (empty($result)) {
            error_log('[DirectReach][DB] Failed to create analytics table');
            $success = false;
        }
        
        return $success;
    }

    /**
     * Check if all tables exist.
     *
     * @return bool True if all tables exist
     */
    public function tables_exist(): bool
    {
        $tables_to_check = [
            $this->table_campaigns, // This is wp_dr_campaign_settings
            $this->table_prospects,
            $this->table_emails,
            $this->table_analytics
        ];
        
        foreach ($tables_to_check as $table) {
            $result = $this->db->get_var($this->db->prepare(
                "SHOW TABLES LIKE %s",
                $table
            ));
            
            if ($result !== $table) {
                error_log("[DirectReach][DB] Table missing: {$table}");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Drop all tables (for uninstall).
     * USE WITH CAUTION!
     * 
     * NOTE: This does NOT drop wp_dr_campaign_settings as it's shared with other modules
     *
     * @return bool Success
     */
    public function drop_tables(): bool
    {
        $tables = [
            $this->table_analytics,
            $this->table_emails,
            // Note: We don't drop prospects or campaigns tables here
            // as they may be managed by the main plugin
        ];
        
        foreach ($tables as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }
        
        delete_option('rtr_db_version');
        
        return true;
    }    

    /* ---------------------------------------------------------------------
     * Utilities
     * -------------------------------------------------------------------*/

    /**
     * Escape value for LIKE queries: wraps with % and escapes wildcards.
     */
    public function prepare_like(string $term): string
    {
        global $wpdb;
        return '%' . $wpdb->esc_like($term) . '%';
    }

    /**
     * Expose table names for external consumers (read-only).
     */
    public function tables(): array
    {
        return [
            'prospects' => $this->table_prospects,
            'campaigns' => $this->table_campaigns,
            'analytics' => $this->table_analytics,
        ];
    }

    /**
     * Run a custom query safely.
     *
     * @param string $sql
     * @param array<int,scalar|null> $params
     * @return array<int,array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $prepared = !empty($params) ? $this->db->prepare($sql, ...$params) : $sql;
        $rows     = $this->db->get_results($prepared, ARRAY_A);
        return $rows ?: [];
    }
}

/**
 * Back-compat: allow legacy references without namespace.
 * If any external code still calls new Reading_Room_Database(), it will resolve here.
 */
if (!class_exists('\Reading_Room_Database', false)) {
    class_alias(__NAMESPACE__ . '\\Reading_Room_Database', 'Reading_Room_Database');
}