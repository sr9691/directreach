<?php
/**
 * Global Templates Page Content (BODY ONLY)
 * 
 * @package DirectReach_Campaign_Builder
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Header -->
<?php 
$args = [
    'page_badge' => 'Global Templates',
    'active_page' => 'dr-global-templates',
    'show_back_btn' => true
];
include __DIR__ . '/partials/admin-header.php';
?>

<!-- Main Content -->
<main class="workflow-main">
    <div class="workflow-container">
        
        <div id="global-templates-app" class="templates-step-container">
            
            <!-- Loading State -->
            <div id="templates-loading" class="loading-state" style="display: none;">
                <div class="spinner"></div>
                <p>Loading global templates...</p>
            </div>
            
            <!-- Error State -->
            <div id="templates-error" class="error-state" style="display: none;">
                <i class="fas fa-exclamation-circle"></i>
                <h3>Failed to Load Templates</h3>
                <p class="error-message"></p>
                <button type="button" id="retry-load-templates" class="btn btn-primary">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
            
            <!-- Main Content -->
            <div id="templates-content" style="display: none;">
                
                <!-- Step Header -->
                <div class="step-header">
                    <h2>
                        <i class="fas fa-robot"></i>
                        Global Email Templates
                    </h2>
                    <p class="step-description">
                        Create reusable AI prompt templates that are available across all campaigns. 
                        These templates serve as fallbacks when campaign-specific templates don't exist.
                    </p>
                </div>

                <!-- Info Banner -->
                <div class="ai-info-banner">
                    <div class="banner-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="banner-content">
                        <h4>Global Template Library</h4>
                        <p>
                            Global templates are automatically available to all campaigns. Create up to 5 templates per room type. 
                            Campaign-specific templates will take priority over global templates.
                        </p>
                    </div>
                </div>

                <!-- Template Availability Notice -->
                <div class="template-availability-notice">
                    <i class="fas fa-info-circle"></i>
                    <span>
                        Up to <strong>5 templates per room</strong>. These are shared across all campaigns.
                    </span>
                </div>
                
                <!-- Room Tabs -->
                <div class="room-tabs">
                    <button type="button" class="room-tab active" data-room="problem">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Problem Room</span>
                        <span class="tab-status"></span>
                    </button>
                    <button type="button" class="room-tab" data-room="solution">
                        <i class="fas fa-lightbulb"></i>
                        <span>Solution Room</span>
                        <span class="tab-status"></span>
                    </button>
                    <button type="button" class="room-tab" data-room="offer">
                        <i class="fas fa-gift"></i>
                        <span>Offer Room</span>
                        <span class="tab-status"></span>
                    </button>
                </div>
                
                <!-- Template Lists (one per room) -->
                <div class="template-lists">
                    <div class="template-list-container active" data-room="problem"></div>
                    <div class="template-list-container" data-room="solution"></div>
                    <div class="template-list-container" data-room="offer"></div>
                </div>
                
                <!-- Include template form partial -->
                <?php include plugin_dir_path(__FILE__) . './partials/template-form.php'; ?>
                
            </div>
            
        </div>
        
    </div>
</main>

<!-- Notification Container -->
<div class="notification-container"></div>