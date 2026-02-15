<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package Journey_Circle
 */

class Journey_Circle_Admin {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $plugin_name       The name of this plugin.
     * @param    string    $version    The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    // ========================================================================
    // FULL-PAGE RENDERING (matches Campaign Builder pattern)
    // ========================================================================

    /**
     * Intercept Creator page load and render standalone full-page interface.
     *
     * Mirrors Campaign Builder's maybe_render_custom_page() approach:
     * outputs a complete HTML document and calls exit() so WordPress
     * admin chrome (sidebar, admin bar) never renders.
     *
     * Only the Creator page gets this treatment — Dashboard and Settings
     * remain standard WP admin pages.
     *
     * @since 2.0.0
     */
    public function maybe_render_full_page() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'journey-circle-creator' ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                __( 'Sorry, you are not allowed to access this page.', 'journey-circle' ),
                __( 'Access Denied', 'journey-circle' ),
                array( 'response' => 403 )
            );
        }

        // Enqueue all assets (runs wp_enqueue_style/script)
        $this->enqueue_creator_styles();
        $this->enqueue_creator_scripts();

        // Render complete HTML page and exit — WP admin chrome never loads
        $this->render_full_page();
        exit;
    }

    /**
     * Render the complete standalone HTML page.
     *
     * @since 2.0.0
     */
    private function render_full_page() {
        $this->render_html_head();
        $this->render_html_body();
    }

    /**
     * Output <!DOCTYPE html> through </head>.
     *
     * @since 2.0.0
     */
    private function render_html_head() {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'Journey Circle Creator', 'journey-circle' ); ?> | <?php bloginfo( 'name' ); ?></title>

            <?php
            // Print all enqueued styles
            wp_print_styles();

            // Print head scripts (includes wp_localize_script data)
            wp_print_head_scripts();
            ?>

            <style>
                /* Remove any residual WordPress admin chrome */
                #wpadminbar { display: none !important; }
                html { 
                    margin-top: 0 !important;
                    width: 100%;
                    overflow-y: scroll; /* Always show scrollbar to prevent layout shift */
                }
                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    width: 100%;
                    min-width: 0;
                    background: var(--bg-color, #eef2f6);
                    font-family: var(--font-family, 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif);
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                }
                *, *::before, *::after {
                    box-sizing: border-box;
                }
            </style>
        </head>
        <?php
    }

    /**
     * Output <body> through </html>.
     *
     * @since 2.0.0
     */
    private function render_html_body() {
        ?>
        <body class="journey-circle-page">
            <?php $this->render_page_content(); ?>

            <?php
            // Print footer scripts (all JS modules load here)
            wp_print_footer_scripts();
            ?>
        </body>
        </html>
        <?php
    }

    /**
     * Include the creator template.
     *
     * @since 2.0.0
     */
    private function render_page_content() {
        $template = JOURNEY_CIRCLE_PLUGIN_DIR . 'admin/views/journey-circle/journey-circle-creator.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<div style="padding:60px;text-align:center;max-width:600px;margin:100px auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">';
            echo '<h1 style="color:#e74c3c;">Template Not Found</h1>';
            echo '<p style="color:#666;">The Journey Circle creator template could not be loaded.</p>';
            echo '<code style="display:block;padding:10px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;font-size:12px;margin-top:15px;">' . esc_html( $template ) . '</code>';
            echo '<p style="margin-top:20px;"><a href="' . esc_url( admin_url( 'admin.php?page=dr-campaign-builder' ) ) . '" class="btn btn-primary" style="display:inline-block;padding:10px 20px;background:#2c435d;color:#fff;text-decoration:none;border-radius:6px;">Return to Campaign Builder</a></p>';
            echo '</div>';
        }
    }

    /**
     * Enqueue styles specifically for the Creator standalone page.
     * Called from maybe_render_full_page(), NOT from admin_enqueue_scripts.
     *
     * @since 2.0.0
     */
    private function enqueue_creator_styles() {
        // ── Campaign Builder shared design tokens ──
        // Look for CB variables.css in common plugin locations
        $cb_plugin_dir = WP_PLUGIN_DIR . '/directreach-campaign-builder/';
        $cb_plugin_url = plugins_url( '/', $cb_plugin_dir . 'directreach-campaign-builder.php' );

        // Alternative: CB might be installed at a different slug
        if ( ! is_dir( $cb_plugin_dir ) ) {
            // Try to find it via the defined constant if Campaign Builder is loaded
            if ( defined( 'DR_CB_PLUGIN_DIR' ) ) {
                $cb_plugin_dir = DR_CB_PLUGIN_DIR;
                $cb_plugin_url = defined( 'DR_CB_PLUGIN_URL' ) ? DR_CB_PLUGIN_URL : plugins_url( '/', $cb_plugin_dir . 'x.php' );
            }
        }

        if ( is_dir( $cb_plugin_dir ) ) {
            // CB design token variables
            wp_enqueue_style(
                'dr-cb-variables',
                $cb_plugin_url . 'admin/css/variables.css',
                array(),
                $this->version
            );

            // CB base styles (header, buttons, breadcrumb, notifications)
            wp_enqueue_style(
                'dr-cb-base',
                $cb_plugin_url . 'admin/css/base.css',
                array( 'dr-cb-variables' ),
                $this->version
            );
        }

        // Google Fonts — Montserrat (used by CB)
        wp_enqueue_style(
            'google-fonts-montserrat',
            'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap',
            array(),
            null
        );

        // Font Awesome (needed for icons in the shared header)
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
            array(),
            '5.15.4'
        );

        // ── Journey Circle styles (depend on CB tokens) ──
        $cb_deps = is_dir( $cb_plugin_dir ) ? array( 'dr-cb-base' ) : array();

        wp_enqueue_style(
            $this->plugin_name,
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/css/journey-circle-admin.css',
            $cb_deps,
            $this->version
        );

        wp_enqueue_style(
            $this->plugin_name . '-main',
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/css/journey-circle.css',
            array( $this->plugin_name ),
            $this->version
        );

        // Step-specific styles
        $step_css_files = array(
            '-steps456'   => 'journey-circle-steps456.css',
            '-sa-actions' => 'service-area-actions.css',
            '-steps78'    => 'journey-circle-steps78.css',
            '-canvas'     => 'journey-circle-canvas.css',
            '-ai'         => 'journey-circle-ai.css',
            '-asset'      => 'journey-circle-asset.css',
            '-completion' => 'journey-completion.css',
        );

        foreach ( $step_css_files as $suffix => $filename ) {
            wp_enqueue_style(
                $this->plugin_name . $suffix,
                JOURNEY_CIRCLE_PLUGIN_URL . 'admin/css/' . $filename,
                array( $this->plugin_name . '-main' ),
                $this->version
            );
        }
    }

    /**
     * Enqueue scripts specifically for the Creator standalone page.
     * Called from maybe_render_full_page(), NOT from admin_enqueue_scripts.
     *
     * @since 2.0.0
     */
    private function enqueue_creator_scripts() {
        // jQuery (WordPress bundled)
        wp_enqueue_script( 'jquery' );

        // Base admin script
        wp_enqueue_script(
            $this->plugin_name,
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/journey-circle-admin.js',
            array( 'jquery' ),
            $this->version,
            true
        );

        // Core workflow module
        wp_enqueue_script(
            $this->plugin_name . '-workflow',
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/journey-circle-workflow.js',
            array( 'jquery', $this->plugin_name ),
            $this->version,
            true
        );

        // Canvas renderer
        wp_enqueue_script(
            $this->plugin_name . '-renderer',
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/journey-circle-renderer.js',
            array( $this->plugin_name . '-workflow' ),
            $this->version,
            true
        );

        // Manager modules
        $manager_modules = array(
            '-brain-content'    => 'brain-content-manager.js',
            '-service-area'     => 'service-area-manager.js',
            '-existing-assets'  => 'existing-assets-manager.js',
            '-problem-solution' => 'problem-solution-manager.js',
            '-steps567'         => 'steps567-manager.js',
            '-solution-offer'   => 'solution-offer-manager.js',
            '-step8-offer'      => 'step8-offer-manager.js',
        );
        foreach ( $manager_modules as $suffix => $filename ) {
            wp_enqueue_script(
                $this->plugin_name . $suffix,
                JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/' . $filename,
                array( $this->plugin_name . '-workflow' ),
                $this->version,
                true
            );
        }

        // Third-party libraries for content generation
        wp_enqueue_script(
            'pptxgenjs',
            'https://cdn.jsdelivr.net/gh/gitbrent/pptxgenjs@latest/dist/pptxgen.bundle.js',
            array(),
            null,
            true
        );
        wp_enqueue_script(
            'docxjs',
            'https://cdn.jsdelivr.net/npm/docx@8.5.0/build/index.umd.js',
            array(),
            '8.5.0',
            true
        );

        // Content Renderer
        wp_enqueue_script(
            $this->plugin_name . '-content-renderer',
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/content-renderer.js',
            array( 'jquery', 'pptxgenjs', 'docxjs' ),
            $this->version,
            true
        );

        // Step 9 - Asset Manager
        wp_enqueue_script(
            $this->plugin_name . '-step9-asset',
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/step9-asset-manager.js',
            array( $this->plugin_name . '-workflow', $this->plugin_name . '-content-renderer' ),
            $this->version,
            true
        );

        // Nano Banana Pro — AI slide image generator (optional)
        $slide_img_js = JOURNEY_CIRCLE_PLUGIN_DIR . 'admin/js/modules/slide-image-generator.js';
        if ( file_exists( $slide_img_js ) ) {
            wp_enqueue_script(
                $this->plugin_name . '-slide-image-generator',
                JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/slide-image-generator.js',
                array( 'jquery', $this->plugin_name . '-content-renderer' ),
                $this->version,
                true
            );
        }

        // Late-step modules
        $late_modules = array(
            '-step10-link'      => 'step10-link-assets.js',
            '-step11-complete'  => 'step11-complete.js',
            '-ai-title'         => 'ai-title-manager.js',
            '-asset-creator'    => 'asset-creator.js',
            '-completion'       => 'journey-completion.js',
            '-client-extension' => 'client-manager-journey-extension.js',
        );
        foreach ( $late_modules as $suffix => $filename ) {
            wp_enqueue_script(
                $this->plugin_name . $suffix,
                JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/modules/' . $filename,
                array( $this->plugin_name . '-workflow' ),
                $this->version,
                true
            );
        }

        // Localize script with REST API data
        wp_localize_script(
            $this->plugin_name,
            'journeyCircleData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'journey_circle_nonce' ),
                'restUrl'   => rest_url( 'journey-circle/v1' ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'pluginUrl' => JOURNEY_CIRCLE_PLUGIN_URL,
            )
        );
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        // Only load on Journey Circle pages
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'journey-circle' ) === false ) {
            return;
        }

        // Skip the creator page — it's handled by the standalone full-page renderer
        if ( strpos( $screen->id, 'journey-circle-creator' ) !== false ) {
            return;
        }

        // Base admin styles (dashboard, settings pages)
        wp_enqueue_style(
            $this->plugin_name,
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/css/journey-circle-admin.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        // Only load on Journey Circle pages
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'journey-circle' ) === false ) {
            return;
        }

        // Skip the creator page — it's handled by the standalone full-page renderer
        if ( strpos( $screen->id, 'journey-circle-creator' ) !== false ) {
            return;
        }

        // Base admin script (dashboard, settings pages)
        wp_enqueue_script(
            $this->plugin_name,
            JOURNEY_CIRCLE_PLUGIN_URL . 'admin/js/journey-circle-admin.js',
            array( 'jquery' ),
            $this->version,
            true
        );

        // Localize script with REST API data
        wp_localize_script(
            $this->plugin_name,
            'journeyCircleData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'journey_circle_nonce' ),
                'restUrl'   => rest_url( 'journey-circle/v1' ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'pluginUrl' => JOURNEY_CIRCLE_PLUGIN_URL,
            )
        );
    }

    /**
     * Add menu items to WordPress admin.
     *
     * @since    1.0.0
     */
    public function add_plugin_admin_menu() {
        // Main menu
        add_menu_page(
            __( 'Journey Circle', 'journey-circle' ),
            __( 'Journey Circle', 'journey-circle' ),
            'manage_options',
            'journey-circle',
            array( $this, 'display_dashboard_page' ),
            'dashicons-networking',
            25
        );

        // Dashboard submenu
        add_submenu_page(
            'journey-circle',
            __( 'Dashboard', 'journey-circle' ),
            __( 'Dashboard', 'journey-circle' ),
            'manage_options',
            'journey-circle',
            array( $this, 'display_dashboard_page' )
        );

        // Journey Circle Creator submenu
        add_submenu_page(
            'journey-circle',
            __( 'Create Journey Circle', 'journey-circle' ),
            __( 'Create New', 'journey-circle' ),
            'manage_options',
            'journey-circle-creator',
            array( $this, 'display_creator_page' )
        );

        // Settings submenu
        add_submenu_page(
            'journey-circle',
            __( 'Settings', 'journey-circle' ),
            __( 'Settings', 'journey-circle' ),
            'manage_options',
            'journey-circle-settings',
            array( $this, 'display_settings_page' )
        );
    }

    /**
     * Display the dashboard page.
     *
     * @since    1.0.0
     */
    public function display_dashboard_page() {
        include_once JOURNEY_CIRCLE_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Display the Journey Circle Creator page.
     *
     * @since    1.0.0
     */
    public function display_creator_page() {
        include_once JOURNEY_CIRCLE_PLUGIN_DIR . 'admin/views/journey-circle/journey-circle-creator.php';
    }

    /**
     * Display the settings page.
     *
     * @since    1.0.0
     */
    public function display_settings_page() {
        include_once JOURNEY_CIRCLE_PLUGIN_DIR . 'admin/views/settings.php';
    }
}