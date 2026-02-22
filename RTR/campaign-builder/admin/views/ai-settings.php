<?php
/**
 * AI Settings Page View
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ai-settings-page">
    <!-- Header -->
    <?php 
    $args = [
        'page_badge' => 'AI Configuration',
        'active_page' => 'dr-ai-settings',
        'show_back_btn' => true
    ];
    include __DIR__ . '/partials/admin-header.php';
    ?>

    <!-- Main Content -->
    <main class="workflow-main">
        <div class="workflow-container">
            
            <!-- Page Header -->
            <div class="step-header">
                <h2>
                    <i class="fas fa-robot"></i>
                    <?php echo esc_html($page_title); ?>
                </h2>
                <p class="step-description">
                    <?php echo esc_html($page_description); ?>
                </p>
            </div>

            <!-- Settings Form -->
            <form id="ai-settings-form">
                
                <!-- Status Section -->
                <section class="settings-section">
                    <h3>Status</h3>
                    <label class="toggle-switch">
                        <input type="checkbox" name="ai_enabled" id="ai-enabled">
                        <span class="slider"></span>
                        <span class="label">Enable AI Email Generation</span>
                    </label>
                    <p class="help-text">
                        When enabled, the system can generate personalized emails using AI based on visitor data and templates.
                    </p>
                </section>

                <!-- API Configuration Section -->
                <section class="settings-section">
                    <h3>Gemini API Configuration</h3>
                    
                    <div class="form-group">
                        <label for="api-key">
                            API Key <span class="required">*</span>
                        </label>
                        <div class="input-with-button">
                            <input type="password" 
                                   name="api_key" 
                                   id="api-key"
                                   class="form-control"
                                   placeholder="Enter your Gemini API key">
                            <button type="button" 
                                    class="btn-toggle-visibility" 
                                    id="toggle-api-key"
                                    title="Show/Hide API Key">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="help-text">
                            Get your API key from 
                            <a href="https://makersuite.google.com/app/apikey" 
                               target="_blank" 
                               rel="noopener noreferrer">
                                Google AI Studio <i class="fas fa-external-link-alt"></i>
                            </a>
                        </span>
                    </div>

                    <div class="form-group">
                        <label for="model-select">Model</label>
                        <select name="model" id="model-select" class="form-control">
                            <option value="">Loading models...</option>
                        </select>
                        <span class="help-text">
                            Gemini 2.5 Flash is recommended for best balance of quality and speed. Pro is higher quality but slower.
                        </span>
                    </div>

                    <div class="form-actions-inline">
                        <button type="button" 
                                class="btn btn-secondary" 
                                id="test-connection">
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                        <span id="connection-status" class="connection-status"></span>
                    </div>
                </section>

                <!-- Generation Settings Section -->
                <section class="settings-section">
                    <h3>Generation Settings</h3>
                    
                    <div class="form-group">
                        <label for="temperature">
                            Temperature (Creativity)
                        </label>
                        <div class="range-input-group">
                            <input type="range" 
                                   name="temperature" 
                                   id="temperature"
                                   min="0" 
                                   max="1" 
                                   step="0.1" 
                                   value="0.7">
                            <span class="range-value" id="temperature-value">0.7</span>
                        </div>
                        <span class="help-text">
                            Lower = More focused and deterministic<br>
                            Higher = More creative and varied
                        </span>
                    </div>

                    <div class="form-group">
                        <label for="max-tokens">Max Tokens</label>
                        <input type="number" 
                               name="max_tokens" 
                               id="max-tokens"
                               class="form-control"
                               min="100" 
                               max="8000" 
                               step="100"
                               value="1000">
                        <span class="help-text">
                            Maximum length of generated emails (100-8000)
                        </span>
                    </div>
                </section>

                <!-- Rate Limiting Section -->
                <section class="settings-section">
                    <h3>Rate Limiting</h3>
                    
                    <label class="toggle-switch">
                        <input type="checkbox" 
                               name="rate_limit_enabled" 
                               id="rate-limit-enabled"
                               checked>
                        <span class="slider"></span>
                        <span class="label">Enable Rate Limiting</span>
                    </label>
                    
                    <div class="form-group" id="rate-limit-input">
                        <label for="rate-limit">Limit (emails per hour)</label>
                        <input type="number" 
                               name="rate_limit" 
                               id="rate-limit"
                               class="form-control"
                               min="10" 
                               max="1000" 
                               step="10"
                               value="100">
                        <span class="help-text">
                            Prevents excessive API usage and costs. Recommended: 100 per hour.
                        </span>
                    </div>
                </section>

                <!-- Usage Statistics Section -->
                <section class="settings-section">
                    <h3>Usage Statistics</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value" id="today-usage">-</div>
                            <div class="stat-label">Emails Today</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="month-usage">-</div>
                            <div class="stat-label">This Month</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="avg-cost">$-.--</div>
                            <div class="stat-label">Avg Cost/Email</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="total-tokens">-</div>
                            <div class="stat-label">Total Tokens</div>
                        </div>
                    </div>
                    <p class="help-text" style="margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        Statistics are updated daily. Current costs are estimates based on Gemini API pricing.
                    </p>
                </section>

                <!-- Save Button -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="save-settings">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </div>

            </form>

        </div>
    </main>

    <!-- Notification Container -->
    <div class="notification-container" id="notification-container"></div>

</div>

<!-- Settings Dropdown Toggle Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('settings-dropdown-toggle');
    const menu = document.getElementById('settings-menu');
    const dropdown = toggle ? toggle.closest('.settings-dropdown') : null; // Get the container
    
    if (toggle && menu && dropdown) {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('active'); // Toggle on container, not menu
        });
        
        // Close when clicking outside
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('active');
            }
        });
    }
    
    // API Key visibility toggle
    const apiKeyInput = document.getElementById('api-key');
    const toggleBtn = document.getElementById('toggle-api-key');
    
    if (apiKeyInput && toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            const isPassword = apiKeyInput.type === 'password';
            apiKeyInput.type = isPassword ? 'text' : 'password';
            toggleBtn.querySelector('i').className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }
    
    // Temperature slider value display
    const tempSlider = document.getElementById('temperature');
    const tempValue = document.getElementById('temperature-value');
    
    if (tempSlider && tempValue) {
        tempSlider.addEventListener('input', function() {
            tempValue.textContent = this.value;
        });
    }
    
    // Rate limit toggle
    const rateLimitToggle = document.getElementById('rate-limit-enabled');
    const rateLimitInput = document.getElementById('rate-limit-input');
    
    if (rateLimitToggle && rateLimitInput) {
        rateLimitToggle.addEventListener('change', function() {
            rateLimitInput.style.display = this.checked ? 'block' : 'none';
        });
        
        // Set initial state
        rateLimitInput.style.display = rateLimitToggle.checked ? 'block' : 'none';
    }
});
</script>