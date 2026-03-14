<?php
/**
 * Enhanced CPD_Database class with AI Intelligence support
 * Phase 2.5: Added wp_rtr_prospects table for email generation
 * Iteration 6: Added visitor scoring columns for RTR scoring system
 *
 * @package CPD_Dashboard
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPD_Database {

    private $wpdb;
    private $charset_collate;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $this->wpdb->get_charset_collate();
    }

    /**
     * Create custom database tables on plugin activation.
     */
    public function create_tables() {
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // Table for Client information (Enhanced with AI Intelligence fields).
        $table_name_clients = $this->wpdb->prefix . 'cpd_clients';
        $sql_clients = "CREATE TABLE $table_name_clients (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            account_id varchar(255) NOT NULL,
            client_name varchar(255) NOT NULL,
            logo_url varchar(255) DEFAULT '' NOT NULL,
            webpage_url varchar(255) DEFAULT '' NOT NULL,
            crm_feed_email text NOT NULL,
            ai_intelligence_enabled tinyint(1) DEFAULT 0 NOT NULL,
            client_context_info text NULL,
            ai_settings_updated_at timestamp NULL,
            ai_settings_updated_by bigint(20) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY account_id (account_id),
            INDEX idx_ai_enabled (ai_intelligence_enabled),
            INDEX idx_ai_settings_updated (ai_settings_updated_at)
        ) $this->charset_collate;";
        dbDelta( $sql_clients );

        // Table for linking WordPress Users to Clients.
        $table_name_users = $this->wpdb->prefix . 'cpd_client_users';
        $sql_users = "CREATE TABLE $table_name_users (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            client_id mediumint(9) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_client (user_id, client_id)
        ) $this->charset_collate;";
        dbDelta( $sql_users );

        // Table for Campaign Performance Data (GroundTruth).
        $table_name_campaign_data = 'dashdev_cpd_campaign_data';
        $sql_campaign_data = "CREATE TABLE $table_name_campaign_data (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            account_id VARCHAR(255) NOT NULL,
            date DATE DEFAULT NULL,
            organization_name VARCHAR(255) DEFAULT NULL,
            account_name VARCHAR(255) DEFAULT NULL,
            campaign_id VARCHAR(255) DEFAULT NULL,
            campaign_name VARCHAR(255) DEFAULT NULL,
            campaign_start_date DATE DEFAULT NULL,
            campaign_end_date DATE DEFAULT NULL,
            campaign_budget DECIMAL(10,2) DEFAULT NULL,
            ad_group_id VARCHAR(255) DEFAULT NULL,
            ad_group_name VARCHAR(255) DEFAULT NULL,
            creative_id VARCHAR(255) DEFAULT NULL,
            creative_name VARCHAR(255) DEFAULT NULL,
            creative_size VARCHAR(50) DEFAULT NULL,
            creative_url VARCHAR(2048) DEFAULT NULL,
            advertiser_bid_type VARCHAR(50) DEFAULT NULL,
            budget_type VARCHAR(50) DEFAULT NULL,
            cpm DECIMAL(10,2) DEFAULT NULL,
            cpv DECIMAL(10,2) DEFAULT NULL,
            market VARCHAR(50) DEFAULT NULL,
            contact_number VARCHAR(50) DEFAULT NULL,
            external_ad_group_id VARCHAR(255) DEFAULT NULL,
            total_impressions_contracted INT(11) DEFAULT NULL,
            impressions INT(11) DEFAULT NULL,
            clicks INT(11) DEFAULT NULL,
            ctr DECIMAL(5,2) DEFAULT NULL,
            visits INT(11) DEFAULT NULL,
            total_spent DECIMAL(10,2) DEFAULT NULL,
            secondary_actions INT(11) DEFAULT NULL,
            secondary_action_rate DECIMAL(5,2) DEFAULT NULL,
            website VARCHAR(255) DEFAULT NULL,
            direction VARCHAR(255) DEFAULT NULL,
            click_to_call INT(11) DEFAULT NULL,
            cta_more_info INT(11) DEFAULT NULL,
            coupon INT(11) DEFAULT NULL,
            daily_reach INT(11) DEFAULT NULL,
            video_start INT(11) DEFAULT NULL,
            first_quartile INT(11) DEFAULT NULL,
            midpoint INT(11) DEFAULT NULL,
            third_quartile INT(11) DEFAULT NULL,
            video_complete INT(11) DEFAULT NULL,
            PRIMARY KEY (id)
        ) $this->charset_collate;";
        dbDelta( $sql_campaign_data );

        // Table for Visitor Data (RB2B).
        $table_name_visitors = $this->wpdb->prefix . 'cpd_visitors';
        $sql_visitors = "CREATE TABLE $table_name_visitors (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            visitor_id varchar(255) NULL DEFAULT NULL,
            account_id varchar(255) NOT NULL,
            linkedin_url varchar(255) DEFAULT '' NOT NULL,
            company_name varchar(255) DEFAULT '' NOT NULL,
            all_time_page_views int(11) DEFAULT 0 NOT NULL,
            first_name varchar(255) DEFAULT '' NOT NULL,
            last_name varchar(255) DEFAULT '' NOT NULL,
            job_title varchar(255) DEFAULT '' NOT NULL,
            most_recent_referrer text NOT NULL,
            recent_page_count int(11) DEFAULT 0 NOT NULL,
            recent_page_urls varchar(10000) DEFAULT '' NOT NULL,
            tags text NOT NULL,
            estimated_employee_count varchar(50) DEFAULT '' NOT NULL,
            estimated_revenue varchar(100) DEFAULT '' NOT NULL,
            city varchar(50) DEFAULT '' NOT NULL,
            zipcode varchar(20) DEFAULT '' NOT NULL,
            last_seen_at datetime NOT NULL,
            first_seen_at datetime NOT NULL,
            new_profile tinyint(1) DEFAULT 0 NOT NULL,
            email varchar(255) DEFAULT '' NOT NULL,
            website varchar(255) DEFAULT '' NOT NULL,
            industry varchar(100) DEFAULT '' NOT NULL,
            state varchar(100) DEFAULT '' NOT NULL,
            filter_matches text NOT NULL,
            profile_type varchar(10) DEFAULT '' NOT NULL,
            status varchar(10) DEFAULT 'active' NOT NULL,
            is_crm_added tinyint(1) DEFAULT 0 NOT NULL,
            crm_sent datetime DEFAULT NULL,
            is_archived tinyint(1) DEFAULT 0 NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_linkedin_account (linkedin_url, account_id)
        ) $this->charset_collate;";
        dbDelta( $sql_visitors );

        // Table for Visitor Intelligence Data
        $table_name_intelligence = $this->wpdb->prefix . 'cpd_visitor_intelligence';
        $sql_intelligence = "CREATE TABLE $table_name_intelligence (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            visitor_id mediumint(9) NOT NULL,
            client_id mediumint(9) NOT NULL,
            user_id bigint(20) NOT NULL,
            request_data longtext NOT NULL,
            response_data longtext NULL,
            client_context text NULL,
            status enum('pending', 'processing', 'completed', 'failed') DEFAULT 'pending' NOT NULL,
            api_request_id varchar(255) NULL,
            error_message text NULL,
            processing_time int(11) NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_visitor_client (visitor_id, client_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_api_request (api_request_id)
        ) $this->charset_collate;";
        dbDelta( $sql_intelligence );
        
        // Table for Logging actions.
        $table_name_logs = $this->wpdb->prefix . 'cpd_action_logs';
        $sql_logs = "CREATE TABLE $table_name_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action_type varchar(50) NOT NULL,
            description text NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id)
        ) $this->charset_collate;";
        dbDelta( $sql_logs );

        // NEW: Create Hot List Settings table
        if (!class_exists('CPD_Hot_List_Database')) {
            require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-hot-list-database.php';
        }
        
        $hot_list_db = new CPD_Hot_List_Database();
        $hot_list_db->create_table();

        // Set plugin version for migration tracking
        update_option( 'cpd_database_version', '1.2.0' );
        
        error_log( 'CPD Database: Tables created successfully with AI Intelligence support' );
    }

    /**
     * Handle database migrations for existing installations
     * Supports migrations from v1.0.0 through v2.1.0
     */
    public function migrate_database() {
        $current_version = get_option('cpd_database_version', '1.0.0');
        
        error_log("CPD Database: Starting migration from version {$current_version}");
        
        // Migration for AI Intelligence features (1.0.0 -> 1.1.0)
        if (version_compare($current_version, '1.1.0', '<')) {
            error_log('CPD: Running migration to v1.1.0 (AI Intelligence)');
            $this->migrate_to_1_1_0();
            update_option('cpd_database_version', '1.1.0');
            $current_version = '1.1.0';
        }
        
        // Migration for Hot List settings (1.1.0 -> 1.2.0)
        if (version_compare($current_version, '1.2.0', '<')) {
            error_log('CPD: Running migration to v1.2.0 (Hot List)');
            $this->migrate_to_1_2_0();
            update_option('cpd_database_version', '1.2.0');
            $current_version = '1.2.0';
        }
        
        // Migration for DirectReach v2 Premium (1.2.0 -> 2.0.0)
        if (version_compare($current_version, '2.0.0', '<')) {
            error_log('CPD: Running migration to v2.0.0 (DirectReach Premium)');
            $this->migrate_to_2_0_0();
            update_option('cpd_database_version', '2.0.0');
            $current_version = '2.0.0';
        }
        
        // NEW: Migration for RTR Scoring System (2.0.0 -> 2.1.0)
        if (version_compare($current_version, '2.1.0', '<')) {
            error_log('CPD: Running migration to v2.1.0 (RTR Scoring System - Iteration 6)');
            $this->migrate_to_2_1_0();
            update_option('cpd_database_version', '2.1.0');
            $current_version = '2.1.0';
        }

        // Migration for contact_edited flag (2.1.0 -> 2.2.0)
        if (version_compare($current_version, '2.2.0', '<')) {
            error_log('CPD: Running migration to v2.2.0 (contact_edited flag)');
            $this->migrate_to_2_2_0();
            update_option('cpd_database_version', '2.2.0');
            $current_version = '2.2.0';
        }

        error_log("CPD Database: Migration completed. Current version: {$current_version}");
    }

    /**
     * Migration to v1.1.0: Add AI Intelligence fields
     */
    private function migrate_to_1_1_0() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpd_clients';
        
        $columns_to_add = array(
            array(
                'name' => 'ai_intelligence_enabled',
                'definition' => 'tinyint(1) DEFAULT 0 NOT NULL'
            ),
            array(
                'name' => 'client_context_info',
                'definition' => 'text NULL'
            ),
            array(
                'name' => 'ai_settings_updated_at',
                'definition' => 'timestamp NULL'
            ),
            array(
                'name' => 'ai_settings_updated_by',
                'definition' => 'bigint(20) NULL'
            )
        );
        
        foreach ($columns_to_add as $column) {
            if (!$this->column_exists($table_name, $column['name'])) {
                $sql = "ALTER TABLE {$table_name} ADD COLUMN {$column['name']} {$column['definition']}";
                $wpdb->query($sql);
                error_log("CPD: Added column {$column['name']} to {$table_name}");
            }
        }
        
        // Add indexes
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_ai_enabled ON {$table_name} (ai_intelligence_enabled)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_ai_settings_updated ON {$table_name} (ai_settings_updated_at)");
        
        // Create intelligence table
        $this->create_intelligence_table();
        
        error_log('CPD: Migration to v1.1.0 completed');
    }

    /**
     * Migration to v1.2.0: Add Hot List settings
     */
    private function migrate_to_1_2_0() {
        if (!class_exists('CPD_Hot_List_Database')) {
            require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-hot-list-database.php';
        }
        
        $hot_list_db = new CPD_Hot_List_Database();
        $hot_list_db->create_table();
        
        error_log('CPD: Migration to v1.2.0 completed (Hot List table created)');
    }

    /**
     * Migration to v2.0.0: DirectReach Premium features
     */
    private function migrate_to_2_0_0() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpd_clients';
        
        error_log('CPD: Starting v2.0.0 migration (DirectReach Premium)');
        
        // Add premium tier columns
        $columns_to_add = array(
            array(
                'name' => 'subscription_tier',
                'definition' => "ENUM('basic', 'premium') DEFAULT 'basic' NOT NULL"
            ),
            array(
                'name' => 'rtr_enabled',
                'definition' => 'TINYINT(1) DEFAULT 0 NOT NULL'
            ),
            array(
                'name' => 'rtr_activated_at',
                'definition' => 'DATETIME NULL'
            ),
            array(
                'name' => 'subscription_expires_at',
                'definition' => 'DATETIME NULL'
            )
        );
        
        foreach ($columns_to_add as $column) {
            if (!$this->column_exists($table_name, $column['name'])) {
                $sql = "ALTER TABLE {$table_name} ADD COLUMN {$column['name']} {$column['definition']}";
                $result = $wpdb->query($sql);
                
                if ($result === false) {
                    error_log("CPD: ERROR adding column {$column['name']}: " . $wpdb->last_error);
                } else {
                    error_log("CPD: Successfully added column {$column['name']}");
                }
            } else {
                error_log("CPD: Column {$column['name']} already exists");
            }
        }
        
        // Add indexes - Check before creating
        if (!$this->index_exists($table_name, 'idx_subscription_tier')) {
            $wpdb->query("CREATE INDEX idx_subscription_tier ON {$table_name} (subscription_tier)");
        }

        if (!$this->index_exists($table_name, 'idx_subscription_expires')) {
            $wpdb->query("CREATE INDEX idx_subscription_expires ON {$table_name} (subscription_expires_at)");
        }
        
        // Create all v2 tables
        $this->create_all_v2_tables();
        
        error_log('CPD: Migration to v2.0.0 completed');
    }

    /**
     * Check if an index exists on a table
     */
    private function index_exists($table_name, $index_name) {
        $index = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s",
                $index_name
            )
        );
        return !empty($index);
    }    

    /**
     * NEW: Migration to v2.1.0: RTR Scoring System (Iteration 6)
     * 
     * Adds lead scoring columns to wp_cpd_visitors table to support
     * the Reading the Room scoring system.
     * 
     * @since 2.1.0
     */
    private function migrate_to_2_1_0() {
        error_log('CPD: Starting v2.1.0 migration (RTR Scoring System - Iteration 6)');
        
        $success = $this->add_visitor_scoring_columns();
        
        if ($success) {
            error_log('CPD: Migration to v2.1.0 completed successfully');
        } else {
            error_log('CPD: Migration to v2.1.0 completed with warnings (check logs)');
        }
    }

    /**
     * Migration to v2.2.0: Add contact_edited column to rtr_prospects
     */
    private function migrate_to_2_2_0() {
        global $wpdb;
        $table = $wpdb->prefix . 'rtr_prospects';

        if (!$this->column_exists($table, 'contact_edited')) {
            $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN contact_edited TINYINT(1) DEFAULT 0 AFTER contact_email");
            if ($result === false) {
                error_log("CPD: ERROR adding contact_edited column: " . $wpdb->last_error);
            } else {
                error_log("CPD: Added contact_edited column to {$table}");
            }
        }
    }

    /**
     * NEW: Add scoring columns to visitors table (Iteration 6)
     * 
     * Adds lead_score, current_room, and score_calculated_at columns
     * to support RTR scoring system. Safe to run multiple times.
     * 
     * @since 2.1.0
     * @return bool Success status
     */
    public function add_visitor_scoring_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'cpd_visitors';
        
        error_log('CPD: Checking for visitor scoring columns in ' . $table);
        
        // Check if columns already exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        if ($columns === false) {
            error_log('CPD: ERROR - Failed to query columns from ' . $table . ': ' . $wpdb->last_error);
            return false;
        }
        
        $column_names = wp_list_pluck($columns, 'Field');
        
        $needs_update = false;
        $alter_statements = array();
        
        // Check each required column
        if (!in_array('lead_score', $column_names)) {
            $alter_statements[] = "ADD COLUMN lead_score INT DEFAULT 0 NOT NULL";
            $needs_update = true;
            error_log('CPD: Column lead_score needs to be added');
        } else {
            error_log('CPD: Column lead_score already exists');
        }
        
        if (!in_array('current_room', $column_names)) {
            $alter_statements[] = "ADD COLUMN current_room ENUM('none', 'problem', 'solution', 'offer') DEFAULT 'none' NOT NULL";
            $needs_update = true;
            error_log('CPD: Column current_room needs to be added');
        } else {
            error_log('CPD: Column current_room already exists');
        }
        
        if (!in_array('score_calculated_at', $column_names)) {
            $alter_statements[] = "ADD COLUMN score_calculated_at DATETIME NULL";
            $needs_update = true;
            error_log('CPD: Column score_calculated_at needs to be added');
        } else {
            error_log('CPD: Column score_calculated_at already exists');
        }
        
        // Execute ALTER TABLE if needed
        if ($needs_update) {
            $sql = "ALTER TABLE {$table} " . implode(', ', $alter_statements);
            error_log('CPD: Executing SQL: ' . $sql);
            
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                error_log('CPD: ERROR - Failed to add scoring columns: ' . $wpdb->last_error);
                return false;
            }
            
            // Add indexes
            error_log('CPD: Adding indexes for scoring columns');
            
            $wpdb->query("CREATE INDEX idx_lead_score ON {$table} (lead_score)");
            if ($wpdb->last_error) {
                error_log('CPD: Warning - Index idx_lead_score may already exist or failed: ' . $wpdb->last_error);
            }
            
            $wpdb->query("CREATE INDEX idx_current_room ON {$table} (current_room)");
            if ($wpdb->last_error) {
                error_log('CPD: Warning - Index idx_current_room may already exist or failed: ' . $wpdb->last_error);
            }
            
            error_log('CPD: Successfully added scoring columns to visitors table');
        } else {
            error_log('CPD: All scoring columns already exist - no migration needed');
        }
        
        return true;
    }

    /**
     * Create visitor intelligence table
     */
    private function create_intelligence_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpd_visitor_intelligence';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            visitor_id mediumint(9) NOT NULL,
            client_id mediumint(9) NOT NULL,
            user_id bigint(20) NOT NULL,
            request_data longtext NOT NULL,
            response_data longtext NULL,
            client_context text NULL,
            status enum('pending', 'processing', 'completed', 'failed') DEFAULT 'pending' NOT NULL,
            api_request_id varchar(255) NULL,
            error_message text NULL,
            processing_time int(11) NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_visitor_client (visitor_id, client_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_api_request (api_request_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('CPD: Intelligence table checked/created');
    }

    /**
     * V2: Create campaign settings table
     */
    public function create_campaign_settings_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dr_campaign_settings';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id VARCHAR(255) NOT NULL,
            client_id mediumint(9) NOT NULL,
            utm_campaign VARCHAR(255) NOT NULL,
            campaign_name VARCHAR(255) NOT NULL,
            campaign_description TEXT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_campaign (campaign_id),
            UNIQUE KEY unique_utm_per_client (client_id, utm_campaign),
            INDEX idx_utm (utm_campaign),
            INDEX idx_client (client_id),
            FOREIGN KEY (client_id) REFERENCES {$wpdb->prefix}cpd_clients(id) ON DELETE CASCADE
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_dr_campaign_settings table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_dr_campaign_settings table');
            return false;
        }
    }

    /**
     * Create wp_rtr_prospects table
     *
     * This table stores RTR campaign prospects and tracks sent content URLs.
     * 
     * @since 2.5.0
     * @return bool Success
     */
    public function create_prospects_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_prospects';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            visitor_id mediumint(9) NOT NULL,
            aleads_member_id VARCHAR(255) NULL,
            current_room ENUM('problem', 'solution', 'offer') NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            job_title VARCHAR(255) NULL,
            contact_name VARCHAR(255),
            contact_email VARCHAR(255),
            contact_edited TINYINT(1) DEFAULT 0,
            email_verified TINYINT(1) DEFAULT 0,
            email_verification_status VARCHAR(50) NULL,
            email_quality VARCHAR(50) NULL,
            email_verified_at DATETIME NULL,
            lead_score INT DEFAULT 0,
            days_in_room INT DEFAULT 0,
            email_sequence_position INT DEFAULT 0,
            email_sequence_state TEXT NULL COMMENT 'JSON array tracking state of each email in sequence: [{position:1,states:[\"sent\",\"opened\"]},{position:2,states:[]}]',
            urls_sent TEXT NULL COMMENT 'JSON array of sent content URLs',
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
            INDEX idx_email_verified (email_verified),
            FOREIGN KEY (campaign_id)
                REFERENCES {$wpdb->prefix}dr_campaign_settings(id)
                ON DELETE CASCADE,
            FOREIGN KEY (visitor_id)
                REFERENCES {$wpdb->prefix}cpd_visitors(id)
                ON DELETE CASCADE
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created wp_rtr_prospects table with email_sequence_state column');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_prospects table');
            return false;
        }
    }

    /**
     * V2: Create visitor campaigns table (Phase 3)
     */
    public function create_visitor_campaigns_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpd_visitor_campaigns';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id mediumint(9) NOT NULL,
            campaign_id BIGINT UNSIGNED NOT NULL,
            entry_page VARCHAR(2048),
            entry_referrer VARCHAR(2048),
            utm_source VARCHAR(255),
            utm_medium VARCHAR(255),
            utm_campaign VARCHAR(255),
            utm_term VARCHAR(255),
            utm_content VARCHAR(255),
            first_visit_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_visit_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            page_view_count INT DEFAULT 1,
            is_prospect TINYINT(1) DEFAULT 0,
            UNIQUE KEY unique_visitor_campaign (visitor_id, campaign_id),
            INDEX idx_visitor (visitor_id),
            INDEX idx_campaign (campaign_id),
            INDEX idx_utm_campaign (utm_campaign),
            INDEX idx_is_prospect (is_prospect),
            FOREIGN KEY (visitor_id) 
                REFERENCES {$wpdb->prefix}cpd_visitors(id) 
                ON DELETE CASCADE,
            FOREIGN KEY (campaign_id) 
                REFERENCES {$wpdb->prefix}dr_campaign_settings(id) 
                ON DELETE CASCADE
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_cpd_visitor_campaigns table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_cpd_visitor_campaigns table');
            return false;
        }
    }

    /**
     * V2: Create email tracking table
     */
    public function create_email_tracking_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_email_tracking';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospect_id BIGINT UNSIGNED NOT NULL,
            visitor_id mediumint(9) NOT NULL,
            campaign_id varchar(255),
            email_number INT NOT NULL,
            room_type ENUM('problem', 'solution', 'offer') NOT NULL,
            subject VARCHAR(500),
            body_html LONGTEXT,
            body_text LONGTEXT,
            generated_by_ai TINYINT(1) DEFAULT 0,
            template_used BIGINT UNSIGNED NULL,
            ai_prompt_tokens INT NULL,
            ai_completion_tokens INT NULL,
            url_included VARCHAR(500) NULL,
            notes TEXT,
            copied_at DATETIME NULL,
            sent_at DATETIME,
            opened_at DATETIME,
            clicked_at DATETIME,
            status ENUM('pending', 'copied', 'sent', 'opened', 'clicked') DEFAULT 'pending',
            tracking_token VARCHAR(255) UNIQUE,
            INDEX idx_prospect (prospect_id),
            INDEX idx_status (status),
            INDEX idx_tracking (tracking_token),
            INDEX idx_template_used (template_used)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_rtr_email_tracking table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_email_tracking table');
            return false;
        }
    }

    /**
     * V2: Create room progression table
     */
    public function create_room_progression_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_room_progression';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id mediumint(9) NOT NULL,
            campaign_id BIGINT UNSIGNED NOT NULL,
            from_room ENUM('none', 'problem', 'solution', 'offer') NOT NULL,
            to_room ENUM('problem', 'solution', 'offer', 'sales') NOT NULL,
            reason VARCHAR(500),
            transitioned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visitor (visitor_id),
            INDEX idx_campaign (campaign_id),
            INDEX idx_transitioned (transitioned_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_rtr_room_progression table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_room_progression table');
            return false;
        }
    }

    /**
     * V2: Create email templates table
     */
    public function create_email_templates_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_email_templates';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            room_type ENUM('problem', 'solution', 'offer') NOT NULL,
            template_name VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            body_html LONGTEXT NOT NULL,
            body_text LONGTEXT,
            prompt_template LONGTEXT NULL COMMENT 'JSON: 7-component AI prompt structure',
            template_order INT DEFAULT 0,
            is_global TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_campaign (campaign_id),
            INDEX idx_room_type (room_type),
            INDEX idx_campaign_room (campaign_id, room_type),
            INDEX idx_template_order (template_order),
            INDEX idx_is_global (is_global),
            FOREIGN KEY (campaign_id) 
                REFERENCES {$wpdb->prefix}dr_campaign_settings(id) 
                ON DELETE CASCADE
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_rtr_email_templates table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_email_templates table');
            return false;
        }
    }

    /**
     * V2: Create scoring rules tables
     */
    public function create_scoring_rules_tables() {
        $success = true;
        
        $success = $success && $this->create_global_scoring_rules_table();
        $success = $success && $this->create_client_scoring_rules_table();
        $success = $success && $this->create_room_thresholds_table();
        
        if ($success) {
            // Initialize default values
            $this->initialize_global_scoring_rules();
            $this->initialize_global_room_thresholds();
        }
        
        return $success;
    }

    /**
     * Create global scoring rules table
     */
    private function create_global_scoring_rules_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_global_scoring_rules';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_type ENUM('problem', 'solution', 'offer') NOT NULL,
            rules_config LONGTEXT NOT NULL COMMENT 'JSON: all rules for this room',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_room (room_type)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_rtr_global_scoring_rules table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_global_scoring_rules table');
            return false;
        }
    }

    /**
     * Create visitor activity tracking table
     * 
     * @since 2.1.0
     * @return bool
     */
    public function create_visitor_activity_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpd_visitor_activity';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_id mediumint(9) NOT NULL,
            activity_type ENUM('page_visit', 'email_open', 'email_click') NOT NULL,
            page_url VARCHAR(2048) NULL,
            utm_source VARCHAR(255) NULL,
            utm_medium VARCHAR(255) NULL,
            utm_campaign VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visitor (visitor_id),
            INDEX idx_activity_type (activity_type),
            INDEX idx_visitor_activity (visitor_id, activity_type),
            INDEX idx_utm_source (utm_source),
            FOREIGN KEY (visitor_id) 
                REFERENCES {$wpdb->prefix}cpd_visitors(id) 
                ON DELETE CASCADE
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);
    }    

    public function create_rtr_jobs_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_jobs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(100) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            params LONGTEXT NULL,
            result LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
            ) {$this->charset_collate};
            ";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);
    }  

    public function create_rtr_content_links_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_content_links';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            room_type ENUM('problem','solution','offer') NOT NULL,
            link_title VARCHAR(255) NOT NULL,
            link_url VARCHAR(500) NOT NULL,
            link_description TEXT NULL,
            url_summary TEXT NULL,
            link_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY room_type (room_type),
            KEY link_order (link_order),
            KEY is_active (is_active)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);
    }

    /**
     * Create client scoring rules table
     */
    private function create_client_scoring_rules_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_client_scoring_rules';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id mediumint(9) NOT NULL,
            room_type ENUM('problem', 'solution', 'offer') NOT NULL,
            rules_config LONGTEXT NOT NULL COMMENT 'JSON: client customizations',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_client_room (client_id, room_type),
            INDEX idx_client (client_id),
            FOREIGN KEY (client_id) 
                REFERENCES {$wpdb->prefix}cpd_clients(id) 
                ON DELETE CASCADE
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_rtr_client_scoring_rules table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_client_scoring_rules table');
            return false;
        }
    }

    /**
     * Create room thresholds table
     */
    private function create_room_thresholds_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rtr_room_thresholds';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id mediumint(9) NULL DEFAULT NULL COMMENT 'NULL = global default',
            problem_max INT DEFAULT 40,
            solution_max INT DEFAULT 60,
            offer_min INT DEFAULT 61,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_client (client_id),
            INDEX idx_client (client_id)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            error_log('CPD: Successfully created/verified wp_rtr_room_thresholds table');
            return true;
        } else {
            error_log('CPD: FAILED to create wp_rtr_room_thresholds table');
            return false;
        }
    }

    /**
     * Initialize global scoring rules with defaults
     */
    private function initialize_global_scoring_rules() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rtr_global_scoring_rules';
        
        // Check if already initialized
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        
        if ($existing > 0) {
            return; // Already initialized
        }
        
        // Problem Room default rules
        $problem_rules = json_encode([
            'revenue' => [
                'enabled' => true,
                'points' => 10,
                'values' => []
            ],
            'company_size' => [
                'enabled' => true,
                'points' => 10,
                'values' => []
            ],
            'industry_alignment' => [
                'enabled' => true,
                'points' => 15,
                'values' => []
            ],
            'target_states' => [
                'enabled' => true,
                'points' => 5,
                'values' => []
            ],
            'visited_target_pages' => [
                'enabled' => false,
                'points' => 10,
                'max_points' => 30
            ],
            'multiple_visits' => [
                'enabled' => true,
                'points' => 5,
                'minimum_visits' => 2
            ],
            'role_match' => [
                'enabled' => false,
                'points' => 5,
                'target_roles' => [
                    'decision_makers' => ['CEO', 'President', 'Director', 'VP', 'Chief'],
                    'technical' => ['Engineer', 'Developer', 'CTO'],
                    'marketing' => ['Marketing', 'CMO', 'Brand'],
                    'sales' => ['Sales', 'Business Development']
                ],
                'match_type' => 'contains'
            ],
            'minimum_threshold' => [
                'enabled' => true,
                'required_score' => 20
            ]
        ]);
        
        // Solution Room default rules
        $solution_rules = json_encode([
            'email_open' => [
                'enabled' => true,
                'points' => 2
            ],
            'email_click' => [
                'enabled' => true,
                'points' => 5
            ],
            'email_multiple_click' => [
                'enabled' => true,
                'points' => 8,
                'minimum_clicks' => 2
            ],
            'page_visit' => [
                'enabled' => true,
                'points_per_visit' => 3,
                'max_points' => 15
            ],
            'key_page_visit' => [
                'enabled' => true,
                'points' => 10,
                'key_pages' => ['/pricing', '/demo', '/contact']
            ],
            'ad_engagement' => [
                'enabled' => true,
                'points' => 5,
                'utm_sources' => ['google', 'linkedin', 'facebook']
            ]
        ]);
        
        // Offer Room default rules
        $offer_rules = json_encode([
            'demo_request' => [
                'enabled' => true,
                'points' => 25,
                'detection_method' => 'url_pattern',
                'patterns' => ['/demo/requested', '/demo/confirmation']
            ],
            'contact_form' => [
                'enabled' => true,
                'points' => 20,
                'detection_method' => 'utm_parameter',
                'utm_content' => 'form_submitted'
            ],
            'pricing_page' => [
                'enabled' => true,
                'points' => 15,
                'page_urls' => ['/pricing', '/plans']
            ],
            'pricing_question' => [
                'enabled' => true,
                'points' => 20,
                'detection_method' => 'utm_parameter',
                'utm_content' => 'pricing_inquiry'
            ],
            'partner_referral' => [
                'enabled' => true,
                'points' => 15,
                'detection_method' => 'utm_source',
                'utm_sources' => ['partner_referral', 'partner']
            ],
            'webinar_attendance' => [
                'enabled' => false,
                'points' => 0,
                'detection_method' => 'utm_parameter'
            ]
        ]);
        
        // Insert default rules
        $wpdb->insert($table, [
            'room_type' => 'problem',
            'rules_config' => $problem_rules
        ]);
        
        $wpdb->insert($table, [
            'room_type' => 'solution',
            'rules_config' => $solution_rules
        ]);
        
        $wpdb->insert($table, [
            'room_type' => 'offer',
            'rules_config' => $offer_rules
        ]);
        
        error_log('DirectReach: Global scoring rules initialized');
    }

    /**
     * Initialize global room thresholds
     */
    private function initialize_global_room_thresholds() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rtr_room_thresholds';
        
        // Check if global defaults exist
        $existing = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE client_id IS NULL"
        );
        
        if ($existing > 0) {
            return; // Already initialized
        }
        
        // Insert global defaults
        $wpdb->insert($table, [
            'client_id' => null,
            'problem_max' => 40,
            'solution_max' => 60,
            'offer_min' => 61
        ]);
        
        error_log('DirectReach: Global room thresholds initialized');
    }

    
    /**
     * V2: Create all v2 tables
     */
    public function create_all_v2_tables() {
        $success = true;
        
        $success = $success && $this->create_campaign_settings_table();
        $success = $success && $this->create_prospects_table();
        $success = $success && $this->create_visitor_campaigns_table();
        $success = $success && $this->create_email_tracking_table();
        $success = $success && $this->create_room_progression_table();
        $success = $success && $this->create_email_templates_table();
        $success = $success && $this->create_scoring_rules_tables();
        $success = $success && $this->create_visitor_activity_table();
        $success = $success && $this->create_rtr_jobs_table();
        $success = $success && $this->create_rtr_content_links_table();
        
        if ($success) {
            error_log('CPD: All v2 tables created successfully (including wp_rtr_prospects)');
        } else {
            error_log('CPD: Some v2 tables failed to create');
        }
        
        return $success;
    }

    /**
     * Check if a column exists in a table
     */
    private function column_exists( $table_name, $column_name ) {
        $column = $this->wpdb->get_results( 
            $this->wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $table_name, $column_name
            )
        );
        
        return ! empty( $column );
    }

    /**
     * Get AI-enabled clients
     */
    public function get_ai_enabled_clients() {
        $table_name_clients = $this->wpdb->prefix . 'cpd_clients';
        
        $results = $this->wpdb->get_results(
            "SELECT * FROM $table_name_clients WHERE ai_intelligence_enabled = 1 ORDER BY client_name ASC"
        );

        return $results ? $results : array();
    }

    /**
     * Check if a client has AI intelligence enabled
     */
    public function is_client_ai_enabled( $account_id ) {
        $table_name_clients = $this->wpdb->prefix . 'cpd_clients';
        
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT ai_intelligence_enabled FROM $table_name_clients WHERE account_id = %s",
                $account_id
            )
        );

        return $result == 1;
    }

    /**
     * Get client context information
     */
    public function get_client_context( $account_id ) {
        $table_name_clients = $this->wpdb->prefix . 'cpd_clients';
        
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT client_context_info FROM $table_name_clients WHERE account_id = %s",
                $account_id
            )
        );

        return $result ? $result : '';
    }

    /**
     * Update client AI settings
     */
    public function update_client_ai_settings( $client_id, $ai_enabled, $context_info, $user_id ) {
        $table_name_clients = $this->wpdb->prefix . 'cpd_clients';
        
        $result = $this->wpdb->update(
            $table_name_clients,
            array(
                'ai_intelligence_enabled' => $ai_enabled ? 1 : 0,
                'client_context_info' => $context_info,
                'ai_settings_updated_at' => current_time( 'mysql' ),
                'ai_settings_updated_by' => $user_id,
            ),
            array( 'id' => $client_id ),
            array( '%d', '%s', '%s', '%d' ),
            array( '%d' )
        );

        if ( $this->wpdb->last_error ) {
            error_log( 'CPD Database Error updating AI settings: ' . $this->wpdb->last_error );
            return false;
        }

        return $result !== false;
    }

    /**
     * V2: Get current database version
     */
    public function get_current_version() {
        return get_option('cpd_database_version', '1.2.0');
    }
    
    /**
     * V2: Rollback migration (for testing)
     */
    public function rollback_to_1_0_0() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpd_clients';
        
        error_log('CPD: Starting rollback from v2.0.0 to v1.2.0');
        
        try {
            // Drop indexes
            $wpdb->query("DROP INDEX IF EXISTS idx_subscription_tier ON {$table_name}");
            $wpdb->query("DROP INDEX IF EXISTS idx_subscription_expires ON {$table_name}");
            
            // Drop columns
            $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN IF EXISTS subscription_expires_at");
            $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN IF EXISTS rtr_activated_at");
            $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN IF EXISTS rtr_enabled");
            $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN IF EXISTS subscription_tier");
            
            // Reset version
            update_option('cpd_database_version', '1.2.0');
            
            error_log('CPD: Rollback completed successfully');
            return true;
            
        } catch (Exception $e) {
            error_log('CPD Rollback Error: ' . $e->getMessage());
            return false;
        }
    }
}