<?php
/**
 * Reading the Room Dashboard - PRODUCTION READY
 *
 * All class names now match CSS and JavaScript expectations
 *
 * @package DirectReach
 * @subpackage ReadingTheRoom
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

show_admin_bar(false);

// Disable concatenation
if (!defined('CONCATENATE_SCRIPTS')) {
    define('CONCATENATE_SCRIPTS', false);
}

// Force direct script tag output
add_filter('print_scripts_array', function ($handles) {
    if (isset($_GET['page']) && $_GET['page'] === 'dr-reading-room') {
        global $wp_scripts;
        if (isset($wp_scripts->registered['rtr-main'])) {
            $wp_scripts->registered['rtr-main']->extra['type'] = 'module';
        }
    }
    return $handles;
});

// Enqueue CSS in correct order
wp_enqueue_style('directreach-variables', plugin_dir_url(__FILE__) . '../css/variables.css', [], '2.0.0');
wp_enqueue_style('directreach-base', plugin_dir_url(__FILE__) . '../css/base.css', [], '2.0.0');
wp_enqueue_style('directreach-header', plugin_dir_url(__FILE__) . '../css/header.css', [], '2.0.0');
wp_enqueue_style('directreach-room-cards', plugin_dir_url(__FILE__) . '../css/room-cards.css', [], '2.0.0');
wp_enqueue_style('directreach-room-details', plugin_dir_url(__FILE__) . '../css/room-details.css', [], '2.0.0');
wp_enqueue_style('directreach-prospect-list', plugin_dir_url(__FILE__) . '../css/prospect-list.css', [], '2.0.0');
wp_enqueue_style('directreach-pagination', plugin_dir_url(__FILE__) . '../css/pagination-styles.css', [], '2.0.0');
wp_enqueue_style('directreach-modals', plugin_dir_url(__FILE__) . '../css/modals.css', [], '2.0.0');
wp_enqueue_style('directreach-email-modal', plugin_dir_url(__FILE__) . '../css/email-modal.css', [], '2.0.0');
wp_enqueue_style('directreach-email-history-modal', plugin_dir_url(__FILE__) . '../css/email-history-modal.css', [], '2.0.0');
wp_enqueue_style('directreach-charts', plugin_dir_url(__FILE__) . '../css/charts.css', [], '2.0.0');
wp_enqueue_style('directreach-ui-utilities', plugin_dir_url(__FILE__) . '../css/ui.css', [], '2.0.0');
wp_enqueue_style('directreach-responsive', plugin_dir_url(__FILE__) . '../css/responsive.css', [], '2.0.0');
wp_enqueue_style('directreach-prospect-info-modal', plugin_dir_url(__FILE__) . '../css/prospect-info-modal.css', [], '2.0.0');
wp_enqueue_style('directreach-score-breakdown-modal', plugin_dir_url(__FILE__) . '../css/score-breakdown-modal.css', [], '2.0.0');
wp_enqueue_style('directreach-enrichment-modal', plugin_dir_url(__FILE__) . '../css/enrichment-modal.css', [], '1.0.0');

wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);

// Enqueue main JavaScript as ES module
/*
wp_enqueue_script(
    'rtr-dashboard-main',
    plugins_url('admin/js/main.js', dirname(__DIR__)),
    array(), // No dependencies
    '2.1.0',
    true // Load in footer
);
*/
// Localize config for JS - MUST be called AFTER wp_enqueue_script
wp_localize_script(
    'rtr-dashboard-main',
    'rtrDashboardConfig',
    array(
        'siteUrl'      => esc_url(get_site_url()),
        'nonce'        => wp_create_nonce('wp_rest'),
        'restUrl'      => esc_url(rest_url('directreach/v1/reading-room')), // change to v1/reading-room
        'apiUrl'       => esc_url(rest_url('directreach/v1/reading-room')),
        'emailApiUrl'  => esc_url(rest_url('directreach/v2')),              // for email endpoints
        'cisApiUrl'    => esc_url(get_option('directreach_cis_server_url', '')),
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'userId'       => get_current_user_id(),
        'userIsAdmin'  => current_user_can('manage_options'),
        'assets'       => array(
            'logo' => esc_url(plugins_url('assets/images/MEMO_Logo.png', dirname(dirname(dirname(dirname(__FILE__)))))),
            'seal' => esc_url(plugins_url('assets/images/MEMO_Seal.png', dirname(dirname(dirname(dirname(__FILE__))))))
        )
    )
);

// Mark the script as a module
add_filter('script_loader_tag', function($tag, $handle) {
    if ($handle === 'rtr-dashboard-main') {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}, 10, 2);

// Get current user info
$current_user = wp_get_current_user();
$user_initials = strtoupper(substr($current_user->first_name, 0, 1) . substr($current_user->last_name, 0, 1));
if (empty(trim($user_initials))) {
    $user_initials = strtoupper(substr($current_user->user_login, 0, 2));
}
$user_display_name = $current_user->display_name;
$user_role = !empty($current_user->roles) ? ucfirst($current_user->roles[0]) : 'User';

// Define required variables
$is_admin = current_user_can('manage_options');

// Get ONLY premium clients (subscription_tier = 'premium')
global $wpdb;
$clients_table = $wpdb->prefix . 'cpd_clients';
$clients = [];

if ($wpdb->get_var("SHOW TABLES LIKE '$clients_table'") == $clients_table) {
    $clients = $wpdb->get_results(
        "SELECT id, client_name 
         FROM $clients_table 
         WHERE subscription_tier = 'premium' 
         ORDER BY client_name ASC",
        ARRAY_A
    );
}

if (!is_array($clients)) {
    $clients = [];
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Reading the Room Dashboard', 'directreach'); ?></title>
    <style>
        /* CRITICAL: Hide admin bar completely */
        #wpadminbar {
            display: none !important;
        }
        html {
            margin-top: 0 !important;
        }
        * html body {
            margin-top: 0 !important;
        }
        body.reading-room-dashboard {
            margin: 0 !important;
            padding: 0 !important;
        }
        .reading-room-container {
            min-height: 100vh;
            width: 100%;
            max-width: 100%;
        }
        .main-content {
            width: 100%;
            max-width: 100%;
            padding: 0;
            box-sizing: border-box;
        }
        .content-area {
            width: 100%;
            max-width: 100%;
            margin: 0;
        }
    </style>
    <?php 
    remove_action('wp_head', '_admin_bar_bump_cb');
    wp_head(); 
    ?>
</head>

<body class="reading-room-dashboard">
    <div class="reading-room-container">
        <!-- Header -->
        <header class="content-header">
            <div class="header-title">
                <img src="<?php echo esc_url( plugin_dir_url( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . 'assets/images/MEMO_Logo.png' ); ?>" alt="DirectReach Logo" />
                <h1>Reading the Room Dashboard</h1>
                <span class="premium-badge">Premium</span>
            </div>

            <div class="header-controls">
                <div class="date-filter-group">
                    <select id="date-filter" class="date-filter">
                        <option value="7">Last 7 Days</option>
                        <option value="30" selected>Last 30 Days</option>
                        <option value="90">Last 90 Days</option>
                    </select>
                </div>

                <button id="refresh-dashboard" class="refresh-btn">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>

                <div class="user-info">
                    <div class="user-avatar"><?php echo esc_html($user_initials); ?></div>
                    <span><?php echo esc_html($user_display_name); ?></span>
                    <small><?php echo esc_html($user_role); ?></small>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="main-content">
            <section class="content-area">
                <!-- Pipeline Overview -->
                <div class="pipeline-overview">
                    <div class="pipeline-header">
                        <h2>Pipeline Overview</h2>
                        <div class="client-selector" <?php if (!$is_admin) echo 'style="display:none;"'; ?>>
                            <label for="client-select">Client:</label>
                            <select id="client-select" class="client-dropdown">
                                <option value="">All Clients</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo esc_attr($client['id']); ?>">
                                        <?php echo esc_html($client['client_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Room Cards Section -->
                    <div id="rtr-room-cards-container" class="rtr-room-cards-container">
                        <!-- JavaScript will populate room cards here -->
                    </div>

                <!-- Prospect Details Section - Room Detail Views -->
                <div class="room-details-section">
                    <div id="rtr-room-problem" class="room-detail-container">
                        <div class="room-detail-header">
                            <h3><i class="fas fa-exclamation-triangle"></i> Problem Room <span class="room-count-badge">0</span></h3>
                            <div style="display: flex; align-items: center; gap: 8px;">
                            <button class="rtr-batch-generate-btn" data-room="problem" title="Generate next email for all prospects in this room">
                                <i class="fas fa-magic"></i>
                            </button>                            
                            <select id="problem-room-sort" class="sort-dropdown" data-room="problem">
                                <option value="lead_score_desc">Lead Score (High → Low)</option>
                                <option value="lead_score_asc">Lead Score (Low → High)</option>
                                <option value="created_desc">Newest First</option>
                                <option value="created_asc">Oldest First</option>
                                <option value="updated_desc">Recently Updated</option>
                                <option value="company_asc">Company Name (A-Z)</option>
                                <option value="company_desc">Company Name (Z-A)</option>
                            </select></div>
                        </div>
                        <div class="rtr-prospect-list"></div>
                    </div>

                    <div id="rtr-room-solution" class="room-detail-container">
                        <div class="room-detail-header">
                            <h3><i class="fas fa-lightbulb"></i> Solution Room <span class="room-count-badge">0</span></h3>
                            <div style="display: flex; align-items: center; gap: 8px;">
                            <button class="rtr-batch-generate-btn" data-room="solution" title="Generate next email for all prospects in this room">
                                <i class="fas fa-magic"></i>
                            </button>
                            <select id="solution-room-sort" class="sort-dropdown" data-room="solution">
                                <option value="lead_score_desc">Lead Score (High → Low)</option>
                                <option value="lead_score_asc">Lead Score (Low → High)</option>
                                <option value="created_desc">Newest First</option>
                                <option value="created_asc">Oldest First</option>
                                <option value="updated_desc">Recently Updated</option>
                                <option value="company_asc">Company Name (A-Z)</option>
                                <option value="company_desc">Company Name (Z-A)</option>
                            </select></div>
                        </div>                        <div class="rtr-prospect-list"></div>
                    </div>

                    <div id="rtr-room-offer" class="room-detail-container">
                        <div class="room-detail-header">
                            <h3><i class="fas fa-handshake"></i> Offer Room <span class="room-count-badge">0</span></h3>
                            <div style="display: flex; align-items: center; gap: 8px;">
                            <button class="rtr-batch-generate-btn" data-room="offer" title="Generate next email for all prospects in this room">
                                <i class="fas fa-magic"></i>
                            </button>                                
                            <select id="offer-room-sort" class="sort-dropdown" data-room="offer">
                                <option value="lead_score_desc">Lead Score (High → Low)</option>
                                <option value="lead_score_asc">Lead Score (Low → High)</option>
                                <option value="created_desc">Newest First</option>
                                <option value="created_asc">Oldest First</option>
                                <option value="updated_desc">Recently Updated</option>
                                <option value="company_asc">Company Name (A-Z)</option>
                                <option value="company_desc">Company Name (Z-A)</option>
                            </select></div>
                        </div>
                        <div class="rtr-prospect-list"></div>
                    </div>

                </div>
            </section>
        </main>
    </div>

    <!-- Analytics Modal -->
    <div id="analytics-modal" class="rtr-modal" style="display: none;">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Room Analytics</h3>
                <button class="modal-close" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="summary-stats">
                    <!-- Stats will be inserted by JS -->
                </div>
                <div class="chart-container" style="height: 400px;">
                    <!-- Chart will be inserted by JS -->
                </div>
            </div>
        </div>
    </div>

<?php 
remove_action('wp_footer', 'wp_admin_bar_render', 1000);
wp_footer(); 
?>
    
</body>
</html>