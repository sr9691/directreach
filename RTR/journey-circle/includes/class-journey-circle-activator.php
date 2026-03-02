<?php
/**
 * Journey Circle Activator — Database Table Creation
 *
 * Creates the proper relational tables that match the Integration Design schema.
 * Replaces the previous generic metadata/relationships approach with dedicated
 * tables for service_areas, journey_circles, problems, solutions, offers,
 * assets, brain_content, and workflow state.
 *
 * Tables are prefixed with {wp_prefix}jc_ to namespace within Journey Circle.
 *
 * @package Journey_Circle
 * @since 2.0.0
 */

class Journey_Circle_Activator {

    /**
     * Bump this to trigger migration on next admin load.
     */
    const DB_VERSION = '2.0.0';

    /**
     * Option key for tracking installed DB schema version.
     */
    const DB_VERSION_OPTION = 'journey_circle_db_version';

    /**
     * Plugin activation hook.
     */
    public static function activate() {
        self::create_tables();
        flush_rewrite_rules();
        add_option( 'journey_circle_version', JOURNEY_CIRCLE_VERSION );
        self::set_default_options();
    }

    /**
     * Run on admin_init — upgrades DB if schema version changed.
     * This ensures tables exist even without re-activating the plugin.
     */
    public static function maybe_upgrade() {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::create_tables();
        }
    }

    /**
     * Create all custom database tables via dbDelta.
     */
    private static function create_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix . 'jc_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Service Areas
        dbDelta( "CREATE TABLE {$p}service_areas (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(20) DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_client (client_id),
            KEY idx_status (status)
        ) $charset;" );

        // 2. Journey Circles (one per service area)
        dbDelta( "CREATE TABLE {$p}journey_circles (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_area_id BIGINT(20) UNSIGNED NOT NULL,
            primary_problem_id BIGINT(20) UNSIGNED NULL,
            industries TEXT,
            brain_content LONGTEXT,
            status VARCHAR(20) DEFAULT 'incomplete',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_service_area (service_area_id),
            KEY idx_status (status)
        ) $charset;" );

        // 3. Problems (outer ring — up to 5)
        dbDelta( "CREATE TABLE {$p}journey_problems (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            journey_circle_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(500) NOT NULL,
            description TEXT,
            is_primary TINYINT(1) DEFAULT 0,
            position INT DEFAULT 0,
            asset_urls TEXT,
            status VARCHAR(20) DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_journey_circle (journey_circle_id),
            KEY idx_position (position)
        ) $charset;" );

        // 4. Solutions (middle ring — one per problem)
        dbDelta( "CREATE TABLE {$p}journey_solutions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            journey_circle_id BIGINT(20) UNSIGNED NOT NULL,
            problem_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(500) NOT NULL,
            description TEXT,
            position INT DEFAULT 0,
            asset_urls TEXT,
            status VARCHAR(20) DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_journey_circle (journey_circle_id),
            KEY idx_problem (problem_id),
            KEY idx_position (position)
        ) $charset;" );

        // 5. Offers (center — multiple per solution/problem)
        dbDelta( "CREATE TABLE {$p}journey_offers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            journey_circle_id BIGINT(20) UNSIGNED NOT NULL,
            solution_id BIGINT(20) UNSIGNED NULL,
            problem_id BIGINT(20) UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            description TEXT,
            position INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_journey_circle (journey_circle_id),
            KEY idx_solution (solution_id),
            KEY idx_problem (problem_id)
        ) $charset;" );

        // 6. Assets (AI-generated content)
        dbDelta( "CREATE TABLE {$p}journey_assets (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            journey_circle_id BIGINT(20) UNSIGNED NOT NULL,
            problem_id BIGINT(20) UNSIGNED NULL,
            solution_id BIGINT(20) UNSIGNED NULL,
            linked_to_type VARCHAR(50) NOT NULL DEFAULT 'problem',
            linked_to_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            asset_type VARCHAR(50) NOT NULL,
            title VARCHAR(500),
            outline LONGTEXT,
            content LONGTEXT,
            published_url VARCHAR(2048),
            status VARCHAR(50) DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_journey_circle (journey_circle_id),
            KEY idx_problem (problem_id),
            KEY idx_linked (linked_to_type,linked_to_id),
            KEY idx_status (status)
        ) $charset;" );

        // 7. Brain Content
        dbDelta( "CREATE TABLE {$p}brain_content (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            journey_circle_id BIGINT(20) UNSIGNED NOT NULL,
            service_area_id BIGINT(20) UNSIGNED NULL,
            content_type VARCHAR(50) NOT NULL,
            content_value LONGTEXT NOT NULL,
            file_path VARCHAR(500),
            extracted_text LONGTEXT,
            extraction_status VARCHAR(20) DEFAULT 'pending',
            processed TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_journey_circle (journey_circle_id),
            KEY idx_content_type (content_type),
            KEY idx_extraction_status (extraction_status)
        ) $charset;" );

        // 8. Journey State Snapshots (full localStorage backup)
        dbDelta( "CREATE TABLE {$p}journey_state (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            service_area_id BIGINT(20) UNSIGNED NULL,
            journey_circle_id BIGINT(20) UNSIGNED NULL,
            state_data LONGTEXT NOT NULL,
            current_step INT DEFAULT 1,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_client_user (client_id,user_id),
            KEY idx_journey_circle (journey_circle_id)
        ) $charset;" );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Journey Circle: DB tables created/updated to v' . self::DB_VERSION );
        }
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options() {
        $defaults = array(
            'jc_enable_ai'    => true,
            'jc_ai_provider'  => 'gemini',
            'jc_max_problems' => 5,
            'jc_max_solutions' => 5,
        );
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}