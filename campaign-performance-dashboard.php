<?php
/**
 * Plugin Name:       DirectReach Reports
 * Plugin URI:        https://memomarketing.com/directreach/reports
 * Description:       A custom dashboard for clients to view their campaign performance and visitor data.
 * Version:           2.0.0
 * Author:            ANSA Solutions
 * Author URI:        https://ansa.solutions/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       reports
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CPD_DASHBOARD_VERSION', '2.2.0' );
define( 'CPD_DASHBOARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ?: __DIR__ . '/' );
define( 'CPD_DASHBOARD_PLUGIN_URL', plugin_dir_url( __FILE__ ) ?: plugins_url( '/', __FILE__ ) );

/**
 * V2 Premium Feature Constants
 */
define( 'CPD_MIN_PREMIUM_VERSION', '2.0.0' );
define( 'CPD_PREMIUM_CAPABILITY', 'cpd_access_premium' );
define( 'CPD_RTR_CAPABILITY', 'cpd_access_rtr' );
define( 'CPD_CAMPAIGN_BUILDER_CAPABILITY', 'cpd_access_campaign_builder' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-dashboard.php';

// Include the admin class as well
require_once CPD_DASHBOARD_PLUGIN_DIR . 'admin/class-cpd-admin.php';

// Include AI Intelligence classes
require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-intelligence.php';
require_once CPD_DASHBOARD_PLUGIN_DIR . 'admin/class-cpd-admin-intelligence-settings.php';

// Include access control class for v2 features
require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-access-control.php';

/**
 * Register activation and deactivation hooks.
 * This is the activation hook for the plugin, which will create the database tables and roles.
 */
function cpd_dashboard_activate() {
    require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-database.php';
    $cpd_database = new CPD_Database();
    $cpd_database->create_tables();
    
    // Handle database migrations for AI Intelligence features
    $cpd_database->migrate_database();

    // Register the custom client role on activation.
    cpd_dashboard_register_roles();

    // Add custom capabilities to existing roles
    cpd_dashboard_add_capabilities();

    // Schedule the daily CRM email event on activation
    require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-email-handler.php';
    CPD_Email_Handler::schedule_daily_crm_email();
    
    // Flush rewrite rules for hot list settings
    cpd_add_hot_list_rewrite_rules();
    flush_rewrite_rules();

    // V2: Create v2 tables and register premium capabilities
    $cpd_database->create_all_v2_tables();
    cpd_register_premium_capabilities();
}

$activation_file = __FILE__ ?: '';
if ($activation_file !== '') {
    register_activation_hook( $activation_file, 'cpd_dashboard_activate' );
}

/**
 * Register premium tier capabilities
 * Called on activation and can be called manually if needed
 */
function cpd_register_premium_capabilities() {
    // Get admin role
    $admin_role = get_role( 'administrator' );
    
    if ( $admin_role ) {
        // Admins get all premium capabilities
        $admin_role->add_cap( CPD_PREMIUM_CAPABILITY );
        $admin_role->add_cap( CPD_RTR_CAPABILITY );
        $admin_role->add_cap( CPD_CAMPAIGN_BUILDER_CAPABILITY );
    }
    
    // Get client role
    $client_role = get_role( 'client' );
    
    if ( $client_role ) {
        // Clients get premium capabilities (checked dynamically based on subscription)
        $client_role->add_cap( CPD_PREMIUM_CAPABILITY );
        $client_role->add_cap( CPD_RTR_CAPABILITY );
        $client_role->add_cap( CPD_CAMPAIGN_BUILDER_CAPABILITY );
    }
    
    // Log capability registration
    error_log( 'CPD: Premium capabilities registered successfully' );
}

/**
 * Check for database updates on admin_init
 * This ensures migrations run even if plugin is already activated
 */
function cpd_check_database_version() {
    // Only run for admins
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-cpd-database.php';
    $database = new CPD_Database();
    
    $current_version = $database->get_current_version();
    
    // If version is below current, run migrations
    if ( version_compare( $current_version, '2.2.0', '<' ) ) {
        error_log( 'CPD: Detected old database version, running migrations...' );
        
        $database->migrate_database();
        $database->create_all_v2_tables();
        
        // Set flag to show admin notice
        set_transient( 'cpd_database_upgraded', true, 30 );
    }
}
add_action( 'admin_init', 'cpd_check_database_version' );

/**
 * Deactivation hook to remove custom roles and clean up.
 */
function cpd_dashboard_deactivate() {
    // Remove the custom client role upon deactivation.
    remove_role( 'client' );

    // Clear scheduled cron events on deactivation
    require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-email-handler.php';
    CPD_Email_Handler::unschedule_daily_crm_email();
    
    // Clear intelligence cleanup schedule
    wp_clear_scheduled_hook( 'cpd_intelligence_cleanup' );
    
    error_log( 'CPD Dashboard deactivated' );
}

register_deactivation_hook( __FILE__, 'cpd_dashboard_deactivate' );

/**
 * Handle plugin updates and migrations
 */
add_action( 'plugins_loaded', 'cpd_dashboard_check_version' );

/**
 * Check if plugin version has changed and run migrations if needed
 */
function cpd_dashboard_check_version() {
    $installed_version = get_option( 'cpd_dashboard_version', '1.0.0' );
    
    if ( version_compare( $installed_version, CPD_DASHBOARD_VERSION, '<' ) ) {
        // Plugin has been updated, run migrations
        require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-database.php';
        $cpd_database = new CPD_Database();
        $cpd_database->migrate_database();
        
        // Update the stored version
        update_option( 'cpd_dashboard_version', CPD_DASHBOARD_VERSION );
        
        error_log( 'CPD Dashboard updated from ' . $installed_version . ' to ' . CPD_DASHBOARD_VERSION );
    }
}

/**
 * Registers the custom 'client' role with specific capabilities.
 * PRESERVED from v1.0.0 + NEW AI Intelligence capabilities
 */
function cpd_dashboard_register_roles() {
    add_role(
        'client',
        'Client',
        array(
            'read'                => true,   // Clients can read posts.
            'upload_files'        => false,  // Don't allow file uploads.
            'edit_posts'          => false,  // Don't allow editing posts.
            'cpd_view_dashboard'  => true,   // Custom capability for viewing dashboard (PRESERVED)
            // AI Intelligence capability
            'cpd_request_intelligence' => true, // Allow clients to request intelligence for their visitors
        )
    );
}

/**
 * Add custom capabilities to existing roles
 * PRESERVED from v1.0.0 + NEW AI Intelligence capabilities
 */
function cpd_dashboard_add_capabilities() {
    // Give administrators the dashboard capability (PRESERVED)
    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $admin_role->add_cap( 'cpd_view_dashboard' );
        // AI Intelligence capabilities for admins
        $admin_role->add_cap( 'cpd_manage_intelligence' );
        $admin_role->add_cap( 'cpd_configure_intelligence' );
        $admin_role->add_cap( 'cpd_view_intelligence_stats' );
        $admin_role->add_cap( 'cpd_manage_clients' );
        $admin_role->add_cap( 'cpd_view_all_data' );
        $admin_role->add_cap( 'cpd_export_data' );
        $admin_role->add_cap( 'cpd_manage_users' );
    }

    // Give the client role the dashboard capability (PRESERVED)
    $client_role = get_role( 'client' );
    if ( $client_role ) {
        $client_role->add_cap( 'cpd_view_dashboard' );
        // AI Intelligence and other capabilities for clients
        $client_role->add_cap( 'cpd_request_intelligence' );
        $client_role->add_cap( 'cpd_view_own_data' );
        $client_role->add_cap( 'cpd_export_own_data' );
    }
}

/**
 * The main function responsible for initializing the plugin.
 * PRESERVED from v1.0.0 + NEW AI Intelligence initialization
 */
function cpd_dashboard_run() {
    // Initialize singleton FIRST - this is critical for premium features
    $plugin = CPD_Dashboard::get_instance();
    $plugin->run();

    // Instantiate your admin class here (PRESERVED)
    $plugin_name = 'reports';
    $version = CPD_DASHBOARD_VERSION;
    $cpd_admin = new CPD_Admin( $plugin_name, $version );
    
    // Load and initialize the email handler hooks (PRESERVED)
    require_once CPD_DASHBOARD_PLUGIN_DIR . 'includes/class-cpd-email-handler.php';
    
    // Register the cron hook - THIS IS THE KEY ADDITION (PRESERVED)
    add_action( 'cpd_daily_crm_email_event', array( 'CPD_Email_Handler', 'daily_crm_email_cron_callback' ) );
    
    // Initialize AI Intelligence admin settings (only in admin)
    if ( is_admin() && class_exists( 'CPD_Admin_Intelligence_Settings' ) ) {
        new CPD_Admin_Intelligence_Settings();
    }
}

// Run at priority 5 to ensure singleton is initialized before admin_menu hooks
add_action( 'plugins_loaded', 'cpd_dashboard_run', 5 );

/**
 * Load DirectReach v2 Premium Features
 * 
 * Campaign Builder and Reading the Room are loaded as sub-plugins
 * Only accessible to premium tier clients and admins
 * 
 * @since 2.0.0
 */
/**
 * Load DirectReach v2 Premium Features
 * 
 * Campaign Builder and Reading the Room are loaded as sub-plugins
 * Only accessible to premium tier clients and admins
 * 
 * @since 2.0.0
 */
    function cpd_load_premium_features() {
        // Prevent multiple loads
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        
        // Load Campaign Builder
        $campaign_builder_file = CPD_DASHBOARD_PLUGIN_DIR . 'RTR/campaign-builder/directreach-campaign-builder.php';
        
        if (file_exists($campaign_builder_file)) {
            require_once $campaign_builder_file;
            
            // Call init function directly
            if (function_exists('dr_campaign_builder_init')) {
                dr_campaign_builder_init();
            }
        } else {
            error_log('CPD: Campaign Builder file not found: ' . $campaign_builder_file);
        }
        
        // Load Scoring System
        $scoring_system_file = CPD_DASHBOARD_PLUGIN_DIR . 'RTR/scoring-system/directreach-scoring-system.php';
        
        if (file_exists($scoring_system_file)) {
            require_once $scoring_system_file;
            
            // Call init function directly (don't rely on plugins_loaded hook)
            if (function_exists('directreach_scoring_system')) {
                directreach_scoring_system();
            }
            
            error_log('CPD: Scoring System loaded and initialized');
        } else {
            error_log('CPD: ERROR - Scoring System file not found: ' . $scoring_system_file);
        }

        // Load Reading the Room
        $rtr_file = CPD_DASHBOARD_PLUGIN_DIR . 'RTR/reading-the-room/directreach-reading-room.php';
        
        if (file_exists($rtr_file)) {
            require_once $rtr_file;
            
            // Initialize RTR Dashboard
            if (function_exists('DirectReach\ReadingTheRoom\init_reading_room_dashboard')) {
                \DirectReach\ReadingTheRoom\init_reading_room_dashboard();
            }
            
            error_log('CPD: Reading the Room loaded successfully');
        } else {
            error_log('CPD: ERROR - Reading the Room file not found: ' . $rtr_file);
        }        
    
  
}

// Load premium features after plugins are loaded
add_action( 'plugins_loaded', 'cpd_load_premium_features', 20 );

/**
 * Register Reading the Room menu
 * Campaign Builder registers its own menu via sub-plugin
 */
function cpd_register_tier_based_menus() {
    $current_user_id = get_current_user_id();
    
    // v2 Reading the Room - Admin or Premium tier clients ONLY
    if ( CPD_Access_Control::is_admin_user( $current_user_id ) || 
         CPD_Access_Control::has_v2_access( $current_user_id ) ) {

        // add_menu_page(
        //     'Reading the Room',
        //    'Reading the Room',
        //    'manage_options',
        //    'dr-reading-room',
        //    array($this, 'render_page_fallback'),
        //    'dashicons-visibility',
        //    27
        //);            

    }
}
add_action( 'admin_menu', 'cpd_register_tier_based_menus', 20 );

/**
 * Reading the Room page callback
 */
function cpd_render_rtr_dashboard_page() {
    $current_user_id = get_current_user_id();
    
    // Double-check access
    if ( ! CPD_Access_Control::is_admin_user( $current_user_id ) && 
         ! CPD_Access_Control::has_v2_access( $current_user_id ) ) {
        wp_die(
            __( 'You do not have permission to access this page.', 'cpd' ),
            __( 'Access Denied', 'cpd' ),
            array( 'response' => 403 )
        );
    }
    
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Reading the Room Dashboard', 'cpd' ); ?></h1>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'RTR Dashboard interface coming in Phase 5. Premium access verified!', 'cpd' ); ?></p>
        </div>
        <?php if ( CPD_Access_Control::is_admin_user( $current_user_id ) ) : ?>
            <p><strong>Debug Info:</strong></p>
            <ul>
                <li>User ID: <?php echo esc_html( $current_user_id ); ?></li>
                <li>Account ID: <?php echo esc_html( CPD_Access_Control::get_user_account_id( $current_user_id ) ?: 'N/A' ); ?></li>
                <li>Tier: <?php echo esc_html( CPD_Access_Control::get_user_tier( $current_user_id ) ?: 'N/A' ); ?></li>
                <li>RTR Enabled: <?php echo CPD_Access_Control::has_v2_access( $current_user_id ) ? 'Yes' : 'No'; ?></li>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Protect premium URLs from unauthorized access
 * Campaign Builder protection is handled by its own plugin
 */
function cpd_protect_tier_based_urls() {
    if ( ! is_admin() ) {
        return;
    }
    
    $current_user_id = get_current_user_id();
    $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
    
    // Only protect RTR page (Campaign Builder protects itself)
    if ( $page === 'cpd-reading-room' ) {
        // Admins always have access
        if ( CPD_Access_Control::is_admin_user( $current_user_id ) ) {
            return;
        }
        
        // Check v2 access for non-admins
        if ( ! CPD_Access_Control::has_v2_access( $current_user_id ) ) {
            // Check if subscription is expired
            if ( CPD_Access_Control::is_subscription_expired( $current_user_id ) ) {
                wp_die(
                    __( 'Your premium subscription has expired. Please contact your administrator to renew.', 'cpd' ),
                    __( 'Subscription Expired', 'cpd' ),
                    array( 'response' => 403 )
                );
            }
            
            // Redirect to v1 dashboard with error
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page' => 'cpd-dashboard',
                        'premium_required' => '1',
                    ),
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }
    }
    
    // Check if basic user trying to access v1
    if ( $page === 'cpd-dashboard' || $page === 'cpd-dashboard-management' ) {
        // Admins always have access
        if ( CPD_Access_Control::is_admin_user( $current_user_id ) ) {
            return;
        }
        
        // Premium users should NOT access v1
        if ( ! CPD_Access_Control::has_v1_access( $current_user_id ) ) {
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page' => 'cpd-reading-room',
                        'v1_not_available' => '1',
                    ),
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }
    }
}
add_action( 'admin_init', 'cpd_protect_tier_based_urls' );

/**
 * Show premium upgrade notice on v1 dashboard for basic clients
 */
function cpd_show_premium_upgrade_notice() {
    $screen = get_current_screen();
    
    // Only show on v1 dashboard
    if ( ! $screen || $screen->id !== 'toplevel_page_campaign-performance-dashboard' ) {
        return;
    }
    
    // Don't show to admins
    if ( current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $dashboard = CPD_Dashboard::get_instance();
    
    // Only show if user doesn't have premium access
    if ( ! $dashboard->has_rtr_access() ) {
        ?>
        <div class="notice notice-info is-dismissible">
            <h3><?php esc_html_e( 'Upgrade to Premium', 'cpd' ); ?></h3>
            <p><?php esc_html_e( 'Unlock Reading the Room and Campaign Builder features with a premium subscription.', 'cpd' ); ?></p>
            <p>
                <strong><?php esc_html_e( 'Premium features include:', 'cpd' ); ?></strong>
            </p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><?php esc_html_e( 'Automatic prospect qualification', 'cpd' ); ?></li>
                <li><?php esc_html_e( '3-stage nurture workflow (Problem → Solution → Offer)', 'cpd' ); ?></li>
                <li><?php esc_html_e( 'Email template system', 'cpd' ); ?></li>
                <li><?php esc_html_e( 'Real-time engagement tracking', 'cpd' ); ?></li>
                <li><?php esc_html_e( 'Advanced analytics', 'cpd' ); ?></li>
            </ul>
            <p>
                <a href="#" class="button button-primary">
                    <?php esc_html_e( 'Contact Sales', 'cpd' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    // Show error if they tried to access premium feature
    if ( isset( $_GET['premium_required'] ) ) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong><?php esc_html_e( 'Premium Feature Required', 'cpd' ); ?></strong><br>
                <?php esc_html_e( 'This feature requires a premium subscription. Please contact your administrator or upgrade your account.', 'cpd' ); ?>
            </p>
        </div>
        <?php
    }
}
add_action( 'admin_notices', 'cpd_show_premium_upgrade_notice' );

// Add URL rewrite rules for Hot List Settings
add_action( 'init', 'cpd_add_hot_list_rewrite_rules' );
add_filter( 'query_vars', 'cpd_add_hot_list_query_vars' );
add_action( 'parse_request', 'cpd_parse_hot_list_request' );
add_action( 'template_redirect', 'cpd_handle_hot_list_settings_template' );

function cpd_add_hot_list_rewrite_rules() {
    // Production URL structure
    add_rewrite_rule(
        '^directreach/reports/hot-list-settings/?$',
        'index.php?cpd_hot_list_settings=1',
        'top'
    );
    
    // Dev URL structure
    add_rewrite_rule(
        '^dashboarddev/campaign-dashboard/hot-list-settings/?$',
        'index.php?cpd_hot_list_settings=1',
        'top'
    );
    
    // Generic fallback
    add_rewrite_rule(
        '^campaign-dashboard/hot-list-settings/?$',
        'index.php?cpd_hot_list_settings=1',
        'top'
    );
}

function cpd_add_hot_list_query_vars( $vars ) {
    $vars[] = 'cpd_hot_list_settings';
    return $vars;
}

function cpd_parse_hot_list_request( $wp ) {
    // PHP 8.1+ compatibility: Ensure REQUEST_URI is a string
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( $request_uri !== '' && (
         strpos( $request_uri, '/directreach/reports/hot-list-settings' ) !== false ||
         strpos( $request_uri, '/dashboarddev/campaign-dashboard/hot-list-settings' ) !== false ||
         strpos( $request_uri, '/campaign-dashboard/hot-list-settings' ) !== false ) ) {
        
        // Manually set the query var
        $wp->query_vars['cpd_hot_list_settings'] = '1';
        
        // Prevent WordPress from trying to find a page/post
        $wp->query_vars['pagename'] = '';
        $wp->query_vars['name'] = '';
    }
}

function cpd_handle_hot_list_settings_template() {
    $hot_list_settings = get_query_var( 'cpd_hot_list_settings', false );
    
    if ( $hot_list_settings === '1' || $hot_list_settings === 1 || $hot_list_settings === true ) {
        $file_path = CPD_DASHBOARD_PLUGIN_DIR . 'public/hot-list-settings.php';
        
        if ( ! file_exists( $file_path ) ) {
            wp_die( 'Hot List Settings page not found.' );
        }
        
        include $file_path;
        exit;
    }
}

/**
 * Plugin uninstall cleanup
 */
$uninstall_file = __FILE__ ?: '';
if ($uninstall_file !== '') {
    register_uninstall_hook( $uninstall_file, 'cpd_dashboard_uninstall' );
}

function cpd_dashboard_uninstall() {
    // Clean up options
    delete_option( 'cpd_dashboard_version' );
    delete_option( 'cpd_database_version' );
    
    // Clean up intelligence settings
    delete_option( 'cpd_intelligence_webhook_url' );
    delete_option( 'cpd_makecom_api_key' );
    delete_option( 'cpd_intelligence_rate_limit' );
    delete_option( 'cpd_intelligence_timeout' );
    delete_option( 'cpd_intelligence_auto_generate_crm' );
    delete_option( 'cpd_intelligence_processing_method' );
    delete_option( 'cpd_intelligence_batch_size' );
    delete_option( 'cpd_intelligence_crm_timeout' );
    delete_option( 'cpd_intelligence_default_enabled' );
    delete_option( 'cpd_intelligence_require_context' );
    
    // Clear scheduled events
    wp_clear_scheduled_hook( 'cpd_intelligence_cleanup' );
    
    // Note: We don't delete database tables on uninstall to preserve data
    // Tables should only be deleted if the admin explicitly chooses to do so
}

/**
 * Add admin notices for intelligence feature
 */
add_action( 'admin_notices', 'cpd_intelligence_admin_notices' );

function cpd_intelligence_admin_notices() {
    // Only show on CPD pages
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'cpd' ) === false ) {
        return;
    }
    
    // Check if intelligence is configured
    if ( class_exists( 'CPD_Intelligence' ) ) {
        $intelligence = new CPD_Intelligence();
        
        if ( ! $intelligence->is_intelligence_configured() ) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . __( 'AI Intelligence Feature:', 'cpd-dashboard' ) . '</strong> ';
            echo sprintf( 
                __( 'Configure the intelligence settings to enable AI features. <a href="%s">Go to Intelligence Settings</a>', 'cpd-dashboard' ),
                admin_url( 'admin.php?page=cpd-intelligence-settings' )
            );
            echo '</p>';
            echo '</div>';
        }
    }
}

/**
 * Load plugin text domain for internationalization
 */
add_action( 'plugins_loaded', 'cpd_dashboard_load_textdomain' );

function cpd_dashboard_load_textdomain() {
    $plugin_file = __FILE__ ?: '';
    if ($plugin_file !== '') {
        load_plugin_textdomain( 'cpd-dashboard', false, dirname( plugin_basename( $plugin_file ) ) . '/languages/' );
    }
}

/**
 * Enqueue intelligence-related scripts and styles
 */
add_action( 'admin_enqueue_scripts', 'cpd_enqueue_intelligence_assets' );

function cpd_enqueue_intelligence_assets( $hook ) {
    // Only load on CPD dashboard pages
    if ( strpos( $hook, 'cpd-dashboard' ) === false && strpos( $hook, 'cpd-intelligence' ) === false ) {
        return;
    }
    
    // Enqueue jQuery for AJAX functionality
    wp_enqueue_script( 'jquery' );
    
    // Add localization for AJAX
    wp_localize_script( 'jquery', 'cpd_ajax', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'cpd_dashboard_nonce' ),
        'strings' => array(
            'requesting_intelligence' => __( 'Requesting Intelligence...', 'cpd-dashboard' ),
            'intelligence_requested' => __( 'Intelligence Requested', 'cpd-dashboard' ),
            'intelligence_failed' => __( 'Intelligence Request Failed', 'cpd-dashboard' ),
            'intelligence_processing' => __( 'Processing...', 'cpd-dashboard' ),
            'intelligence_completed' => __( 'Intelligence Available', 'cpd-dashboard' ),
            'intelligence_error' => __( 'Error', 'cpd-dashboard' ),
        ),
    ) );
}