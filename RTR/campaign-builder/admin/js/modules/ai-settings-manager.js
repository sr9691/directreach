/**
 * AI Settings Manager
 * 
 * Handles all interactions for the AI Settings page
 * - Load/save settings
 * - Test API connection
 * - Fetch models from Gemini
 * - Display usage statistics
 * - Form validation
 * - Notifications
 * 
 * @package DirectReach
 * @subpackage CampaignBuilder\Admin\JS
 */

class AISettingsManager {
    constructor(config) {
        this.config = config;
        this.apiUrl = config.apiUrl;
        this.nonce = config.nonce;
        this.strings = config.strings || {};
        
        // State
        this.currentSettings = {};
        this.models = [];
        this.isLoading = false;
        this.isSaving = false;
        this.isTesting = false;
        
        // Initialize
        this.init();
    }
    
    /**
     * Initialize manager
     */
    async init() {
        this.cacheElements();
        this.attachEventListeners();
        await this.loadSettings();
        
        // If API key is in the input field but not saved, save it first
        if (this.apiKeyInput && this.apiKeyInput.value && !this.currentSettings.api_key_set) {
            await this.saveApiKeyOnly();
        }
        
        this.loadModels();
        this.loadUsageStats();
    }
    
    /**
     * Cache DOM elements
     */
    cacheElements() {
        // Form
        this.form = document.getElementById('ai-settings-form');
        
        // Inputs
        this.aiEnabledInput = document.getElementById('ai-enabled');
        this.apiKeyInput = document.getElementById('api-key');
        this.modelSelect = document.getElementById('model-select');
        this.temperatureInput = document.getElementById('temperature');
        this.temperatureValue = document.getElementById('temperature-value');
        this.maxTokensInput = document.getElementById('max-tokens');
        this.rateLimitEnabledInput = document.getElementById('rate-limit-enabled');
        this.rateLimitInput = document.getElementById('rate-limit');
        
        // Buttons
        this.testConnectionBtn = document.getElementById('test-connection');
        this.saveBtn = document.getElementById('save-settings');
        
        // Status displays
        this.connectionStatus = document.getElementById('connection-status');
        
        // Stats
        this.todayUsageStat = document.getElementById('today-usage');
        this.monthUsageStat = document.getElementById('month-usage');
        this.avgCostStat = document.getElementById('avg-cost');
        this.totalTokensStat = document.getElementById('total-tokens');
        
        // Notification container
        this.notificationContainer = document.getElementById('notification-container');
    }
    
    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Form submission
        if (this.form) {
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettings();
            });
        }
        
        // Test connection
        if (this.testConnectionBtn) {
            this.testConnectionBtn.addEventListener('click', () => {
                this.testConnection();
            });
        }
        
        // Temperature slider
        if (this.temperatureInput && this.temperatureValue) {
            this.temperatureInput.addEventListener('input', (e) => {
                this.temperatureValue.textContent = e.target.value;
            });
        }
    }
    
    /**
     * Load current settings from API
     */
    async loadSettings() {
        try {
            this.setLoadingState(true);
            
            const response = await this.apiRequest('GET', '/settings/ai-config');
            
            if (response.success && response.data) {
                this.currentSettings = response.data;
                this.populateForm(response.data);
            }
            
        } catch (error) {
            console.error('Failed to load settings:', error);
            this.showNotification('error', 'Failed to load settings', error.message);
        } finally {
            this.setLoadingState(false);
        }
    }

    async saveApiKeyOnly() {
        if (!this.apiKeyInput || !this.apiKeyInput.value) {
            return;
        }
        
        try {
            console.log('Saving API key before loading models...');
            const response = await this.apiRequest('PUT', '/settings/ai-config', {
                api_key: this.apiKeyInput.value
            });
            
            if (response.success) {
                this.currentSettings.api_key_set = true;
                console.log('API key saved successfully');
            }
        } catch (error) {
            console.error('Failed to save API key:', error);
        }
    }    
    
    /**
     * Populate form with settings data
     */
    populateForm(data) {
        // AI Enabled
        if (this.aiEnabledInput) {
            this.aiEnabledInput.checked = data.enabled || false;
        }
        
        // API Key (don't populate for security - only show if set)
        if (this.apiKeyInput && data.api_key_set) {
            this.apiKeyInput.placeholder = 'API Key configured (hidden for security)';
        }
        
        // Model
        if (this.modelSelect && data.model) {
            // Will be set after models load
            this.modelSelect.dataset.selectedModel = data.model;
        }
        
        // Temperature
        if (this.temperatureInput && data.temperature !== undefined) {
            this.temperatureInput.value = data.temperature;
            if (this.temperatureValue) {
                this.temperatureValue.textContent = data.temperature;
            }
        }
        
        // Max Tokens
        if (this.maxTokensInput && data.max_tokens) {
            this.maxTokensInput.value = data.max_tokens;
        }
        
        // Rate Limiting
        if (this.rateLimitEnabledInput) {
            this.rateLimitEnabledInput.checked = data.rate_limit_enabled !== false;
        }
        
        if (this.rateLimitInput && data.rate_limit) {
            this.rateLimitInput.value = data.rate_limit;
        }
    }
    
    /**
     * Load available Gemini models
     */
    async loadModels() {
        try {
            const response = await this.apiRequest('GET', '/settings/gemini-models');
            
            if (response.success && response.data && response.data.models) {
                this.models = response.data.models;
                this.populateModelDropdown(response.data.models);
            }
            
        } catch (error) {
            console.error('Failed to load models:', error);
            // Fallback to default models
            this.populateModelDropdown([
                { name: 'gemini-2.5-flash', display_name: 'Gemini 2.5 Flash (Recommended)' },
                { name: 'gemini-2.5-pro', display_name: 'Gemini 2.5 Pro (Higher Quality)' }
            ]);
        }
    }
    
    /**
     * Populate model dropdown
     */
    populateModelDropdown(models) {
        if (!this.modelSelect) return;
        
        // Clear existing options
        this.modelSelect.innerHTML = '';
        
        // Add models
        models.forEach(model => {
            const option = document.createElement('option');
            option.value = model.name;
            option.textContent = model.display_name;
            this.modelSelect.appendChild(option);
        });
        
        // Set selected model if available
        const selectedModel = this.modelSelect.dataset.selectedModel;
        if (selectedModel) {
            this.modelSelect.value = selectedModel;
        }
    }
    
    /**
     * Save settings
     */
    async saveSettings() {
        if (this.isSaving) return;
        
        try {
            // Validate form
            if (!this.validateForm()) {
                return;
            }
            
            this.isSaving = true;
            this.setSavingState(true);
            
            // Gather form data
            const formData = this.gatherFormData();
            
            // Save via API
            const response = await this.apiRequest('PUT', '/settings/ai-config', formData);
            
            if (response.success) {
                this.showNotification(
                    'success',
                    this.strings.saveSuccess || 'Settings saved successfully'
                );
                
                // Reload settings to get updated state
                await this.loadSettings();
            } else {
                throw new Error(response.message || 'Failed to save settings');
            }
            
        } catch (error) {
            console.error('Failed to save settings:', error);
            this.showNotification(
                'error',
                this.strings.saveError || 'Failed to save settings',
                error.message
            );
        } finally {
            this.isSaving = false;
            this.setSavingState(false);
        }
    }
    
    /**
     * Gather form data
     */
    gatherFormData() {
        const data = {
            enabled: this.aiEnabledInput ? this.aiEnabledInput.checked : false,
            model: this.modelSelect ? this.modelSelect.value : 'gemini-2.5-flash',
            temperature: this.temperatureInput ? parseFloat(this.temperatureInput.value) : 0.7,
            max_tokens: this.maxTokensInput ? parseInt(this.maxTokensInput.value) : 1000,
            rate_limit_enabled: this.rateLimitEnabledInput ? this.rateLimitEnabledInput.checked : true,
            rate_limit: this.rateLimitInput ? parseInt(this.rateLimitInput.value) : 100
        };
        
        // Only include API key if changed
        if (this.apiKeyInput && this.apiKeyInput.value && 
            this.apiKeyInput.value !== this.apiKeyInput.placeholder) {
            data.api_key = this.apiKeyInput.value;
        }
        
        return data;
    }
    
    /**
     * Validate form
     */
    validateForm() {
        let isValid = true;
        const errors = [];
        
        // Temperature validation
        if (this.temperatureInput) {
            const temp = parseFloat(this.temperatureInput.value);
            if (isNaN(temp) || temp < 0 || temp > 1) {
                errors.push('Temperature must be between 0.0 and 1.0');
                isValid = false;
            }
        }
        
        // Max tokens validation
        if (this.maxTokensInput) {
            const tokens = parseInt(this.maxTokensInput.value);
            if (isNaN(tokens) || tokens < 100 || tokens > 8000) {
                errors.push('Max tokens must be between 100 and 8000');
                isValid = false;
            }
        }
        
        // Rate limit validation
        if (this.rateLimitInput && this.rateLimitEnabledInput && this.rateLimitEnabledInput.checked) {
            const limit = parseInt(this.rateLimitInput.value);
            if (isNaN(limit) || limit < 10 || limit > 1000) {
                errors.push('Rate limit must be between 10 and 1000');
                isValid = false;
            }
        }
        
        // Show errors
        if (!isValid) {
            this.showNotification('error', 'Validation Error', errors.join('<br>'));
        }
        
        return isValid;
    }
    
    /**
     * Test API connection
     */
    async testConnection() {
        if (this.isTesting) return;
        
        try {
            this.isTesting = true;
            this.setTestingState(true);
            
            const apiKey = this.apiKeyInput && this.apiKeyInput.value ? 
                this.apiKeyInput.value : null;
            
            const selectedModel = this.modelSelect ? this.modelSelect.value : null;
            
            const payload = {};
            if (apiKey) payload.api_key = apiKey;
            if (selectedModel) payload.model = selectedModel;
            
            const response = await this.apiRequest('POST', '/settings/test-ai', payload);
            
            if (response.success) {
                this.showConnectionStatus('success', 
                    this.strings.testSuccess || 'Connection successful',
                    `Model: ${response.model || 'Unknown'}`
                );
                
                this.showNotification(
                    'success',
                    'Connection Successful',
                    `Successfully connected to Gemini API using ${response.model}`
                );
            } else {
                throw new Error(response.message || 'Connection failed');
            }
            
        } catch (error) {
            console.error('Connection test failed:', error);
            
            const errorMessage = error.message || 'Unknown error';
            
            this.showConnectionStatus('error', 
                this.strings.testError || 'Connection failed',
                errorMessage
            );
            
            this.showNotification(
                'error',
                'Connection Failed',
                errorMessage
            );
        } finally {
            this.isTesting = false;
            this.setTestingState(false);
        }
    }
    
    /**
     * Load usage statistics
     */
    async loadUsageStats() {
        try {
            // Note: This endpoint doesn't exist yet, but we'll prepare for it
            // For now, just show placeholder values
            
            // Future implementation:
            // const response = await this.apiRequest('GET', '/settings/ai-usage');
            // if (response.success && response.data) {
            //     this.updateStatsDisplay(response.data);
            // }
            
            // Placeholder for now
            this.updateStatsDisplay({
                today: 0,
                month: 0,
                avg_cost: 0,
                total_tokens: 0
            });
            
        } catch (error) {
            console.error('Failed to load usage stats:', error);
        }
    }
    
    /**
     * Update stats display
     */
    updateStatsDisplay(stats) {
        if (this.todayUsageStat) {
            this.todayUsageStat.textContent = stats.today || 0;
        }
        
        if (this.monthUsageStat) {
            this.monthUsageStat.textContent = stats.month || 0;
        }
        
        if (this.avgCostStat) {
            const cost = stats.avg_cost || 0;
            this.avgCostStat.textContent = `$${cost.toFixed(4)}`;
        }
        
        if (this.totalTokensStat) {
            const tokens = stats.total_tokens || 0;
            this.totalTokensStat.textContent = tokens.toLocaleString();
        }
    }
    
    /**
     * Show connection status
     */
    showConnectionStatus(type, message, details = '') {
        if (!this.connectionStatus) return;
        
        this.connectionStatus.className = `connection-status ${type}`;
        this.connectionStatus.innerHTML = `
            <span>${message}</span>
            ${details ? `<small style="display: block; margin-top: 0.25rem;">${details}</small>` : ''}
        `;
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            this.connectionStatus.className = 'connection-status';
            this.connectionStatus.innerHTML = '';
        }, 5000);
    }
    
    /**
     * Show notification
     */
    showNotification(type, title, message = '') {
        if (!this.notificationContainer) return;
        
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const iconMap = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            info: 'fa-info-circle'
        };
        
        notification.innerHTML = `
            <div class="notification-content">
                <div class="notification-title">${title}</div>
                ${message ? `<div class="notification-message">${message}</div>` : ''}
            </div>
            <button class="notification-close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        // Close button
        const closeBtn = notification.querySelector('.notification-close');
        closeBtn.addEventListener('click', () => {
            notification.remove();
        });
        
        // Add to container
        this.notificationContainer.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.remove();
        }, 5000);
    }
    
    /**
     * Set loading state
     */
    setLoadingState(isLoading) {
        this.isLoading = isLoading;
        
        if (this.form) {
            const inputs = this.form.querySelectorAll('input, select, button');
            inputs.forEach(input => {
                input.disabled = isLoading;
            });
        }
    }
    
    /**
     * Set saving state
     */
    setSavingState(isSaving) {
        if (!this.saveBtn) return;
        
        const originalHTML = this.saveBtn.innerHTML;
        
        if (isSaving) {
            this.saveBtn.disabled = true;
            this.saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        } else {
            this.saveBtn.disabled = false;
            this.saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Settings';
        }
    }
    
    /**
     * Set testing state
     */
    setTestingState(isTesting) {
        if (!this.testConnectionBtn) return;
        
        if (isTesting) {
            this.testConnectionBtn.disabled = true;
            this.testConnectionBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
            
            if (this.connectionStatus) {
                this.connectionStatus.className = 'connection-status testing';
                this.connectionStatus.textContent = 'Testing connection...';
            }
        } else {
            this.testConnectionBtn.disabled = false;
            this.testConnectionBtn.innerHTML = '<i class="fas fa-plug"></i> Test Connection';
        }
    }
    
    /**
     * Make API request
     */
    async apiRequest(method, endpoint, data = null) {
        const url = this.apiUrl + endpoint;
        
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.nonce
            }
        };
        
        if (data && (method === 'POST' || method === 'PUT')) {
            options.body = JSON.stringify(data);
        }
        
        const response = await fetch(url, options);
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
        }
        
        return await response.json();
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Check if config is available
    if (typeof window.drAIConfig === 'undefined') {
        console.error('AI Settings: Configuration not found (window.drAIConfig)');
        return;
    }
    
    // Initialize manager
    const aiSettings = new AISettingsManager(window.drAIConfig);
    
    // Expose to window for debugging (optional)
    window.aiSettingsManager = aiSettings;
});