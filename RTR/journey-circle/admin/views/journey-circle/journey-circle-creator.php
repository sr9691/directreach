<?php
/**
 * Journey Circle Creator - Main Template
 * 
 * Template for the 11-step Journey Circle workflow
 * Steps 1-3 implementation
 * 
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get client ID from URL parameter
$client_id = isset($_GET['client_id']) ? absint($_GET['client_id']) : 0;
$service_area_id = isset($_GET['service_area_id']) ? absint($_GET['service_area_id']) : 0;

if (!$client_id) {
    wp_die(__('Invalid client ID', 'directreach-campaign-builder'));
}

// Get client data
global $wpdb;
$client = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}cpd_clients WHERE id = %d",
    $client_id
));

if (!$client) {
    wp_die(__('Client not found', 'directreach-campaign-builder'));
}
?>

<div class="campaign-builder-wrap dr-journey-circle-creator">
    
    <!-- Header — Campaign Builder branded header -->
    <?php
    // Try to reuse Campaign Builder's shared admin-header partial
    $cb_header_path = WP_PLUGIN_DIR . '/directreach-campaign-builder/admin/views/partials/admin-header.php';
    if ( ! file_exists( $cb_header_path ) && defined( 'DR_CB_PLUGIN_DIR' ) ) {
        $cb_header_path = DR_CB_PLUGIN_DIR . 'admin/views/partials/admin-header.php';
    }

    if ( file_exists( $cb_header_path ) ) {
        $args = array(
            'page_badge'    => 'Journey Circle',
            'active_page'   => 'journey-circle-creator',
            'show_back_btn' => true,
        );
        include $cb_header_path;
    } else {
        // Self-contained fallback matching CB header structure
        $current_user  = wp_get_current_user();
        $user_initials = strtoupper( substr( $current_user->display_name, 0, 2 ) );
        $user_role     = ! empty( $current_user->roles ) ? ucfirst( $current_user->roles[0] ) : 'User';
        // Logo: try CB assets first, then JC's own
        $logo_url = plugins_url( 'assets/MEMO_Seal.png', WP_PLUGIN_DIR . '/directreach-campaign-builder/directreach-campaign-builder.php' );
        ?>
        <header class="admin-header">
            <div class="header-content">
                <div class="header-left">
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="DirectReach" class="header-logo"
                         onerror="this.style.display='none'">
                    <span class="admin-badge"><?php esc_html_e( 'Journey Circle', 'journey-circle' ); ?></span>
                </div>
                <div class="header-right">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dr-campaign-builder' ) ); ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> <?php esc_html_e( 'Back to Campaign Builder', 'journey-circle' ); ?>
                    </a>
                    <div class="admin-user-info">
                        <div class="user-avatar"><?php echo esc_html( $user_initials ); ?></div>
                        <div class="user-details">
                            <div class="user-name"><?php echo esc_html( $current_user->display_name ); ?></div>
                            <div class="user-role"><?php echo esc_html( $user_role ); ?></div>
                        </div>
                    </div>
                    <button class="logout-btn" onclick="window.location.href='<?php echo esc_url( wp_logout_url() ); ?>'">
                        <i class="fas fa-sign-out-alt"></i>
                        <span><?php esc_html_e( 'Logout', 'journey-circle' ); ?></span>
                    </button>
                </div>
            </div>
        </header>
        <?php
    }
    ?>

    <!-- Client Context Bar -->
    <div class="jc-client-bar">
        <div class="jc-client-bar-content">
            <span class="jc-client-bar-label">
                <i class="fas fa-building"></i>
                <?php printf( __( 'Client: %s', 'journey-circle' ), '<strong>' . esc_html( $client->client_name ) . '</strong>' ); ?>
            </span>
            <div class="save-indicator">
                <i class="fas fa-check-circle"></i>
                <span><?php esc_html_e( 'All changes saved', 'journey-circle' ); ?></span>
            </div>
        </div>
    </div>

    <!-- Progress Indicator -->
    <div class="jc-progress-container">
        <div class="jc-progress-bar">
            <div class="jc-progress-fill" style="width: 0%"></div>
        </div>
        <div class="jc-progress-steps">
            <div class="jc-progress-step active" data-step="1">
                <div class="jc-step-circle">1</div>
                <div class="jc-step-label"><?php esc_html_e('Brain Content', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="2">
                <div class="jc-step-circle">2</div>
                <div class="jc-step-label"><?php esc_html_e('Service Area', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="3">
                <div class="jc-step-circle">3</div>
                <div class="jc-step-label"><?php esc_html_e('Existing Assets', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="4">
                <div class="jc-step-circle">4</div>
                <div class="jc-step-label"><?php esc_html_e('Industries', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="5">
                <div class="jc-step-circle">5</div>
                <div class="jc-step-label"><?php esc_html_e('Primary Problem', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="6">
                <div class="jc-step-circle">6</div>
                <div class="jc-step-label"><?php esc_html_e('Problem Titles', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="7">
                <div class="jc-step-circle">7</div>
                <div class="jc-step-label"><?php esc_html_e('Solution Titles', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="8">
                <div class="jc-step-circle">8</div>
                <div class="jc-step-label"><?php esc_html_e('Offer Mapping', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="9">
                <div class="jc-step-circle">9</div>
                <div class="jc-step-label"><?php esc_html_e('Content Assets', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="10">
                <div class="jc-step-circle">10</div>
                <div class="jc-step-label"><?php esc_html_e('Link Assets', 'directreach-campaign-builder'); ?></div>
            </div>
            <div class="jc-progress-step" data-step="11">
                <div class="jc-step-circle">11</div>
                <div class="jc-step-label"><?php esc_html_e('Complete', 'directreach-campaign-builder'); ?></div>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="jc-main-content">
        
        <!-- Left Panel: Step Content -->
        <div class="jc-left-panel">
            
            <!-- Step 1: Brain Content -->
            <div class="jc-step-container" id="jc-step-1">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 1: Add Brain Content', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Upload resources that will help AI understand your service area and generate relevant content.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <!-- URL Input -->
                    <div class="jc-resource-section">
                        <h3>
                            <i class="fas fa-link"></i>
                            <?php esc_html_e('Add URL', 'directreach-campaign-builder'); ?>
                        </h3>
                        <div class="jc-url-input-group">
                            <input 
                                type="url" 
                                id="jc-url-input" 
                                class="jc-input jc-input-url" 
                                placeholder="https://example.com/your-content"
                            />
                            <button type="button" class="btn btn-primary jc-add-url-btn">
                                <i class="fas fa-plus"></i>
                                <?php esc_html_e('Add URL', 'directreach-campaign-builder'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Text Paste -->
                    <div class="jc-resource-section">
                        <h3>
                            <i class="fas fa-file-alt"></i>
                            <?php esc_html_e('Paste Text Content', 'directreach-campaign-builder'); ?>
                        </h3>
                        <button type="button" class="btn btn-secondary jc-paste-text-btn">
                            <i class="fas fa-clipboard"></i>
                            <?php esc_html_e('Paste Text', 'directreach-campaign-builder'); ?>
                        </button>
                    </div>

                    <!-- File Upload -->
                    <div class="jc-resource-section">
                        <h3>
                            <i class="fas fa-upload"></i>
                            <?php esc_html_e('Upload Files', 'directreach-campaign-builder'); ?>
                        </h3>
                        <div class="jc-file-upload-area" id="jc-file-upload-area">
                            <input 
                                type="file" 
                                id="jc-file-input" 
                                multiple 
                                accept=".pdf,.doc,.docx,.txt"
                                style="display: none;"
                            />
                            <label for="jc-file-input" class="jc-file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p><?php esc_html_e('Click to upload or drag and drop', 'directreach-campaign-builder'); ?></p>
                                <span class="jc-file-types"><?php esc_html_e('PDF, DOC, DOCX, TXT', 'directreach-campaign-builder'); ?></span>
                            </label>
                        </div>
                    </div>

                    <!-- Resource List -->
                    <div class="jc-resource-section">
                        <h3>
                            <i class="fas fa-list"></i>
                            <?php esc_html_e('Added Resources', 'directreach-campaign-builder'); ?>
                            <span class="jc-resource-count">0</span>
                        </h3>
                        <div id="jc-resource-list" class="jc-resource-list">
                            <!-- Resources will be dynamically added here -->
                            <div class="jc-empty-state">
                                <i class="fas fa-inbox"></i>
                                <p><?php esc_html_e('No resources added yet', 'directreach-campaign-builder'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Service Area -->
            <div class="jc-step-container" id="jc-step-2" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 2: Select or Create Service Area', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Choose an existing service area or create a new one for this journey circle.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <!-- Service Area Selection -->
                    <div class="jc-service-area-section">
                        <h3><?php esc_html_e('Existing Service Areas', 'directreach-campaign-builder'); ?></h3>
                        <div id="jc-service-area-list" class="jc-service-area-list">
                            <!-- Loading state -->
                            <div class="jc-loading-state">
                                <i class="fas fa-spinner fa-spin"></i>
                                <p><?php esc_html_e('Loading service areas...', 'directreach-campaign-builder'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Create New Service Area -->
                    <div class="jc-service-area-section">
                        <h3><?php esc_html_e('Create New Service Area', 'directreach-campaign-builder'); ?></h3>
                        <form id="jc-create-service-area-form" class="jc-form">
                            <div class="jc-form-group">
                                <label for="jc-sa-name">
                                    <?php esc_html_e('Service Area Name', 'directreach-campaign-builder'); ?>
                                    <span class="required">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    id="jc-sa-name" 
                                    name="name" 
                                    class="jc-input" 
                                    placeholder="e.g., Cloud Migration Services"
                                    required
                                />
                            </div>
                            <div class="jc-form-group">
                                <label for="jc-sa-description">
                                    <?php esc_html_e('Description', 'directreach-campaign-builder'); ?>
                                </label>
                                <textarea 
                                    id="jc-sa-description" 
                                    name="description" 
                                    class="jc-textarea" 
                                    rows="4"
                                    placeholder="Describe what this service area covers..."
                                ></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary jc-create-sa-btn">
                                <i class="fas fa-plus"></i>
                                <?php esc_html_e('Create Service Area', 'directreach-campaign-builder'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Step 3: Upload Existing Assets -->
            <div class="jc-step-container" id="jc-step-3" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 3: Upload Existing Assets (Optional)', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Add existing content assets — upload files or link to content already published online.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <!-- Asset Upload -->
                    <div class="jc-asset-upload-section">
                        <div class="jc-file-upload-area jc-asset-upload-area" id="jc-asset-upload-area">
                            <input 
                                type="file" 
                                id="jc-asset-input" 
                                multiple 
                                accept=".pdf,.doc,.docx,.html,.jpg,.jpeg,.png"
                                style="display: none;"
                            />
                            <label for="jc-asset-input" class="jc-file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p><?php esc_html_e('Click to upload or drag and drop existing assets', 'directreach-campaign-builder'); ?></p>
                                <span class="jc-file-types"><?php esc_html_e('PDF, DOC, HTML, Images', 'directreach-campaign-builder'); ?></span>
                            </label>
                        </div>
                    </div>

                    <!-- Asset URL Input -->
                    <div class="jc-asset-url-input-section">
                        <h3>
                            <i class="fas fa-link"></i>
                            <?php esc_html_e('Add Asset URL', 'directreach-campaign-builder'); ?>
                        </h3>
                        <p class="jc-section-description"><?php esc_html_e('Link to an existing asset hosted online (blog post, article, landing page, etc.)', 'directreach-campaign-builder'); ?></p>
                        <div class="jc-url-input-group">
                            <input 
                                type="url" 
                                id="jc-asset-url-input" 
                                class="jc-input jc-input-url" 
                                placeholder="https://example.com/your-existing-asset"
                            />
                            <button type="button" class="btn btn-primary jc-add-asset-url-btn">
                                <i class="fas fa-plus"></i>
                                <?php esc_html_e('Add URL', 'directreach-campaign-builder'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Assets List -->
                    <div class="jc-asset-section">
                        <h3>
                            <i class="fas fa-list"></i>
                            <?php esc_html_e('Added Assets', 'directreach-campaign-builder'); ?>
                            <span class="jc-asset-count">0</span>
                        </h3>
                        <div id="jc-asset-list" class="jc-asset-list">
                            <div class="jc-empty-state">
                                <i class="fas fa-inbox"></i>
                                <p><?php esc_html_e('No assets added yet. Upload files or add URLs above.', 'directreach-campaign-builder'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Skip Option -->
                    <div class="jc-skip-section">
                        <p class="jc-help-text">
                            <i class="fas fa-info-circle"></i>
                            <?php esc_html_e('You can skip this step if you don\'t have existing assets to add.', 'directreach-campaign-builder'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Step 4: Industry Selection -->
            <div class="jc-step-container" id="jc-step-4" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 4: Select Target Industries', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Click on an industry below to add it. This helps AI generate more relevant content.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div class="jc-industry-section">

                        <!-- Selected Industries (top) -->
                        <div class="jc-selected-industries-box" id="jc-selected-industries-box">
                            <div class="jc-selected-industries-header">
                                <h4>
                                    <i class="fas fa-check-circle"></i>
                                    <?php esc_html_e('Selected Industries', 'directreach-campaign-builder'); ?>
                                    (<span id="jc-industry-count">0</span>)
                                </h4>
                                <button type="button" class="jc-btn jc-btn-secondary jc-btn-sm jc-clear-industries" style="display:none;">
                                    <i class="fas fa-times"></i> <?php esc_html_e('Clear All', 'directreach-campaign-builder'); ?>
                                </button>
                            </div>
                            <div id="jc-selected-industry-chips" class="jc-selected-industry-chips">
                                <span class="jc-empty-selection"><?php esc_html_e('No industries selected — click industries below to add them', 'directreach-campaign-builder'); ?></span>
                            </div>
                        </div>

                        <!-- Search -->
                        <div class="jc-industry-search-bar">
                            <i class="fas fa-search"></i>
                            <input 
                                type="text" 
                                id="jc-industry-search" 
                                class="jc-search-input" 
                                placeholder="<?php esc_attr_e('Search industries...', 'directreach-campaign-builder'); ?>"
                            />
                        </div>

                        <!-- Available Industries (bottom) -->
                        <div class="jc-available-industries" id="jc-available-industries">
                            <h4><?php esc_html_e('Available Industries', 'directreach-campaign-builder'); ?></h4>
                            <div id="jc-industry-list" class="jc-industry-catalog">
                                <div class="jc-loading-state">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <p><?php esc_html_e('Loading industries...', 'directreach-campaign-builder'); ?></p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Step 5: Primary Problem Selection -->
            <div class="jc-step-container" id="jc-step-5" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 5: Designate Primary Problem', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Select the main problem that your service area addresses. This will be the center of your journey circle.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div class="jc-ai-generating" id="jc-primary-problem-loading" style="display: none;">
                        <div class="jc-loading-spinner"></div>
                        <p><?php esc_html_e('AI is analyzing your content to suggest problems...', 'directreach-campaign-builder'); ?></p>
                    </div>
                    
                    <div id="jc-primary-problem-list" class="jc-problem-list">
                        <!-- Problem options will be rendered here by JavaScript -->
                    </div>
                    
                    <div class="jc-regenerate-section">
                        <button type="button" class="btn btn-secondary jc-regenerate-btn" id="jc-regenerate-primary-problems">
                            <i class="fas fa-sync-alt"></i>
                            <?php esc_html_e('Regenerate Suggestions', 'directreach-campaign-builder'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 6: Problem Title Selection -->
            <div class="jc-step-container" id="jc-step-6" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 6: Select 5 Problem Titles', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Choose exactly 5 problem titles from the AI-generated suggestions. These will form the outer ring of your journey circle.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div class="jc-selection-counter">
                        <span id="jc-problem-selection-count">0</span> / 5 <?php esc_html_e('selected', 'directreach-campaign-builder'); ?>
                        <span class="jc-selection-hint"><?php esc_html_e('(Select exactly 5)', 'directreach-campaign-builder'); ?></span>
                    </div>
                    
                    <div class="jc-ai-generating" id="jc-problem-titles-loading" style="display: none;">
                        <div class="jc-loading-spinner"></div>
                        <p><?php esc_html_e('AI is generating problem title suggestions...', 'directreach-campaign-builder'); ?></p>
                    </div>
                    
                    <div id="jc-problem-titles-list" class="jc-checkbox-list">
                        <!-- Problem title options will be rendered here by JavaScript -->
                    </div>
                    
                    <div class="jc-regenerate-section">
                        <button type="button" class="btn btn-secondary jc-regenerate-btn" id="jc-regenerate-problem-titles">
                            <i class="fas fa-sync-alt"></i>
                            <?php esc_html_e('Regenerate Suggestions', 'directreach-campaign-builder'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 7: Solution Title Selection -->
            <div class="jc-step-container" id="jc-step-7" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 7: Select Solution Titles', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('For each problem, select one solution title. These will form the middle ring of your journey circle.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div class="jc-ai-generating" id="jc-solution-titles-loading" style="display: none;">
                        <div class="jc-loading-spinner"></div>
                        <p><?php esc_html_e('AI is generating solution suggestions...', 'directreach-campaign-builder'); ?></p>
                    </div>
                    
                    <div id="jc-solution-mapping-container" class="jc-solution-mapping">
                        <!-- Solution options for each problem will be rendered here by JavaScript -->
                    </div>
                    
                    <div class="jc-regenerate-section">
                        <button type="button" class="btn btn-secondary jc-regenerate-btn" id="jc-regenerate-solutions">
                            <i class="fas fa-sync-alt"></i>
                            <?php esc_html_e('Regenerate All Solutions', 'directreach-campaign-builder'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 8: Offer Mapping -->
            <div class="jc-step-container" id="jc-step-8" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 8: Map Offers to Solutions', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Link your offers (products, services, content) to each solution. These form the center of your journey circle.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div id="jc-offer-mapping-container" class="jc-offer-mapping">
                        <!-- Offer forms for each solution will be rendered here by JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Step 9: Asset Creation -->
            <div class="jc-step-container" id="jc-step-9" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 9: Create Content Assets', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Generate AI-powered content for each problem and solution in your journey circle.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <!-- Asset Selection Grid -->
                    <div class="jc-asset-grid" id="jc-asset-grid">
                        <!-- Asset cards will be rendered here by JavaScript -->
                    </div>
                    
                    <!-- Asset Creation Panel (shown when creating) -->
                    <div id="jc-asset-creation-panel" class="jc-asset-creation-panel" style="display: none;">
                        <!-- Format Selection -->
                        <div class="jc-format-selection" id="jc-format-selection">
                            <h3><?php esc_html_e('Select Content Format', 'directreach-campaign-builder'); ?></h3>
                            <div class="jc-format-cards">
                                <div class="jc-format-card" data-format="article_long">
                                    <i class="fas fa-file-alt"></i>
                                    <h4><?php esc_html_e('Long Article', 'directreach-campaign-builder'); ?></h4>
                                    <p><?php esc_html_e('1500-2500 words', 'directreach-campaign-builder'); ?></p>
                                </div>
                                <div class="jc-format-card" data-format="article_short">
                                    <i class="fas fa-file"></i>
                                    <h4><?php esc_html_e('Short Article', 'directreach-campaign-builder'); ?></h4>
                                    <p><?php esc_html_e('500-800 words', 'directreach-campaign-builder'); ?></p>
                                </div>
                                <div class="jc-format-card" data-format="infographic">
                                    <i class="fas fa-chart-bar"></i>
                                    <h4><?php esc_html_e('Infographic', 'directreach-campaign-builder'); ?></h4>
                                    <p><?php esc_html_e('Visual content outline', 'directreach-campaign-builder'); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Outline Phase -->
                        <div class="jc-outline-phase" id="jc-outline-phase" style="display: none;">
                            <h3><?php esc_html_e('Content Outline', 'directreach-campaign-builder'); ?></h3>
                            <div class="jc-ai-generating" id="jc-outline-loading" style="display: none;">
                                <div class="jc-loading-spinner"></div>
                                <p><?php esc_html_e('Generating outline...', 'directreach-campaign-builder'); ?></p>
                            </div>
                            <div id="jc-outline-content" class="jc-outline-content"></div>
                            <div class="jc-feedback-section">
                                <textarea id="jc-outline-feedback" class="jc-textarea" rows="3" placeholder="<?php esc_attr_e('Provide feedback to improve the outline...', 'directreach-campaign-builder'); ?>"></textarea>
                                <div class="jc-feedback-actions">
                                    <button type="button" class="btn btn-secondary" id="jc-revise-outline">
                                        <i class="fas fa-edit"></i>
                                        <?php esc_html_e('Revise Outline', 'directreach-campaign-builder'); ?>
                                    </button>
                                    <button type="button" class="btn btn-primary" id="jc-approve-outline">
                                        <i class="fas fa-check"></i>
                                        <?php esc_html_e('Approve & Generate Content', 'directreach-campaign-builder'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Content Phase -->
                        <div class="jc-content-phase" id="jc-content-phase" style="display: none;">
                            <h3><?php esc_html_e('Generated Content', 'directreach-campaign-builder'); ?></h3>
                            <div class="jc-ai-generating" id="jc-content-loading" style="display: none;">
                                <div class="jc-loading-spinner"></div>
                                <p><?php esc_html_e('Generating content...', 'directreach-campaign-builder'); ?></p>
                            </div>
                            <div id="jc-content-preview" class="jc-content-preview"></div>
                            <div class="jc-feedback-section">
                                <textarea id="jc-content-feedback" class="jc-textarea" rows="3" placeholder="<?php esc_attr_e('Provide feedback to improve the content...', 'directreach-campaign-builder'); ?>"></textarea>
                                <div class="jc-feedback-actions">
                                    <button type="button" class="btn btn-secondary" id="jc-revise-content">
                                        <i class="fas fa-edit"></i>
                                        <?php esc_html_e('Revise Content', 'directreach-campaign-builder'); ?>
                                    </button>
                                    <button type="button" class="btn btn-primary" id="jc-approve-content">
                                        <i class="fas fa-check"></i>
                                        <?php esc_html_e('Approve & Download', 'directreach-campaign-builder'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 10: Link Published Assets -->
            <div class="jc-step-container" id="jc-step-10" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 10: Link Published Assets', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Paste the URLs where you published your content assets.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div id="jc-asset-url-list" class="jc-asset-url-list">
                        <!-- Asset URL forms will be rendered here by JavaScript -->
                    </div>
                    
                    <div class="jc-skip-section">
                        <p class="jc-help-text">
                            <i class="fas fa-info-circle"></i>
                            <?php esc_html_e('You can skip linking URLs for now and add them later from the dashboard.', 'directreach-campaign-builder'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Step 11: Complete -->
            <div class="jc-step-container" id="jc-step-11" style="display: none;">
                <div class="jc-step-header">
                    <h2><?php esc_html_e('Step 11: Journey Circle Complete!', 'directreach-campaign-builder'); ?></h2>
                    <p><?php esc_html_e('Your journey circle is ready. Review the summary below.', 'directreach-campaign-builder'); ?></p>
                </div>
                
                <div class="jc-step-content">
                    <div class="jc-completion-summary">
                        <div class="jc-completion-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        
                        <div class="jc-summary-stats" id="jc-summary-stats">
                            <div class="jc-stat-item">
                                <span class="jc-stat-value" id="jc-stat-problems">5</span>
                                <span class="jc-stat-label"><?php esc_html_e('Problems', 'directreach-campaign-builder'); ?></span>
                            </div>
                            <div class="jc-stat-item">
                                <span class="jc-stat-value" id="jc-stat-solutions">5</span>
                                <span class="jc-stat-label"><?php esc_html_e('Solutions', 'directreach-campaign-builder'); ?></span>
                            </div>
                            <div class="jc-stat-item">
                                <span class="jc-stat-value" id="jc-stat-offers">0</span>
                                <span class="jc-stat-label"><?php esc_html_e('Offers', 'directreach-campaign-builder'); ?></span>
                            </div>
                            <div class="jc-stat-item">
                                <span class="jc-stat-value" id="jc-stat-assets">0</span>
                                <span class="jc-stat-label"><?php esc_html_e('Assets Created', 'directreach-campaign-builder'); ?></span>
                            </div>
                        </div>
                        
                        <div class="jc-completion-actions">
                            <button type="button" class="btn btn-secondary" id="jc-create-more-assets">
                                <i class="fas fa-plus"></i>
                                <?php esc_html_e('Create More Assets', 'directreach-campaign-builder'); ?>
                            </button>
                            <button type="button" class="btn btn-primary" id="jc-complete-journey">
                                <i class="fas fa-check"></i>
                                <?php esc_html_e('Complete & Return to Campaign Builder', 'directreach-campaign-builder'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Panel: Visualization -->
        <div class="jc-right-panel">
            <div class="jc-canvas-container">
                <h3><?php esc_html_e('Journey Circle Visualization', 'directreach-campaign-builder'); ?></h3>
                <canvas id="jc-canvas" width="700" height="700"></canvas>
                <div class="jc-canvas-legend">
                    <div class="jc-legend-item">
                        <span class="jc-legend-color" style="background: #ff6b6b;"></span>
                        <span><?php esc_html_e('Problems', 'directreach-campaign-builder'); ?></span>
                    </div>
                    <div class="jc-legend-item">
                        <span class="jc-legend-color" style="background: #42a5f5;"></span>
                        <span><?php esc_html_e('Solutions', 'directreach-campaign-builder'); ?></span>
                    </div>
                    <div class="jc-legend-item">
                        <span class="jc-legend-color" style="background: #66bb6a;"></span>
                        <span><?php esc_html_e('Offers', 'directreach-campaign-builder'); ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Navigation Footer -->
    <footer class="jc-footer">
        <div class="jc-footer-content">
            <button type="button" class="btn btn-secondary jc-prev-btn" disabled>
                <i class="fas fa-arrow-left"></i>
                <?php esc_html_e('Previous', 'directreach-campaign-builder'); ?>
            </button>
            <div class="jc-step-indicator">
                <?php esc_html_e('Step', 'directreach-campaign-builder'); ?> 
                <span class="jc-current-step">1</span> / 11
            </div>
            <button type="button" class="btn btn-primary jc-next-btn">
                <?php esc_html_e('Next', 'directreach-campaign-builder'); ?>
                <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </footer>

</div>

<!-- Text Paste Modal -->
<div id="jc-text-paste-modal" class="jc-modal" style="display: none;">
    <div class="jc-modal-content">
        <div class="jc-modal-header">
            <h3><?php esc_html_e('Paste Text Content', 'directreach-campaign-builder'); ?></h3>
            <button type="button" class="jc-modal-close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="jc-modal-body">
            <textarea 
                id="jc-paste-textarea" 
                class="jc-textarea jc-paste-textarea" 
                rows="15"
                placeholder="Paste your content here..."
            ></textarea>
        </div>
        <div class="jc-modal-footer">
            <button type="button" class="btn btn-secondary jc-modal-cancel">
                <?php esc_html_e('Cancel', 'directreach-campaign-builder'); ?>
            </button>
            <button type="button" class="btn btn-primary jc-paste-submit-btn">
                <i class="fas fa-check"></i>
                <?php esc_html_e('Add Content', 'directreach-campaign-builder'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Hidden data for JavaScript -->
<script type="text/javascript">
    var drJourneyCircleConfig = {
        clientId: <?php echo absint($client_id); ?>,
        serviceAreaId: <?php echo absint($service_area_id); ?>,
        clientName: <?php echo wp_json_encode($client->client_name); ?>,
        nonce: '<?php echo wp_create_nonce('dr_journey_circle_nonce'); ?>',
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        restUrl: '<?php echo rest_url('directreach/v2'); ?>',
        restNonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
        campaignBuilderUrl: '<?php echo admin_url('admin.php?page=dr-campaign-builder'); ?>',
        maxFileSize: <?php echo wp_max_upload_size(); ?>,
        allowedFileTypes: ['pdf', 'doc', 'docx', 'txt', 'html', 'jpg', 'jpeg', 'png']
    };
</script>