/**
 * Client Settings Manager (Enhanced with Full Feature Parity)
 * 
 * Manages the client settings panel for Room Thresholds and Scoring Rules
 * Allows full customization of rules including values arrays
 * 
 * @package DirectReach_Campaign_Builder
 * @since 2.1.0
 */

import EventEmitter from '../../../../campaign-builder/admin/js/utils/event-emitter.js';
import APIClient from '../../../../campaign-builder/admin/js/utils/api-client.js';

export default class ClientSettingsManager extends EventEmitter {
    constructor(config) {
        super();
        
        this.config = config;
        this.api = new APIClient(config.apiUrl, config.nonce);
        
        // Thresholds state
        this.currentClient = null;
        this.currentTab = 'thresholds';
        this.globalThresholds = null;
        this.clientThresholds = null;
        this.isCustom = false;
        
        // Scoring rules state
        this.currentPanelRoom = 'problem';
        this.globalScoringRules = { problem: {}, solution: {}, offer: {} };
        this.clientScoringRules = { problem: {}, solution: {}, offer: {} };
        this.isScoringCustom = false;
        
        // Value editing state
        this.currentEditingRule = null;
        this.editingModal = null;
        
        this.init();
    }
    
    /**
     * Initialize
     */
    init() {
        this.loadGlobalThresholds();
        this.loadGlobalScoringRules();
        this.attachEventListeners();
        this.createEditingModal();
    }
    
    /**
     * Load global thresholds for reference
     */
    async loadGlobalThresholds() {
        try {
            const response = await this.api.get('/room-thresholds');
            if (response.success) {
                this.globalThresholds = response.data.thresholds || response.data;
                this.updateGlobalHints();
            }
        } catch (error) {
            console.error('Failed to load global thresholds:', error);
        }
    }
    
    /**
     * Load global scoring rules for reference
     */
    async loadGlobalScoringRules() {
        try {
            const response = await this.api.get('/scoring-rules');
            if (response.success) {
                this.globalScoringRules = {
                    problem: response.data.problem || {},
                    solution: response.data.solution || {},
                    offer: response.data.offer || {}
                };
            }
        } catch (error) {
            console.error('Failed to load global scoring rules:', error);
        }
    }
    
    /**
     * Create editing modal for values
     */
    createEditingModal() {
        // Remove any existing modal
        const existingModal = document.getElementById('panel-editing-modal');
        if (existingModal) {
            existingModal.remove();
        }
        
        const modal = document.createElement('div');
        modal.className = 'panel-editing-modal';
        modal.id = 'panel-editing-modal';
        modal.style.display = 'none'; // Start hidden
        modal.innerHTML = `
            <div class="modal-overlay"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="editing-modal-title">Edit Values</h3>
                    <button class="modal-close" id="editing-modal-close">&times;</button>
                </div>
                <div class="modal-body" id="editing-modal-body">
                    <!-- Dynamic content -->
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" id="editing-modal-cancel">Cancel</button>
                    <button class="btn btn-primary" id="editing-modal-save">Save Changes</button>
                </div>
            </div>
        `;
        
        // Append to body
        document.body.appendChild(modal);
        this.editingModal = modal;
        
        // Attach modal listeners
        modal.querySelector('#editing-modal-close').addEventListener('click', () => this.closeEditingModal());
        modal.querySelector('#editing-modal-cancel').addEventListener('click', () => this.closeEditingModal());
        modal.querySelector('#editing-modal-save').addEventListener('click', () => this.saveEditingModal());
        modal.querySelector('.modal-overlay').addEventListener('click', () => this.closeEditingModal());
    }
    
    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Close panel buttons
        document.getElementById('close-settings-panel')?.addEventListener('click', () => {
            this.closePanel();
        });
        
        document.getElementById('cancel-settings-btn')?.addEventListener('click', () => {
            this.closePanel();
        });
        
        // Close on overlay click
        document.getElementById('client-settings-overlay')?.addEventListener('click', (e) => {
            if (e.target.id === 'client-settings-overlay') {
                this.closePanel();
            }
        });
        
        // Main tab switching (Thresholds/Scoring)
        document.querySelectorAll('.panel-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                const tabName = e.currentTarget.dataset.tab;
                this.switchTab(tabName);
            });
        });
        
        // Save button
        document.getElementById('save-settings-btn')?.addEventListener('click', () => {
            this.saveSettings();
        });
        
        // Reset to global
        document.getElementById('reset-to-global-btn')?.addEventListener('click', () => {
            this.resetToGlobal();
        });
        
        // Live threshold updates
        const form = document.getElementById('client-thresholds-form');
        if (form) {
            form.querySelectorAll('input[type="number"]').forEach(input => {
                input.addEventListener('input', () => {
                    this.updateVisualSlider();
                    this.validateThresholds();
                });
            });
        }
    }
    
    /**
     * Attach mini tab listeners (for room switching in scoring tab)
     */
    attachScoringTabListeners() {
        const miniTabs = document.querySelectorAll('.panel-mini-tab');
        
        miniTabs.forEach(tab => {
            // Clone to remove old listeners
            const newTab = tab.cloneNode(true);
            tab.parentNode.replaceChild(newTab, tab);
            
            // Add new listener
            newTab.addEventListener('click', (e) => {
                const room = e.currentTarget.dataset.panelRoom;
                this.switchPanelRoom(room);
            });
        });
    }
    
    /**
     * Open panel for a client
     */
    async openPanel(client) {
        this.currentClient = client;
        
        // Update panel title
        document.getElementById('panel-client-name').textContent = client.name;
        
        // Show panel
        document.getElementById('client-settings-overlay').style.display = 'flex';
        
        // Show loading
        this.showLoading();
        
        try {
            // Load client thresholds
            await this.loadClientThresholds(client.id);
            
            // Load client scoring rules
            await this.loadClientScoringRules(client.id);
        } catch (error) {
            console.error('Error loading client data:', error);
        }
        
        // Hide loading
        this.hideLoading();
        
        // Attach scoring tab listeners after panel is visible
        setTimeout(() => {
            this.attachScoringTabListeners();
        }, 100);
    }
    
    /**
     * Close panel
     */
    closePanel() {
        document.getElementById('client-settings-overlay').style.display = 'none';
        this.currentClient = null;
        this.currentTab = 'thresholds';
        
        // Reset to first tab
        this.switchTab('thresholds');
    }
    
    /**
     * Switch main tab (Thresholds/Scoring)
     */
    switchTab(tabName) {
        this.currentTab = tabName;
        
        // Update tab buttons
        document.querySelectorAll('.panel-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });
        
        // Update tab content
        document.querySelectorAll('.panel-tab-content').forEach(content => {
            const isActive = content.dataset.tabContent === tabName;
            content.classList.toggle('active', isActive);
            content.style.display = isActive ? 'block' : 'none';
        });
        
        // Render scoring rules when switching to scoring tab
        if (tabName === 'scoring' && this.currentClient) {
            setTimeout(() => {
                this.renderClientScoringRules();
            }, 50);
        }
    }
    
    /**
     * Switch panel room (Problem/Solution/Offer within Scoring tab)
     */
    switchPanelRoom(room) {
        this.currentPanelRoom = room;
        
        // Update mini tabs
        document.querySelectorAll('.panel-mini-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.panelRoom === room);
        });
        
        // Update room content
        document.querySelectorAll('.panel-room-rules').forEach(content => {
            const isActive = content.dataset.panelRoomContent === room;
            content.classList.toggle('active', isActive);
            content.style.display = isActive ? 'block' : 'none';
        });
        
        // Re-render rules for current room
        this.renderClientScoringRules();
    }
    
    // ===== THRESHOLDS METHODS =====
    
    /**
     * Load client thresholds
     */
    async loadClientThresholds(clientId) {
        try {
            const response = await this.api.get(`/room-thresholds/${clientId}`);
            
            if (response.success) {
                const data = response.data || {};
                this.clientThresholds = data.thresholds || data;
                this.isCustom = data.is_custom || false;
                
                if (!this.clientThresholds.problem_max) {
                    console.warn('Invalid threshold data, using defaults');
                    this.clientThresholds = {
                        problem_max: 40,
                        solution_max: 60,
                        offer_min: 61
                    };
                }
                
                document.getElementById('threshold-client-id').value = clientId;
                document.getElementById('client_problem_max').value = this.clientThresholds.problem_max;
                document.getElementById('client_solution_max').value = this.clientThresholds.solution_max;
                document.getElementById('client_offer_min').value = this.clientThresholds.offer_min;
                
                this.updateSourceIndicator();
                this.updateVisualSlider();
            }
        } catch (error) {
            console.error('Failed to load client thresholds:', error);
            this.emit('notification', {
                type: 'error',
                message: 'Failed to load client thresholds'
            });
        }
    }
    
    /**
     * Update source indicator
     */
    updateSourceIndicator() {
        const globalIndicator = document.getElementById('thresholds-indicator-global');
        const customIndicator = document.getElementById('thresholds-indicator-custom');
        
        if (this.isCustom) {
            globalIndicator.style.display = 'none';
            customIndicator.style.display = 'flex';
        } else {
            globalIndicator.style.display = 'flex';
            customIndicator.style.display = 'none';
        }
    }
    
    /**
     * Update global value hints
     */
    updateGlobalHints() {
        if (!this.globalThresholds) return;
        
        const problemMax = document.getElementById('global-problem-max');
        const solutionMax = document.getElementById('global-solution-max');
        const offerMin = document.getElementById('global-offer-min');
        
        if (problemMax) problemMax.textContent = this.globalThresholds.problem_max;
        if (solutionMax) solutionMax.textContent = this.globalThresholds.solution_max;
        if (offerMin) offerMin.textContent = this.globalThresholds.offer_min;
    }
    
    /**
     * Update visual slider
     */
    updateVisualSlider() {
        const problemMax = parseInt(document.getElementById('client_problem_max')?.value) || 40;
        const solutionMax = parseInt(document.getElementById('client_solution_max')?.value) || 60;
        const offerMin = parseInt(document.getElementById('client_offer_min')?.value) || 61;
        
        const total = 100;
        const problemPercent = (problemMax / total) * 100;
        const solutionPercent = ((solutionMax - problemMax) / total) * 100;
        const offerPercent = 100 - problemPercent - solutionPercent;
        
        const problemSegment = document.getElementById('client-problem-segment');
        const solutionSegment = document.getElementById('client-solution-segment');
        const offerSegment = document.getElementById('client-offer-segment');
        
        if (problemSegment) {
            problemSegment.style.flex = `0 0 ${problemPercent}%`;
            const maxValue = problemSegment.querySelector('.max-value');
            if (maxValue) maxValue.textContent = problemMax;
        }
        
        if (solutionSegment) {
            solutionSegment.style.flex = `0 0 ${solutionPercent}%`;
            const minValue = solutionSegment.querySelector('.min-value');
            const maxValue = solutionSegment.querySelector('.max-value');
            if (minValue) minValue.textContent = problemMax + 1;
            if (maxValue) maxValue.textContent = solutionMax;
        }
        
        if (offerSegment) {
            offerSegment.style.flex = `0 0 ${offerPercent}%`;
            const minValue = offerSegment.querySelector('.min-value');
            if (minValue) minValue.textContent = offerMin;
        }
    }
    
    /**
     * Validate thresholds
     */
    validateThresholds() {
        const problemMax = parseInt(document.getElementById('client_problem_max')?.value);
        const solutionMax = parseInt(document.getElementById('client_solution_max')?.value);
        const offerMin = parseInt(document.getElementById('client_offer_min')?.value);
        
        const errors = [];
        
        if (problemMax >= solutionMax) {
            errors.push('Problem max must be less than Solution max');
        }
        
        if (solutionMax >= offerMin) {
            errors.push('Solution max must be less than Offer min');
        }
        
        if (problemMax < 1 || solutionMax < 1 || offerMin < 1) {
            errors.push('All thresholds must be positive numbers');
        }
        
        this.showValidationErrors(errors);
        
        return errors.length === 0;
    }
    
    /**
     * Show validation errors
     */
    showValidationErrors(errors) {
        const container = document.getElementById('thresholds-validation');
        const messageText = container?.querySelector('.message-text');
        
        if (container && messageText) {
            if (errors.length > 0) {
                messageText.textContent = errors.join('. ');
                container.style.display = 'flex';
            } else {
                container.style.display = 'none';
            }
        }
    }
    
    /**
     * Save thresholds
     */
    async saveThresholds() {
        if (!this.validateThresholds()) {
            this.emit('notification', {
                type: 'error',
                message: 'Please fix validation errors'
            });
            return;
        }
        
        if (!this.currentClient) {
            return;
        }
        
        const btn = document.getElementById('save-settings-btn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        const formData = {
            problem_max: parseInt(document.getElementById('client_problem_max').value),
            solution_max: parseInt(document.getElementById('client_solution_max').value),
            offer_min: parseInt(document.getElementById('client_offer_min').value)
        };
        
        try {
            const response = await this.api.put(
                `/room-thresholds/${this.currentClient.id}`,
                formData
            );
            
            if (response.success) {
                this.isCustom = true;
                this.updateSourceIndicator();
                
                this.emit('notification', {
                    type: 'success',
                    message: 'Client thresholds saved successfully'
                });
                
                setTimeout(() => {
                    this.closePanel();
                }, 1000);
            }
        } catch (error) {
            console.error('Failed to save thresholds:', error);
            this.emit('notification', {
                type: 'error',
                message: 'Failed to save thresholds'
            });
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    // ===== SCORING RULES METHODS =====
    
    /**
     * Load client scoring rules
     */
    async loadClientScoringRules(clientId) {
        try {
            const response = await this.api.get(`/scoring-rules/client/${clientId}`);
            
            if (response.success) {
                const data = response.data || {};
                const roomsData = data.rules || {};
                
                this.clientScoringRules = {
                    problem: roomsData.problem?.rules || {},
                    solution: roomsData.solution?.rules || {},
                    offer: roomsData.offer?.rules || {}
                };
                
                this.globalScoringRules = {
                    problem: roomsData.problem?.global || this.globalScoringRules.problem || {},
                    solution: roomsData.solution?.global || this.globalScoringRules.solution || {},
                    offer: roomsData.offer?.global || this.globalScoringRules.offer || {}
                };
                
                this.isScoringCustom = data.is_custom || false;
                this.updateScoringIndicator();
            }
        } catch (error) {
            console.error('Failed to load client scoring rules:', error);
            this.clientScoringRules = {
                problem: {},
                solution: {},
                offer: {}
            };
            this.isScoringCustom = false;
            this.updateScoringIndicator();
        }
    }
    
    /**
     * Update scoring indicator
     */
    updateScoringIndicator() {
        const globalIndicator = document.getElementById('scoring-indicator-global');
        const customIndicator = document.getElementById('scoring-indicator-custom');
        
        if (globalIndicator && customIndicator) {
            if (this.isScoringCustom) {
                globalIndicator.style.display = 'none';
                customIndicator.style.display = 'flex';
            } else {
                globalIndicator.style.display = 'flex';
                customIndicator.style.display = 'none';
            }
        }
    }
    
    /**
     * Render client scoring rules for current room
     */
    renderClientScoringRules() {
        const room = this.currentPanelRoom;
        const container = document.getElementById(`client-${room}-rules`);
        
        if (!container) {
            console.warn(`Container not found: client-${room}-rules`);
            return;
        }
        
        const clientRules = this.clientScoringRules[room] || {};
        const globalRules = this.globalScoringRules[room] || {};
        
        const rulesToShow = this.getRulesForRoom(room);
        
        if (rulesToShow.length === 0) {
            container.innerHTML = '<div class="empty-rules">No rules configured for this room</div>';
            return;
        }
        
        container.innerHTML = rulesToShow.map(ruleKey => {
            const clientRule = clientRules[ruleKey] || {};
            const globalRule = globalRules[ruleKey] || {};
            
            const enabled = clientRule.enabled !== undefined ? clientRule.enabled : (globalRule.enabled || false);
            
            // Special handling for minimum_threshold - uses required_score instead of points
            if (ruleKey === 'minimum_threshold') {
                const requiredScore = clientRule.required_score !== undefined ? clientRule.required_score : (globalRule.required_score || 20);
                const globalRequiredScore = globalRule.required_score || 20;
                return this.renderThresholdRuleCard(room, ruleKey, enabled, requiredScore, globalRequiredScore);
            }
            
            const points = clientRule.points !== undefined ? clientRule.points : (globalRule.points || 0);
            const globalPoints = globalRule.points || 0;
            
            // Get values (client overrides global)
            const values = clientRule.values || clientRule.key_pages || globalRule.values || globalRule.key_pages || [];
            const globalValues = globalRule.values || globalRule.key_pages || [];
            
            return this.renderPanelRuleCard(room, ruleKey, enabled, points, globalPoints, values, globalValues);
        }).join('');
        
        this.attachPanelRuleListeners(room, container);
    }
    
    /**
     * Get rules to display for room
     */
    getRulesForRoom(room) {
        const rules = {
            problem: [
                'revenue',
                'company_size',
                'industry_alignment',
                'target_states',
                'multiple_visits',
                'minimum_threshold'
            ],
            solution: [
                'email_open',
                'email_click',
                'page_visit',
                'key_page_visit',
                'ad_engagement'
            ],
            offer: [
                'demo_request',
                'contact_form',
                'pricing_page',
                'pricing_question',
                'partner_referral'
            ]
        };
        
        return rules[room] || [];
    }
    
    /**
     * Get rule display name
     */
    getRuleName(ruleKey) {
        const names = {
            revenue: 'Revenue Range',
            company_size: 'Company Size',
            industry_alignment: 'Industry Match',
            target_states: 'Target States',
            visited_target_pages: 'Target Pages',
            multiple_visits: 'Multiple Visits',
            role_match: 'Role/Title Match',
            minimum_threshold: 'Minimum Score',
            email_open: 'Email Open',
            email_click: 'Email Click',
            email_multiple_click: 'Multiple Clicks',
            page_visit: 'Page Visit',
            key_page_visit: 'Key Page Visit',
            ad_engagement: 'Ad Engagement',
            demo_request: 'Demo Request',
            contact_form: 'Contact Form',
            pricing_page: 'Pricing Page',
            pricing_question: 'Pricing Question',
            partner_referral: 'Partner Referral',
            webinar_attendance: 'Webinar'
        };
        
        return names[ruleKey] || ruleKey;
    }
    
    /**
     * Check if rule has editable values
     */
    hasEditableValues(ruleKey) {
        return ['revenue', 'company_size', 'industry_alignment', 'target_states'].includes(ruleKey);
    }
    
    /**
     * Check if rule has key pages
     */
    hasKeyPages(ruleKey) {
        return ruleKey === 'key_page_visit';
    }
    
    /**
     * Render panel rule card with values
     */
    renderPanelRuleCard(room, ruleKey, enabled, points, globalPoints, values, globalValues) {
        const hasValues = this.hasEditableValues(ruleKey);
        const hasKeyPages = this.hasKeyPages(ruleKey);
        const valuesCount = values?.length || 0;
        const globalValuesCount = globalValues?.length || 0;
        
        // For industry_alignment, also get exclusion info
        let exclusionInfo = '';
        if (ruleKey === 'industry_alignment') {
            const clientRule = this.clientScoringRules[room]?.[ruleKey] || {};
            const globalRule = this.globalScoringRules[room]?.[ruleKey] || {};
            const excludedCount = clientRule.excluded_values?.length || globalRule.excluded_values?.length || 0;
            const exclusionPoints = clientRule.exclusion_points ?? globalRule.exclusion_points ?? -200;
            
            if (excludedCount > 0) {
                exclusionInfo = `
                    <div class="exclusion-badge">
                        <i class="fas fa-ban"></i>
                        ${excludedCount} excluded (${exclusionPoints} pts)
                    </div>
                `;
            }
        }
        
        return `
            <div class="panel-rule-card ${!enabled ? 'disabled' : ''}" data-rule="${ruleKey}">
                <div class="panel-rule-header">
                    <div class="panel-rule-info">
                        <div class="panel-rule-name">${this.getRuleName(ruleKey)}</div>
                        <div class="panel-rule-hint">Global: ${globalPoints} pts</div>
                    </div>
                    <div class="panel-rule-toggle">
                        <span class="toggle-label">On</span>
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   data-room="${room}" 
                                   data-rule="${ruleKey}" 
                                   data-field="enabled" 
                                   ${enabled ? 'checked' : ''} />
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="panel-rule-body">
                    <div class="panel-rule-points">
                        <label>Points:</label>
                        <input type="number" 
                               data-room="${room}" 
                               data-rule="${ruleKey}" 
                               data-field="points" 
                               value="${points}" 
                               min="0" 
                               max="100" />
                    </div>
                    ${hasValues || hasKeyPages ? `
                        <div class="panel-rule-values">
                            <div class="values-header">
                                <span class="values-label">
                                    ${hasKeyPages ? 'Key Pages' : 'Selected Values'}:
                                </span>
                                <span class="values-count">
                                    ${valuesCount} selected
                                    ${valuesCount !== globalValuesCount ? `(Global: ${globalValuesCount})` : ''}
                                </span>
                            </div>
                            ${exclusionInfo}
                            <button type="button" 
                                    class="btn-edit-values" 
                                    data-room="${room}" 
                                    data-rule="${ruleKey}">
                                <i class="fas fa-edit"></i> Edit ${hasKeyPages ? 'Pages' : 'Values'}
                            </button>
                        </div>
                    ` : ''}
                    <div class="panel-global-hint">
                        Global: <span>${globalPoints}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Render threshold rule card (minimum_threshold uses required_score, not points)
     */
    renderThresholdRuleCard(room, ruleKey, enabled, requiredScore, globalRequiredScore) {
        return `
            <div class="panel-rule-card ${!enabled ? 'disabled' : ''}" data-rule="${ruleKey}">
                <div class="panel-rule-header">
                    <div class="panel-rule-info">
                        <div class="panel-rule-name">${this.getRuleName(ruleKey)}</div>
                        <div class="panel-rule-hint">Global: ${globalRequiredScore} pts</div>
                    </div>
                    <div class="panel-rule-toggle">
                        <span class="toggle-label">On</span>
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   data-room="${room}" 
                                   data-rule="${ruleKey}" 
                                   data-field="enabled" 
                                   ${enabled ? 'checked' : ''} />
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="panel-rule-body">
                    <div class="panel-rule-points">
                        <label>Required Score:</label>
                        <input type="number" 
                               data-room="${room}" 
                               data-rule="${ruleKey}" 
                               data-field="required_score" 
                               value="${requiredScore}" 
                               min="0" 
                               max="100" />
                    </div>
                    <div class="panel-global-hint">
                        Global: <span>${globalRequiredScore}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    
    /**
     * Attach panel rule listeners
     */
    attachPanelRuleListeners(room, container) {
        // Toggle switches
        container.querySelectorAll('input[type="checkbox"][data-field="enabled"]').forEach(toggle => {
            toggle.addEventListener('change', (e) => {
                const ruleKey = e.target.dataset.rule;
                const card = e.target.closest('.panel-rule-card');
                card.classList.toggle('disabled', !e.target.checked);
                this.updateClientRuleField(room, ruleKey, 'enabled', e.target.checked);
            });
        });
        
        // Number inputs
        container.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('change', (e) => {
                const ruleKey = e.target.dataset.rule;
                const field = e.target.dataset.field;
                this.updateClientRuleField(room, ruleKey, field, parseInt(e.target.value));
            });
        });
        
        // Edit values buttons
        container.querySelectorAll('.btn-edit-values').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const room = e.currentTarget.dataset.room;
                const ruleKey = e.currentTarget.dataset.rule;
                this.openValueEditor(room, ruleKey);
            });
        });
    }
    
    /**
     * Update client rule field
     */
    updateClientRuleField(room, ruleKey, field, value) {
        if (!this.clientScoringRules[room]) {
            this.clientScoringRules[room] = {};
        }
        if (!this.clientScoringRules[room][ruleKey]) {
            this.clientScoringRules[room][ruleKey] = {};
        }
        this.clientScoringRules[room][ruleKey][field] = value;
    }
    
    /**
     * Open value editor modal
     */
    openValueEditor(room, ruleKey) {
        this.currentEditingRule = { room, ruleKey };
        
        const modalTitle = this.editingModal.querySelector('#editing-modal-title');
        const modalBody = this.editingModal.querySelector('#editing-modal-body');
        
        modalTitle.textContent = `Edit ${this.getRuleName(ruleKey)}`;
        
        // Get current and global values
        const clientRule = this.clientScoringRules[room][ruleKey] || {};
        const globalRule = this.globalScoringRules[room][ruleKey] || {};
        
        let currentValues = clientRule.values || clientRule.key_pages || globalRule.values || globalRule.key_pages || [];
        const globalValues = globalRule.values || globalRule.key_pages || [];
        
        // Render appropriate editor
        if (ruleKey === 'industry_alignment') {
            modalBody.innerHTML = this.renderIndustryEditor(currentValues, globalValues);
        } else if (ruleKey === 'key_page_visit') {
            modalBody.innerHTML = this.renderKeyPagesEditor(currentValues, globalValues);
        } else {
            modalBody.innerHTML = this.renderValuesEditor(ruleKey, currentValues, globalValues);
        }
        
        // Attach editor-specific listeners
        this.attachEditorListeners(ruleKey);
        
        // Show the modal with proper display
        this.editingModal.style.display = 'flex';
        
        // Prevent body scroll when modal is open
        document.body.style.overflow = 'hidden';
    }
    
    /**
     * Render values editor (revenue, company_size, states)
     */
    renderValuesEditor(ruleKey, currentValues, globalValues) {
        const presets = this.getPresetsForRule(ruleKey);
        
        return `
            <div class="values-editor">
                <div class="editor-section">
                    <h4>Current Values</h4>
                    <div class="values-list" id="current-values-list">
                        ${currentValues.length > 0 ? currentValues.map(v => `
                            <div class="value-chip">
                                <span>${v}</span>
                                <button type="button" class="remove-value" data-value="${v}">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('') : '<div class="empty-message">No values selected</div>'}
                    </div>
                </div>
                
                <div class="editor-section">
                    <h4>Available Values</h4>
                    <div class="preset-values">
                        ${presets.map(preset => {
                            const isSelected = currentValues.includes(preset);
                            return `
                                <div class="preset-chip ${isSelected ? 'selected' : ''}" data-value="${preset}">
                                    <span>${preset}</span>
                                    ${isSelected ? '<i class="fas fa-check"></i>' : '<i class="fas fa-plus"></i>'}
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
                
                ${globalValues.length > 0 ? `
                    <div class="editor-section global-reference">
                        <h4>Global Default Values</h4>
                        <div class="global-values-display">
                            ${globalValues.map(v => `<span class="global-chip">${v}</span>`).join('')}
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
    }
    
    /**
     * Render industry editor with match and exclude sections
     */
    renderIndustryEditor(currentValues, globalValues) {
        const allIndustries = this.config.industries || [];
        const { room, ruleKey } = this.currentEditingRule;
        
        // Get exclusion values
        const clientRule = this.clientScoringRules[room]?.[ruleKey] || {};
        const globalRule = this.globalScoringRules[room]?.[ruleKey] || {};
        const excludedValues = clientRule.excluded_values || globalRule.excluded_values || [];
        const globalExcludedValues = globalRule.excluded_values || [];
        const exclusionPoints = clientRule.exclusion_points ?? globalRule.exclusion_points ?? -200;
        
        return `
            <div class="industry-editor">
                <div class="editor-section">
                    <input type="text" 
                           id="industry-search" 
                           placeholder="Search industries..." 
                           class="search-input" />
                </div>
                
                <!-- Match Industries Section -->
                <div class="editor-section match-section">
                    <h4><i class="fas fa-check-circle" style="color: #28a745;"></i> Match Industries (${currentValues.length})</h4>
                    <p class="section-help">Visitors from these industries will receive positive points</p>
                    <div class="values-list" id="current-values-list">
                        ${currentValues.length > 0 ? currentValues.map(v => `
                            <div class="value-chip">
                                <span>${v.replace('|', ' → ')}</span>
                                <button type="button" class="remove-value" data-value="${v}" data-mode="match">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('') : '<div class="empty-message">No industries selected - all industries will qualify</div>'}
                    </div>
                </div>
                
                <!-- Exclude Industries Section -->
                <div class="editor-section exclude-section">
                    <div class="exclude-header">
                        <h4><i class="fas fa-ban" style="color: #dc3545;"></i> Exclude Industries (${excludedValues.length})</h4>
                        <div class="exclusion-points-config">
                            <label>Penalty:</label>
                            <input type="number" 
                                   id="exclusion-points-input"
                                   value="${exclusionPoints}" 
                                   max="0" 
                                   step="10" />
                        </div>
                    </div>
                    <p class="section-help section-help-warning">Double-click on an industry to add them to the exclusion list. Visitors from these industries will be disqualified and hidden from dashboard</p>
                    <div class="values-list excluded-values-list" id="excluded-values-list">
                        ${excludedValues.length > 0 ? excludedValues.map(v => `
                            <div class="value-chip excluded">
                                <span>${v.replace('|', ' → ')}</span>
                                <button type="button" class="remove-value" data-value="${v}" data-mode="exclude">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('') : '<div class="empty-message">No industries excluded</div>'}
                    </div>
                </div>
                
                <!-- Available Industries -->
                <div class="editor-section">
                    <h4>Available Industries</h4>
                    <div class="industry-list" id="industry-list">
                        ${Object.keys(allIndustries).map(category => `
                            <div class="industry-category">
                                <h5>${category}</h5>
                                <div class="industry-chips">
                                    ${allIndustries[category].map(sub => {
                                        const value = `${category}|${sub}`;
                                        const isMatched = currentValues.includes(value);
                                        const isExcluded = excludedValues.includes(value);
                                        let chipClass = 'preset-chip';
                                        let icon = '<i class="fas fa-plus"></i>';
                                        
                                        if (isMatched) {
                                            chipClass += ' selected';
                                            icon = '<i class="fas fa-check"></i>';
                                        } else if (isExcluded) {
                                            chipClass += ' excluded';
                                            icon = '<i class="fas fa-ban"></i>';
                                        }
                                        
                                        return `
                                            <div class="${chipClass}" data-value="${value}">
                                                <span>${sub}</span>
                                                ${icon}
                                            </div>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                
                <!-- Global Reference -->
                ${globalValues.length > 0 || globalExcludedValues.length > 0 ? `
                    <div class="editor-section global-reference">
                        <h4>Global Defaults</h4>
                        ${globalValues.length > 0 ? `
                            <div class="global-subsection">
                                <span class="global-label">Match:</span>
                                <div class="global-values-display">
                                    ${globalValues.map(v => `<span class="global-chip">${v.replace('|', ' → ')}</span>`).join('')}
                                </div>
                            </div>
                        ` : ''}
                        ${globalExcludedValues.length > 0 ? `
                            <div class="global-subsection">
                                <span class="global-label global-label-exclude">Exclude:</span>
                                <div class="global-values-display">
                                    ${globalExcludedValues.map(v => `<span class="global-chip excluded">${v.replace('|', ' → ')}</span>`).join('')}
                                </div>
                            </div>
                        ` : ''}
                    </div>
                ` : ''}
            </div>
        `;
    }
    
    /**
     * Render key pages editor
     */
    renderKeyPagesEditor(currentPages, globalPages) {
        return `
            <div class="key-pages-editor">
                <div class="editor-section">
                    <h4>Current Key Pages</h4>
                    <div class="values-list" id="current-values-list">
                        ${currentPages.length > 0 ? currentPages.map(page => `
                            <div class="value-chip">
                                <span>${page}</span>
                                <button type="button" class="remove-value" data-value="${page}">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('') : '<div class="empty-message">No key pages defined</div>'}
                    </div>
                </div>
                
                <div class="editor-section">
                    <h4>Add Key Page</h4>
                    <div class="add-page-form">
                        <input type="text" 
                               id="page-url-input" 
                               placeholder="/pricing or /demo" 
                               class="form-input" />
                        <button type="button" id="add-page-btn" class="btn btn-secondary">
                            <i class="fas fa-plus"></i> Add Page
                        </button>
                    </div>
                    <small class="help-text">Enter page paths like /pricing, /demo, or /contact</small>
                </div>
                
                ${globalPages.length > 0 ? `
                    <div class="editor-section global-reference">
                        <h4>Global Default Pages</h4>
                        <div class="global-values-display">
                            ${globalPages.map(p => `<span class="global-chip">${p}</span>`).join('')}
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
    }
    
    /**
     * Get preset values for rule
     */
    getPresetsForRule(ruleKey) {
        const presets = {
            revenue: [
                'Under $1M',
                '$1M - $5M',
                '$5M - $10M',
                '$10M - $50M',
                '$50M - $100M',
                'Over $100M'
            ],
            company_size: [
                '1-10',
                '11-50',
                '51-200',
                '201-500',
                '501-1000',
                '1001-5000',
                '5000+'
            ],
            target_states: [
                'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
                'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
                'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
                'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
                'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'
            ]
        };
        
        return presets[ruleKey] || [];
    }
    
    /**
     * Attach editor-specific listeners
     */
    attachEditorListeners(ruleKey) {
        const modalBody = this.editingModal.querySelector('#editing-modal-body');
        
        // Remove value buttons
        modalBody.querySelectorAll('.remove-value').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const value = e.currentTarget.dataset.value;
                const mode = e.currentTarget.dataset.mode || 'match';
                this.removeValueFromEditor(value, mode);
            });
        });
        
        // Preset chips - handle industry with mode cycling
        if (ruleKey === 'industry_alignment') {
            modalBody.querySelectorAll('.preset-chip').forEach(chip => {
                chip.addEventListener('click', (e) => {
                    const value = e.currentTarget.dataset.value;
                    this.cycleIndustryState(value, e.currentTarget);
                });
            });
        } else {
            modalBody.querySelectorAll('.preset-chip').forEach(chip => {
                chip.addEventListener('click', (e) => {
                    const value = e.currentTarget.dataset.value;
                    this.toggleValueInEditor(value);
                });
            });
        }
        
        // Key pages specific
        if (ruleKey === 'key_page_visit') {
            const addBtn = modalBody.querySelector('#add-page-btn');
            const input = modalBody.querySelector('#page-url-input');
            
            if (addBtn && input) {
                addBtn.addEventListener('click', () => {
                    const value = input.value.trim();
                    if (value) {
                        this.addValueToEditor(value);
                        input.value = '';
                    }
                });
                
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        addBtn.click();
                    }
                });
            }
        }
        
        // Industry search
        if (ruleKey === 'industry_alignment') {
            const searchInput = modalBody.querySelector('#industry-search');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    this.filterIndustries(e.target.value);
                });
            }
        }
    }
    
    /**
     * Toggle value in editor
     */
    toggleValueInEditor(value) {
        const currentList = this.editingModal.querySelector('#current-values-list');
        const chips = currentList.querySelectorAll('.value-chip');
        const values = Array.from(chips).map(chip => chip.querySelector('span').textContent);
        
        if (values.includes(value) || values.includes(value.replace('|', ' → '))) {
            this.removeValueFromEditor(value);
        } else {
            this.addValueToEditor(value);
        }
    }
    
    /**
     * Add value to editor
     */
    addValueToEditor(value) {
        const currentList = this.editingModal.querySelector('#current-values-list');
        const emptyMessage = currentList.querySelector('.empty-message');
        
        if (emptyMessage) {
            emptyMessage.remove();
        }
        
        const displayValue = value.includes('|') ? value.replace('|', ' → ') : value;
        
        const chip = document.createElement('div');
        chip.className = 'value-chip';
        chip.innerHTML = `
            <span>${displayValue}</span>
            <button type="button" class="remove-value" data-value="${value}">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        currentList.appendChild(chip);
        
        // Attach remove listener
        chip.querySelector('.remove-value').addEventListener('click', (e) => {
            this.removeValueFromEditor(value);
        });
        
        // Update preset chip state
        const presetChip = this.editingModal.querySelector(`.preset-chip[data-value="${value}"]`);
        if (presetChip) {
            presetChip.classList.add('selected');
            presetChip.querySelector('i').className = 'fas fa-check';
        }
    }
    
    /**
     * Remove value from editor
     */
    removeValueFromEditor(value) {
        const currentList = this.editingModal.querySelector('#current-values-list');
        const chips = currentList.querySelectorAll('.value-chip');
        
        chips.forEach(chip => {
            const chipValue = chip.querySelector('span').textContent;
            const normalizedValue = value.includes('|') ? value.replace('|', ' → ') : value;
            
            if (chipValue === normalizedValue || chipValue === value) {
                chip.remove();
            }
        });
        
        // Show empty message if no values
        if (currentList.querySelectorAll('.value-chip').length === 0) {
            currentList.innerHTML = '<div class="empty-message">No values selected</div>';
        }
        
        // Update preset chip state
        const presetChip = this.editingModal.querySelector(`.preset-chip[data-value="${value}"]`);
        if (presetChip) {
            presetChip.classList.remove('selected');
            presetChip.querySelector('i').className = 'fas fa-plus';
        }
    }
    
    /**
     * Cycle industry state: none -> match -> exclude -> none
     */
    cycleIndustryState(value, chipElement) {
        const isMatched = chipElement.classList.contains('selected');
        const isExcluded = chipElement.classList.contains('excluded');
        
        if (!isMatched && !isExcluded) {
            // Add to match
            this.addValueToEditor(value, 'match');
            chipElement.classList.add('selected');
            chipElement.classList.remove('excluded');
            chipElement.querySelector('i').className = 'fas fa-check';
        } else if (isMatched) {
            // Move to exclude
            this.removeValueFromEditor(value, 'match');
            this.addValueToEditor(value, 'exclude');
            chipElement.classList.remove('selected');
            chipElement.classList.add('excluded');
            chipElement.querySelector('i').className = 'fas fa-ban';
        } else {
            // Remove from exclude
            this.removeValueFromEditor(value, 'exclude');
            chipElement.classList.remove('excluded');
            chipElement.querySelector('i').className = 'fas fa-plus';
        }
    }
    
    /**
     * Add value to editor (with mode support)
     */
    addValueToEditor(value, mode = 'match') {
        const listId = mode === 'exclude' ? '#excluded-values-list' : '#current-values-list';
        const currentList = this.editingModal.querySelector(listId);
        if (!currentList) return;
        
        const emptyMessage = currentList.querySelector('.empty-message');
        if (emptyMessage) {
            emptyMessage.remove();
        }
        
        const displayValue = value.includes('|') ? value.replace('|', ' → ') : value;
        
        const chip = document.createElement('div');
        chip.className = mode === 'exclude' ? 'value-chip excluded' : 'value-chip';
        chip.innerHTML = `
            <span>${displayValue}</span>
            <button type="button" class="remove-value" data-value="${value}" data-mode="${mode}">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        currentList.appendChild(chip);
        
        // Attach remove listener
        chip.querySelector('.remove-value').addEventListener('click', (e) => {
            this.removeValueFromEditor(value, mode);
        });
        
        // Update preset chip state
        const presetChip = this.editingModal.querySelector(`.preset-chip[data-value="${value}"]`);
        if (presetChip) {
            if (mode === 'exclude') {
                presetChip.classList.add('excluded');
                presetChip.classList.remove('selected');
                presetChip.querySelector('i').className = 'fas fa-ban';
            } else {
                presetChip.classList.add('selected');
                presetChip.classList.remove('excluded');
                presetChip.querySelector('i').className = 'fas fa-check';
            }
        }
    }
    
    /**
     * Remove value from editor (with mode support)
     */
    removeValueFromEditor(value, mode = 'match') {
        const listId = mode === 'exclude' ? '#excluded-values-list' : '#current-values-list';
        const currentList = this.editingModal.querySelector(listId);
        if (!currentList) return;
        
        const chips = currentList.querySelectorAll('.value-chip');
        
        chips.forEach(chip => {
            const chipValue = chip.querySelector('span').textContent;
            const normalizedValue = value.includes('|') ? value.replace('|', ' → ') : value;
            
            if (chipValue === normalizedValue || chipValue === value) {
                chip.remove();
            }
        });
        
        // Show empty message if no values
        if (currentList.querySelectorAll('.value-chip').length === 0) {
            const emptyText = mode === 'exclude' ? 'No industries excluded' : 'No industries selected - all industries will qualify';
            currentList.innerHTML = `<div class="empty-message">${emptyText}</div>`;
        }
        
        // Update preset chip state
        const presetChip = this.editingModal.querySelector(`.preset-chip[data-value="${value}"]`);
        if (presetChip) {
            presetChip.classList.remove('selected', 'excluded');
            presetChip.querySelector('i').className = 'fas fa-plus';
        }
    }

    /**
     * Filter industries by search
     */
    filterIndustries(searchTerm) {
        const term = searchTerm.toLowerCase();
        const categories = this.editingModal.querySelectorAll('.industry-category');
        
        categories.forEach(category => {
            let hasVisible = false;
            const chips = category.querySelectorAll('.preset-chip');
            
            chips.forEach(chip => {
                const text = chip.textContent.toLowerCase();
                const visible = text.includes(term);
                chip.style.display = visible ? 'flex' : 'none';
                if (visible) hasVisible = true;
            });
            
            category.style.display = hasVisible ? 'block' : 'none';
        });
    }
    
    /**
     * Save editing modal
     */
    saveEditingModal() {
        if (!this.currentEditingRule) return;
        
        const { room, ruleKey } = this.currentEditingRule;
        
        // Update client rules
        if (!this.clientScoringRules[room]) {
            this.clientScoringRules[room] = {};
        }
        if (!this.clientScoringRules[room][ruleKey]) {
            this.clientScoringRules[room][ruleKey] = {};
        }
        
        // Handle industry_alignment specially
        if (ruleKey === 'industry_alignment') {
            // Get match values
            const matchList = this.editingModal.querySelector('#current-values-list');
            const matchChips = matchList?.querySelectorAll('.value-chip') || [];
            const matchValues = Array.from(matchChips).map(chip => {
                const text = chip.querySelector('span').textContent;
                return text.replace(' → ', '|');
            });
            
            // Get exclude values
            const excludeList = this.editingModal.querySelector('#excluded-values-list');
            const excludeChips = excludeList?.querySelectorAll('.value-chip') || [];
            const excludedValues = Array.from(excludeChips).map(chip => {
                const text = chip.querySelector('span').textContent;
                return text.replace(' → ', '|');
            });
            
            // Get exclusion points
            const exclusionPointsInput = this.editingModal.querySelector('#exclusion-points-input');
            const exclusionPoints = parseInt(exclusionPointsInput?.value) || -200;
            
            this.clientScoringRules[room][ruleKey].values = matchValues;
            this.clientScoringRules[room][ruleKey].excluded_values = excludedValues;
            this.clientScoringRules[room][ruleKey].exclusion_points = exclusionPoints;
        } else {
            // Original logic for other rules
            const currentList = this.editingModal.querySelector('#current-values-list');
            const chips = currentList?.querySelectorAll('.value-chip') || [];
            
            const values = Array.from(chips).map(chip => {
                const text = chip.querySelector('span').textContent;
                return text.replace(' → ', '|');
            });
            
            // Store in correct field
            if (ruleKey === 'key_page_visit') {
                this.clientScoringRules[room][ruleKey].key_pages = values;
            } else {
                this.clientScoringRules[room][ruleKey].values = values;
            }
        }
        
        // Re-render the room rules
        this.renderClientScoringRules(this.currentPanelRoom);
        
        // Close modal
        this.closeEditingModal();
        
        // Show feedback
        this.emit('notification', {
            type: 'info',
            message: 'Values updated. Click Save to apply changes.'
        });
    }
    
    /**
     * Close editing modal
     */
    closeEditingModal() {
        this.editingModal.style.display = 'none';
        this.currentEditingRule = null;
        
        // Restore body scroll
        document.body.style.overflow = '';
    }
    
    /**
     * Save client scoring rules
     */
    async saveClientScoringRules() {
        if (!this.currentClient) {
            return;
        }
        
        const btn = document.getElementById('save-settings-btn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        try {
            const rooms = ['problem', 'solution', 'offer'];
            const savePromises = rooms.map(room => {
                const completeRules = this.buildCompleteRulesForRoom(room);
                
                return this.api.put(
                    `/scoring-rules/client/${this.currentClient.id}`,
                    {
                        room: room,
                        rules_config: completeRules
                    }
                );
            });
            
            const responses = await Promise.all(savePromises);
            const allSucceeded = responses.every(response => response.success);
            
            if (allSucceeded) {
                this.isScoringCustom = true;
                this.updateScoringIndicator();
                
                this.emit('notification', {
                    type: 'success',
                    message: 'Client scoring rules saved successfully'
                });
                
                setTimeout(() => {
                    this.closePanel();
                }, 1000);
            } else {
                throw new Error('Some rules failed to save');
            }
        } catch (error) {
            console.error('Failed to save client scoring rules:', error);
            this.emit('notification', {
                type: 'error',
                message: error.message || 'Failed to save scoring rules'
            });
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    /**
     * Build complete rules object for a room
     */
    buildCompleteRulesForRoom(room) {
        const clientRules = this.clientScoringRules[room] || {};
        const globalRules = this.globalScoringRules[room] || {};
        const allRequiredRules = this.getAllRequiredRulesForRoom(room);
        
        const completeRules = {};
        
        allRequiredRules.forEach(ruleKey => {
            const clientRule = clientRules[ruleKey] || {};
            const globalRule = globalRules[ruleKey] || {};
            
            // Start with global rule
            completeRules[ruleKey] = { ...globalRule };
            
            // Override with client values
            if (Object.keys(clientRule).length > 0) {
                completeRules[ruleKey] = {
                    ...completeRules[ruleKey],
                    ...clientRule
                };
            }
            
            // Ensure enabled field exists
            if (completeRules[ruleKey].enabled === undefined) {
                completeRules[ruleKey].enabled = false;
            }
        });
        
        return completeRules;
    }

    /**
     * Get ALL required rules for a room
     */
    getAllRequiredRulesForRoom(room) {
        const allRules = {
            problem: [
                'revenue',
                'company_size',
                'industry_alignment',
                'target_states',
                'visited_target_pages',
                'multiple_visits',
                'role_match',
                'minimum_threshold'
            ],
            solution: [
                'email_open',
                'email_click',
                'email_multiple_click',
                'page_visit',
                'key_page_visit',
                'ad_engagement'
            ],
            offer: [
                'demo_request',
                'contact_form',
                'pricing_page',
                'pricing_question',
                'partner_referral',
                'webinar_attendance'
            ]
        };
        
        return allRules[room] || [];
    }    
    
    // ===== SHARED METHODS =====
    
    /**
     * Save settings
     */
    async saveSettings() {
        if (this.currentTab === 'thresholds') {
            await this.saveThresholds();
        } else if (this.currentTab === 'scoring') {
            await this.saveClientScoringRules();
        }
    }
    
    /**
     * Reset to global
     */
    async resetToGlobal() {
        if (this.currentTab === 'thresholds') {
            await this.resetThresholdsToGlobal();
        } else if (this.currentTab === 'scoring') {
            await this.resetScoringRulesToGlobal();
        }
    }
    
    /**
     * Reset thresholds to global
     */
    async resetThresholdsToGlobal() {
        if (!confirm('Reset this client\'s thresholds to global defaults? This cannot be undone.')) {
            return;
        }
        
        if (!this.currentClient) {
            return;
        }
        
        const btn = document.getElementById('reset-to-global-btn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
        
        try {
            const response = await this.api.delete(`/room-thresholds/${this.currentClient.id}`);
            
            if (response.success) {
                this.emit('notification', {
                    type: 'success',
                    message: 'Reset to global defaults'
                });
                
                await this.loadClientThresholds(this.currentClient.id);
            }
        } catch (error) {
            console.error('Failed to reset thresholds:', error);
            this.emit('notification', {
                type: 'error',
                message: 'Failed to reset thresholds'
            });
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    /**
     * Reset scoring rules to global
     */
    async resetScoringRulesToGlobal() {
        if (!confirm('Reset this client\'s scoring rules to global defaults? This cannot be undone.')) {
            return;
        }
        
        if (!this.currentClient) {
            return;
        }
        
        const btn = document.getElementById('reset-to-global-btn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
        
        try {
            const response = await this.api.delete(`/scoring-rules/client/${this.currentClient.id}`);
            
            if (response.success) {
                this.emit('notification', {
                    type: 'success',
                    message: 'Reset to global defaults'
                });
                
                await this.loadClientScoringRules(this.currentClient.id);
                this.renderClientScoringRules();
            }
        } catch (error) {
            console.error('Failed to reset scoring rules:', error);
            this.emit('notification', {
                type: 'error',
                message: 'Failed to reset scoring rules'
            });
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    /**
     * Show loading state
     */
    showLoading() {
        const loading = document.getElementById('panel-loading');
        if (loading) loading.style.display = 'flex';
        
        document.querySelectorAll('.panel-tab-content').forEach(content => {
            content.style.display = 'none';
        });
    }
    
    /**
     * Hide loading state
     */
    hideLoading() {
        const loading = document.getElementById('panel-loading');
        if (loading) loading.style.display = 'none';
        
        const activeContent = document.querySelector(`.panel-tab-content[data-tab-content="${this.currentTab}"]`);
        if (activeContent) {
            activeContent.style.display = 'block';
            activeContent.classList.add('active');
        }
    }
}