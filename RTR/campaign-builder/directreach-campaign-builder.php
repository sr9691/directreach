<?php
/**
 * Plugin Name: DirectReach Campaign Builder
 * Description: Campaign creation and management for DirectReach v2 Premium
 * Version: 2.0.0
 * Author: DirectReach
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define constants ONLY ONCE with type safety
if (!defined('DR_CB_VERSION')) {
    define('DR_CB_VERSION', '2.0.0');
}

if (!defined('DR_CB_PLUGIN_DIR')) {
    define('DR_CB_PLUGIN_DIR', plugin_dir_path(__FILE__) ?: __DIR__ . '/');
}

if (!defined('DR_CB_PLUGIN_URL')) {
    define('DR_CB_PLUGIN_URL', plugin_dir_url(__FILE__) ?: plugins_url('/', __FILE__));
}

/**
 * Initialize Campaign Builder
 * This is called by the main plugin at the appropriate time
 */
function dr_campaign_builder_init() {
    // Prevent double initialization
    if (defined('DR_CB_INITIALIZED')) {
        return;
    }
    define('DR_CB_INITIALIZED', true);
    
    // Verify constants are strings (PHP 8.1+ compatibility)
    if (!is_string(DR_CB_PLUGIN_DIR) || !is_string(DR_CB_PLUGIN_URL)) {
        error_log('DR_CB Bootstrap: ERROR - Plugin constants are invalid types');
        return;
    }
    
    // Check if class file exists
    $plugin_dir = defined('DR_CB_PLUGIN_DIR') && DR_CB_PLUGIN_DIR ? DR_CB_PLUGIN_DIR : __DIR__ . '/';
    $class_file = $plugin_dir . 'includes/class-campaign-builder.php';
    
    if (!file_exists($class_file)) {
        error_log('DR_CB Bootstrap: ERROR - Class file not found: ' . $class_file);
        return;
    }
    
    // Load the core Campaign Builder class
    require_once $class_file;
    
    // Verify class was loaded
    if (!class_exists('DR_Campaign_Builder')) {
        error_log('DR_CB Bootstrap: ERROR - Class does not exist after require!');
        return;
    }
    
    // Admin-only initialization
    if (is_admin()) {
        // Load Global Templates Admin
        $plugin_dir = defined('DR_CB_PLUGIN_DIR') && DR_CB_PLUGIN_DIR ? DR_CB_PLUGIN_DIR : __DIR__ . '/';
        $admin_file = $plugin_dir . 'includes/admin/class-global-templates-admin.php';
        if (file_exists($admin_file)) {
            require_once $admin_file;
        }

        // Load Scoring System
        $plugin_dir = defined('DR_CB_PLUGIN_DIR') && DR_CB_PLUGIN_DIR ? DR_CB_PLUGIN_DIR : __DIR__ . '/';
        $scoring_system_file = $plugin_dir . '../scoring-system/directreach-scoring-system.php';
        if (file_exists($scoring_system_file)) {
            require_once $scoring_system_file;
        } else {
            error_log('DR_CB Bootstrap: ERROR - Scoring system file not found: ' . $scoring_system_file);
        }
        
        // Load Journey Circle Creator
        $journey_circle_file = $plugin_dir . '../journey-circle/journey-circle.php';
        if (file_exists($journey_circle_file)) {
            require_once $journey_circle_file;
        } else {
            error_log('DR_CB Bootstrap: WARNING - Journey Circle file not found: ' . $journey_circle_file);
        }

        // Initialize AI Settings admin interface
        dr_ai_settings_admin_init();
    }

    // ALWAYS register REST API routes (not just in admin)
    add_action('rest_api_init', 'dr_ai_settings_register_api');

    // Initialize the plugin (singleton pattern)
    DR_Campaign_Builder::get_instance();
}

/**
 * Initialize AI Settings admin interface
 */
function dr_ai_settings_admin_init() {
    // Register admin menu
    add_action('admin_menu', 'dr_ai_settings_register_menu', 20);
    
    // Intercept page rendering
    add_action('admin_init', 'dr_ai_settings_maybe_render', 10);
}

/**
 * Register AI Settings menu page
 */
function dr_ai_settings_register_menu() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    add_menu_page(
        __('AI Configuration', 'directreach'),
        __('AI Configuration', 'directreach'),
        'manage_options',
        'dr-ai-settings',
        'dr_ai_settings_render_fallback',
        'dashicons-robot',
        29
    );
    
    // Hide from main menu (accessed via Settings dropdown)
    remove_menu_page('dr-ai-settings');
}

/**
 * Fallback render (rarely called)
 */
function dr_ai_settings_render_fallback() {
    echo '<div style="padding: 40px;"><p>Loading AI Settings...</p></div>';
}

/**
 * Maybe render AI Settings page
 */
function dr_ai_settings_maybe_render() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'dr-ai-settings') {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(
            __('Sorry, you are not allowed to access this page.', 'directreach'),
            __('Access Denied', 'directreach'),
            array('response' => 403)
        );
    }
    
    // Enqueue assets
    dr_ai_settings_enqueue_assets();
    
    // Render full page
    dr_ai_settings_render_page();
    exit;
}

/**
 * Enqueue AI Settings assets
 */
function dr_ai_settings_enqueue_assets() {
    // Font Awesome
    wp_enqueue_style(
        'font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
        array(),
        '5.15.4'
    );
    
    // Campaign Builder base styles
    $plugin_url = defined('DR_CB_PLUGIN_URL') && DR_CB_PLUGIN_URL ? DR_CB_PLUGIN_URL : plugins_url('/', __FILE__);
    wp_enqueue_style(
        'dr-cb-variables',
        $plugin_url . 'admin/css/variables.css',
        array(),
        DR_CB_VERSION
    );
    
    wp_enqueue_style(
        'dr-cb-base',
        $plugin_url . 'admin/css/base.css',
        array('dr-cb-variables'),
        DR_CB_VERSION
    );
    
    // AI Settings specific styles
    wp_enqueue_style(
        'dr-ai-settings',
        $plugin_url . '/admin/css/ai-settings.css',
        array('dr-cb-variables'),
        DR_CB_VERSION
    );
    
    // AI Settings JavaScript
    wp_enqueue_script(
        'dr-ai-settings',
        $plugin_url . 'admin/js/modules/ai-settings-manager.js',
        array(),
        DR_CB_VERSION,
        true
    );
    
    // Inject configuration
    $config = array(
        'apiUrl' => rest_url('directreach/v2'),
        'nonce' => wp_create_nonce('wp_rest'),
        'strings' => array(
            'saveSuccess' => __('Settings saved successfully', 'directreach'),
            'saveError' => __('Failed to save settings', 'directreach'),
            'testSuccess' => __('Connection successful', 'directreach'),
            'testError' => __('Connection failed', 'directreach'),
            'noApiKey' => __('Please configure your Gemini API key', 'directreach')
        )
    );
    
    wp_add_inline_script(
        'dr-ai-settings',
        'window.drAIConfig = ' . wp_json_encode($config) . ';',
        'before'
    );
    
    // Add module type
    add_filter('script_loader_tag', function($tag, $handle) {
        if ($handle === 'dr-ai-settings' && is_string($tag)) {
            return str_replace(' src', ' type="module" src', $tag);
        }
        return $tag;
    }, 10, 2);
}

/**
 * Render AI Settings page
 */
function dr_ai_settings_render_page() {
    $page_title = __('AI Email Generation Settings', 'directreach');
    $page_description = __('Configure Google Gemini API for AI-powered email generation.', 'directreach');
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html($page_title); ?> | <?php bloginfo('name'); ?></title>
        
        <?php
        wp_print_styles();
        wp_print_head_scripts();
        ?>
        
        <style>
            #wpadminbar { display: none !important; }
            html { margin-top: 0 !important; }
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                margin: 0 !important;
                padding: 0 !important;
                background: #f5f5f5;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
        </style>
    </head>
    <body class="rtr-page dr-ai-settings">
        <?php
        $plugin_dir = defined('DR_CB_PLUGIN_DIR') && DR_CB_PLUGIN_DIR ? DR_CB_PLUGIN_DIR : __DIR__ . '/';
        $template_file = $plugin_dir . 'admin/views/ai-settings.php';
        
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            ?>
            <div style="padding: 40px; text-align: center; max-width: 600px; margin: 100px auto; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <h1 style="color: #dc3545; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php _e('Template Error', 'directreach'); ?>
                </h1>
                <p style="color: #666; margin-bottom: 10px;">
                    <?php _e('The template file could not be found.', 'directreach'); ?>
                </p>
                <code style="display: block; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; font-size: 12px; color: #495057; word-break: break-all;">
                    <?php echo esc_html($template_file); ?>
                </code>
            </div>
            <?php
        }
        ?>
        
        <?php wp_print_footer_scripts(); ?>
    </body>
    </html>
    <?php
}

/**
 * Register AI Settings REST API routes
 */
function dr_ai_settings_register_api() {
    error_log('=== AI Settings: Registering REST API routes ===');
    
    $plugin_dir = defined('DR_CB_PLUGIN_DIR') && DR_CB_PLUGIN_DIR ? DR_CB_PLUGIN_DIR : __DIR__ . '/';
    $controller_file = $plugin_dir . 'includes/api/class-ai-settings-controller.php';
    
    error_log('AI Settings: Controller file path: ' . $controller_file);
    error_log('AI Settings: File exists? ' . (file_exists($controller_file) ? 'YES' : 'NO'));
    
    if (file_exists($controller_file)) {
        require_once $controller_file;
        error_log('AI Settings: Controller file loaded');
        
        if (class_exists('DirectReach\CampaignBuilder\API\AI_Settings_Controller')) {
            error_log('AI Settings: Controller class found');
            
            $controller = new \DirectReach\CampaignBuilder\API\AI_Settings_Controller();
            $controller->register_routes();
            
            error_log('AI Settings: Routes registered successfully');
        } else {
            error_log('AI Settings: ERROR - Controller class NOT found after require');
        }
    } else {
        error_log('AI Settings: ERROR - Controller file not found at: ' . $controller_file);
    }
}

// NOTE: This file is loaded by the main plugin, which calls dr_campaign_builder_init()
// DO NOT add any hooks here - the main plugin controls timing