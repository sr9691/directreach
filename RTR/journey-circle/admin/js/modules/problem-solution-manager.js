/**
 * Problem/Solution Manager Module
 * 
 * Handles Steps 4-6 of the Journey Circle Creator:
 * - Step 4: Industry Selection
 * - Step 5: Primary Problem Designation
 * - Step 6: Problem Title Selection (select 5 from 8-10)
 * 
 * All data is fetched from and saved to the database via REST API.
 * No mock data - everything is live.
 * 
 * @package DirectReach
 * @subpackage JourneyCircle
 * @since 2.0.0
 */

class ProblemSolutionManager {
    /**
     * Initialize the Problem/Solution Manager
     * 
     * @param {Object} workflow - Reference to the main workflow instance
     * @param {Object} options - Configuration options
     */
    constructor(workflow, options = {}) {
        this.workflow = workflow;
        this.options = {
            apiBase: options.apiBase || '/wp-json/directreach/v2',
            nonce: options.nonce || '',
            ...options
        };

        // State for this module
        this.industries = [];
        this.selectedIndustries = [];
        this.problemRecommendations = [];
        this.selectedProblems = [];
        this.primaryProblemId = null;

        // Industry taxonomy (loaded from API)
        this.industryTaxonomy = {};
        this.industryTaxonomyLoaded = false;

        // Loading states
        this.isLoadingIndustries = false;
        this.isLoadingProblems = false;
        this.isLoadingRecommendations = false;

        // Init guards to prevent double-initialization
        this._step5Initializing = false;

        // Bind event handlers
        this.handleIndustryChange = this.handleIndustryChange.bind(this);
        this.handlePrimaryProblemSelect = this.handlePrimaryProblemSelect.bind(this);
        this.handleProblemTitleToggle = this.handleProblemTitleToggle.bind(this);
    }

    // =========================================================================
    // API METHODS - ALL DATA COMES FROM THE DATABASE
    // =========================================================================

    /**
     * Fetch industry taxonomy from API
     * @returns {Promise<Object>} Industry taxonomy
     */
    async fetchIndustryTaxonomy() {
        if (this.industryTaxonomyLoaded && Object.keys(this.industryTaxonomy).length > 0) {
            return this.industryTaxonomy;
        }

        this.isLoadingIndustries = true;

        try {
            const response = await fetch(`${this.options.apiBase}/industries`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.options.nonce
                }
            });

            if (!response.ok) {
                throw new Error(`Failed to fetch industries: ${response.status}`);
            }

            const data = await response.json();
            this.industryTaxonomy = data.taxonomy || {};
            this.industryTaxonomyLoaded = true;
            return this.industryTaxonomy;

        } catch (error) {
            console.error('Error fetching industry taxonomy:', error);
            this.showNotification('Failed to load industries. Please refresh the page.', 'error');
            return {};
        } finally {
            this.isLoadingIndustries = false;
        }
    }

    /**
     * Fetch saved industries for this journey circle
     * @returns {Promise<Array>} Selected industries
     */
    async fetchSavedIndustries() {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) return [];

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/industries`,
                {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    }
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to fetch saved industries: ${response.status}`);
            }

            const data = await response.json();
            return data.industries || [];

        } catch (error) {
            console.error('Error fetching saved industries:', error);
            return [];
        }
    }

    /**
     * Save industries to database
     * @param {Array} industries - Selected industries
     * @returns {Promise<boolean>} Success status
     */
    async saveIndustriesToDatabase(industries) {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) {
            console.error('No journey circle ID available');
            return false;
        }

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/industries`,
                {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({ industries })
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to save industries: ${response.status}`);
            }

            return true;

        } catch (error) {
            console.error('Error saving industries:', error);
            this.showNotification('Failed to save industries. Please try again.', 'error');
            return false;
        }
    }

    /**
     * Fetch problems from database
     * Returns only what exists in the database - no mock data
     * @returns {Promise<Array>} Problems from database
     */
    async fetchProblemRecommendations() {
        const state = this.workflow.getState();
        const journeyCircleId = state.journeyCircleId;
        
        if (!journeyCircleId) {
            console.error('No journey circle ID available');
            return [];
        }

        this.isLoadingRecommendations = true;

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/recommendations/problems`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({
                        journey_circle_id: journeyCircleId
                    })
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to fetch problems: ${response.status}`);
            }

            const data = await response.json();
            this.problemRecommendations = data.recommendations || [];
            return this.problemRecommendations;

        } catch (error) {
            console.error('Error fetching problems from database:', error);
            // Don't show error for empty results - that's expected for new circles
            return [];
        } finally {
            this.isLoadingRecommendations = false;
        }
    }

    /**
     * Fetch saved problems from database
     * @returns {Promise<Array>} Saved problems
     */
    async fetchSavedProblems() {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) return [];

        this.isLoadingProblems = true;

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/problems`,
                {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    }
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to fetch problems: ${response.status}`);
            }

            const data = await response.json();
            return data.problems || [];

        } catch (error) {
            console.error('Error fetching saved problems:', error);
            return [];
        } finally {
            this.isLoadingProblems = false;
        }
    }

    /**
     * Save problems to database (bulk save)
     * @param {Array} problems - Problems to save
     * @returns {Promise<Object>} Save result
     */
    async saveProblemsToDatabase(problems) {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) {
            throw new Error('No journey circle ID available');
        }

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/problems/bulk`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({
                        problems: problems.map(p => ({
                            title: p.title,
                            description: p.description || '',
                            category: p.category || '',
                            is_primary: p.isPrimary || false,
                            position: p.position,
                            status: 'draft'
                        }))
                    })
                }
            );

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || 'Failed to save problems');
            }

            const result = await response.json();
            
            // Update local state with database IDs
            if (result.created_ids) {
                result.created_ids.forEach((id, index) => {
                    if (this.selectedProblems[index]) {
                        this.selectedProblems[index].id = id;
                        this.selectedProblems[index].databaseId = id;
                    }
                });
            }

            this.saveProblemsToState();
            this.showNotification('Problems saved successfully!', 'success');
            return result;

        } catch (error) {
            console.error('Error saving problems:', error);
            this.showNotification('Failed to save problems. Please try again.', 'error');
            throw error;
        }
    }

    /**
     * Update primary problem in database
     * @param {number} problemId - Problem ID to set as primary
     * @returns {Promise<boolean>} Success status
     */
    async updatePrimaryProblem(problemId) {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) return false;

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}`,
                {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({
                        primary_problem_id: problemId
                    })
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to update primary problem: ${response.status}`);
            }

            return true;

        } catch (error) {
            console.error('Error updating primary problem:', error);
            return false;
        }
    }

    // =========================================================================
    // STEP 4: INDUSTRY SELECTION
    // =========================================================================

    /**
     * Initialize Step 4: Industry Selection
     */
    async initStep4() {
        // Try both possible container IDs
        const container = document.getElementById('step-4-content') || document.getElementById('jc-industry-list');
        if (!container) {
            console.error('Step 4 container not found');
            return;
        }

        // Show loading state
        container.innerHTML = this.renderLoadingState('Loading industries...');

        // Load industry taxonomy from API
        await this.fetchIndustryTaxonomy();

        // Load saved state (from local state first, then try API)
        const state = this.workflow.getState();
        this.selectedIndustries = state.industries || [];

        // If no local state, try to fetch from database
        if (this.selectedIndustries.length === 0 && state.journeyCircleId) {
            const savedIndustries = await this.fetchSavedIndustries();
            if (savedIndustries.length > 0) {
                this.selectedIndustries = savedIndustries;
                this.saveIndustriesToState();
            }
        }

        // Render the industry selector
        this.renderIndustrySelector(container);

        // Attach event listeners
        this.attachIndustryListeners();
    }

    /**
     * Render loading state
     */
    renderLoadingState(message) {
        return `
            <div class="jc-loading-state">
                <div class="jc-loading-spinner">
                    <i class="fas fa-spinner fa-spin fa-3x"></i>
                </div>
                <p>${message}</p>
            </div>
        `;
    }

    /**
     * Render the industry multi-select component
     */
    renderIndustrySelector(container) {
        if (Object.keys(this.industryTaxonomy).length === 0) {
            container.innerHTML = `
                <div class="jc-error-state">
                    <i class="fas fa-exclamation-triangle fa-3x"></i>
                    <h3>Unable to Load Industries</h3>
                    <p>Please check your connection and refresh the page.</p>
                    <button type="button" class="jc-btn jc-btn-primary jc-retry-btn" onclick="location.reload()">
                        <i class="fas fa-redo"></i> Refresh Page
                    </button>
                </div>
            `;
            return;
        }

        const html = `
            <div class="jc-industry-selector">
                <div class="jc-step-header">
                    <h3>Select Target Industries</h3>
                    <p class="jc-step-description">
                        Choose the industries you want to target with this journey circle. 
                        This helps generate more relevant problem and solution recommendations.
                    </p>
                </div>

                <div class="jc-industry-controls">
                    <div class="jc-industry-actions">
                        <button type="button" class="jc-btn jc-btn-secondary jc-select-all-industries">
                            <i class="fas fa-check-double"></i> Select All Industries
                        </button>
                        <button type="button" class="jc-btn jc-btn-secondary jc-clear-industries">
                            <i class="fas fa-times"></i> Clear Selection
                        </button>
                    </div>
                    <div class="jc-industry-search">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="jc-industry-search" 
                               placeholder="Search industries..." 
                               class="jc-search-input">
                    </div>
                </div>

                <div class="jc-selected-count">
                    <span id="jc-selected-industries-count">${this.selectedIndustries.length}</span> industries selected
                </div>

                <div class="jc-industry-list" id="jc-industry-list">
                    ${this.renderIndustryCategories()}
                </div>

                <div class="jc-selected-industries-display" id="jc-selected-industries-display">
                    <h4>Selected Industries:</h4>
                    <div class="jc-selected-tags" id="jc-selected-tags">
                        ${this.selectedIndustries.length === 0 
                            ? '<span class="jc-empty-selection">No industries selected</span>'
                            : this.renderSelectedTags()
                        }
                    </div>
                </div>
            </div>
        `;

        container.innerHTML = html;
    }

    /**
     * Render industry categories with their subcategories
     */
    renderIndustryCategories() {
        let html = '';

        Object.entries(this.industryTaxonomy).forEach(([category, subcategories]) => {
            const categoryId = this.slugify(category);
            const hasSubcategories = subcategories && subcategories.length > 0;

            html += `
                <div class="jc-industry-category" data-category="${categoryId}">
                    <div class="jc-category-header">
                        <label class="jc-checkbox-label jc-category-checkbox-label">
                            <input type="checkbox" 
                                   class="jc-industry-checkbox jc-category-checkbox"
                                   data-category="${category}"
                                   data-value="${category}"
                                   ${this.isSelected(category) ? 'checked' : ''}>
                            <span class="jc-checkbox-custom"></span>
                            <span class="jc-category-name">${category}</span>
                        </label>
                        ${hasSubcategories ? `
                            <button type="button" class="jc-category-toggle" data-category="${categoryId}">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        ` : ''}
                    </div>
                    ${hasSubcategories ? `
                        <div class="jc-subcategory-list" id="jc-subcategories-${categoryId}">
                            ${subcategories.map(sub => `
                                <label class="jc-checkbox-label jc-subcategory-item">
                                    <input type="checkbox" 
                                           class="jc-industry-checkbox jc-subcategory-checkbox"
                                           data-category="${category}"
                                           data-subcategory="${sub}"
                                           data-value="${category}|${sub}"
                                           ${this.isSelected(`${category}|${sub}`) ? 'checked' : ''}>
                                    <span class="jc-checkbox-custom"></span>
                                    <span class="jc-subcategory-name">${sub}</span>
                                </label>
                            `).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
        });

        return html;
    }

    /**
     * Render selected industry tags
     */
    renderSelectedTags() {
        return this.selectedIndustries.map(industry => `
            <span class="jc-selected-tag" data-value="${industry}">
                ${industry.includes('|') ? industry.split('|')[1] : industry}
                <button type="button" class="jc-remove-tag" data-value="${industry}">
                    <i class="fas fa-times"></i>
                </button>
            </span>
        `).join('');
    }

    /**
     * Attach event listeners for industry selection
     */
    attachIndustryListeners() {
        // Category toggle
        document.querySelectorAll('.jc-category-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const categoryId = btn.dataset.category;
                const subcategoryList = document.getElementById(`jc-subcategories-${categoryId}`);
                const icon = btn.querySelector('i');

                if (subcategoryList) {
                    subcategoryList.classList.toggle('jc-expanded');
                    icon.classList.toggle('fa-chevron-down');
                    icon.classList.toggle('fa-chevron-up');
                }
            });
        });

        // Checkbox changes
        document.querySelectorAll('.jc-industry-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', this.handleIndustryChange);
        });

        // Select all
        document.querySelector('.jc-select-all-industries')?.addEventListener('click', () => {
            this.selectAllIndustries();
        });

        // Clear selection
        document.querySelector('.jc-clear-industries')?.addEventListener('click', () => {
            this.clearIndustrySelection();
        });

        // Search
        document.getElementById('jc-industry-search')?.addEventListener('input', (e) => {
            this.filterIndustries(e.target.value);
        });

        // Remove tags
        document.querySelectorAll('.jc-remove-tag').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const value = btn.dataset.value;
                this.removeIndustry(value);
            });
        });
    }

    /**
     * Handle industry checkbox change
     */
    handleIndustryChange(e) {
        const checkbox = e.target;
        const value = checkbox.dataset.value;
        const isCategory = checkbox.classList.contains('jc-category-checkbox');

        if (checkbox.checked) {
            if (!this.selectedIndustries.includes(value)) {
                this.selectedIndustries.push(value);
            }

            // If it's a category, also select all subcategories
            if (isCategory) {
                const category = checkbox.dataset.category;
                const subcategories = this.industryTaxonomy[category] || [];
                subcategories.forEach(sub => {
                    const subValue = `${category}|${sub}`;
                    if (!this.selectedIndustries.includes(subValue)) {
                        this.selectedIndustries.push(subValue);
                    }
                    // Update subcategory checkboxes
                    const subCheckbox = document.querySelector(`input[data-value="${subValue}"]`);
                    if (subCheckbox) subCheckbox.checked = true;
                });
            }
        } else {
            this.selectedIndustries = this.selectedIndustries.filter(i => i !== value);

            // If it's a category, also deselect all subcategories
            if (isCategory) {
                const category = checkbox.dataset.category;
                const subcategories = this.industryTaxonomy[category] || [];
                subcategories.forEach(sub => {
                    const subValue = `${category}|${sub}`;
                    this.selectedIndustries = this.selectedIndustries.filter(i => i !== subValue);
                    const subCheckbox = document.querySelector(`input[data-value="${subValue}"]`);
                    if (subCheckbox) subCheckbox.checked = false;
                });
            }
        }

        this.updateSelectedIndustriesDisplay();
        this.saveIndustriesToState();
    }

    /**
     * Remove a specific industry
     */
    removeIndustry(value) {
        this.selectedIndustries = this.selectedIndustries.filter(i => i !== value);
        
        // Update checkbox
        const checkbox = document.querySelector(`input[data-value="${value}"]`);
        if (checkbox) checkbox.checked = false;

        this.updateSelectedIndustriesDisplay();
        this.saveIndustriesToState();
    }

    /**
     * Check if an industry is selected
     */
    isSelected(value) {
        return this.selectedIndustries.includes(value);
    }

    /**
     * Select all industries
     */
    selectAllIndustries() {
        this.selectedIndustries = [];

        Object.entries(this.industryTaxonomy).forEach(([category, subcategories]) => {
            this.selectedIndustries.push(category);
            if (subcategories && subcategories.length > 0) {
                subcategories.forEach(sub => {
                    this.selectedIndustries.push(`${category}|${sub}`);
                });
            }
        });

        // Update all checkboxes
        document.querySelectorAll('.jc-industry-checkbox').forEach(cb => {
            cb.checked = true;
        });

        this.updateSelectedIndustriesDisplay();
        this.saveIndustriesToState();
    }

    /**
     * Clear all industry selections
     */
    clearIndustrySelection() {
        this.selectedIndustries = [];

        // Update all checkboxes
        document.querySelectorAll('.jc-industry-checkbox').forEach(cb => {
            cb.checked = false;
        });

        this.updateSelectedIndustriesDisplay();
        this.saveIndustriesToState();
    }

    /**
     * Filter industries by search term
     */
    filterIndustries(searchTerm) {
        const term = searchTerm.toLowerCase().trim();
        
        document.querySelectorAll('.jc-industry-category').forEach(category => {
            const categoryName = category.querySelector('.jc-category-name')?.textContent.toLowerCase() || '';
            const subcategories = category.querySelectorAll('.jc-subcategory-item');
            let hasMatch = categoryName.includes(term);

            subcategories.forEach(sub => {
                const subName = sub.querySelector('.jc-subcategory-name')?.textContent.toLowerCase() || '';
                const matches = subName.includes(term);
                sub.style.display = term === '' || matches ? '' : 'none';
                if (matches) hasMatch = true;
            });

            // Show/hide category based on matches
            category.style.display = term === '' || hasMatch ? '' : 'none';

            // Expand category if searching and has matches
            if (term !== '' && hasMatch) {
                const subcategoryList = category.querySelector('.jc-subcategory-list');
                if (subcategoryList) {
                    subcategoryList.classList.add('jc-expanded');
                }
            }
        });
    }

    /**
     * Update the selected industries display
     */
    updateSelectedIndustriesDisplay() {
        const countEl = document.getElementById('jc-selected-industries-count');
        const tagsEl = document.getElementById('jc-selected-tags');

        if (countEl) {
            countEl.textContent = this.selectedIndustries.length;
        }

        if (tagsEl) {
            if (this.selectedIndustries.length === 0) {
                tagsEl.innerHTML = '<span class="jc-empty-selection">No industries selected</span>';
            } else {
                tagsEl.innerHTML = this.renderSelectedTags();
                // Re-attach remove listeners
                tagsEl.querySelectorAll('.jc-remove-tag').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.removeIndustry(btn.dataset.value);
                    });
                });
            }
        }
    }

    /**
     * Save industries to workflow state
     */
    saveIndustriesToState() {
        this.workflow.updateState({
            industries: this.selectedIndustries
        });
    }

    /**
     * Validate Step 4
     */
    async validateStep4() {
        if (this.selectedIndustries.length === 0) {
            this.showNotification('Please select at least one industry.', 'warning');
            return false;
        }

        // Save to database
        const saved = await this.saveIndustriesToDatabase(this.selectedIndustries);
        if (!saved) {
            this.showNotification('Failed to save industries. Please try again.', 'error');
            return false;
        }

        return true;
    }

    // =========================================================================
    // STEP 5: PRIMARY PROBLEM SELECTION
    // =========================================================================

    /**
     * Initialize Step 5: Primary Problem Selection
     */
    async initStep5() {
        // Prevent double initialization
        if (this._step5Initializing) return;
        this._step5Initializing = true;

        // Use template's existing container
        const listContainer = document.getElementById('jc-primary-problem-list');
        const loadingEl = document.getElementById('jc-primary-problem-loading');
        const regenBtn = document.getElementById('jc-regenerate-primary-problems');
        
        if (!listContainer) {
            console.error('Step 5: jc-primary-problem-list container not found');
            this._step5Initializing = false;
            return;
        }

        // Load saved state
        const state = this.workflow.getState();
        this.primaryProblemId = state.primaryProblemId || null;

        // Wire up regenerate button
        if (regenBtn && !regenBtn._bound) {
            regenBtn._bound = true;
            regenBtn.addEventListener('click', () => this.generateAndRenderProblems(listContainer, loadingEl, regenBtn));
        }

        // Auto-generate on first visit if no problems exist
        if (this.problemRecommendations.length === 0) {
            await this.generateAndRenderProblems(listContainer, loadingEl, regenBtn);
        } else {
            this.renderProblemRadioList(listContainer);
        }

        // Attach selection listeners
        this.attachPrimaryProblemListeners();
        
        this._step5Initializing = false;
    }

    /**
     * Generate problem titles via AI and render them
     */
    async generateAndRenderProblems(listContainer, loadingEl, regenBtn) {
        // Show loading
        if (loadingEl) loadingEl.style.display = 'block';
        if (regenBtn) regenBtn.disabled = true;
        listContainer.innerHTML = '';

        const state = this.workflow.getState();

        try {
            // Try AI title manager if available
            if (window.AITitleManager) {
                const aiManager = new window.AITitleManager({
                    apiBase: this.options.apiBase,
                    nonce: this.options.nonce,
                    circleId: state.journeyCircleId,
                    clientId: state.clientId
                });

                const result = await aiManager.generateProblemTitles({
                    serviceAreaId: state.serviceAreaId,
                    serviceAreaName: '',
                    industries: state.industries || [],
                    brainContent: state.brainContent || [],
                    forceRefresh: true
                });

                if (result.success && result.titles.length > 0) {
                    this.problemRecommendations = result.titles.map((title, i) => ({
                        id: `ai_${i}`,
                        title: typeof title === 'string' ? title : title.title || title.text || String(title),
                        category: typeof title === 'object' ? (title.category || '') : ''
                    }));
                } else {
                    throw new Error(result.error || 'No titles generated');
                }
            } else {
                // Fallback: try the API directly
                const response = await fetch(`${this.options.apiBase}/ai/generate-problem-titles`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({
                        service_area_id: state.serviceAreaId,
                        industries: state.industries || [],
                        brain_content: state.brainContent || [],
                        force_refresh: true
                    })
                });

                if (response.ok) {
                    const data = await response.json();
                    const titles = data.titles || data.recommendations || [];
                    this.problemRecommendations = titles.map((title, i) => ({
                        id: `ai_${i}`,
                        title: typeof title === 'string' ? title : title.title || title.text || String(title),
                        category: typeof title === 'object' ? (title.category || '') : ''
                    }));
                } else {
                    throw new Error(`API returned ${response.status}`);
                }
            }
        } catch (error) {
            console.error('Problem title generation error:', error);
            // Show manual entry fallback
            listContainer.innerHTML = `
                <div class="jc-ai-error" style="padding: 15px; margin-bottom: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                    <p><i class="fas fa-exclamation-triangle"></i> <strong>AI generation unavailable:</strong> ${this.escapeHtml(error.message)}</p>
                    <p>You can manually enter problem titles below.</p>
                </div>
                <div class="jc-manual-problem-entry" style="display: flex; gap: 10px; margin-top: 10px;">
                    <input type="text" id="jc-manual-problem-input" class="jc-input" 
                           placeholder="Enter a problem statement..." style="flex: 1;">
                    <button type="button" class="button button-primary" id="jc-add-manual-problem">
                        <i class="fas fa-plus"></i> Add Problem
                    </button>
                </div>
            `;
            this.attachManualProblemListener(listContainer);
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
            if (regenBtn) regenBtn.disabled = false;
        }

        // Render the radio list
        if (this.problemRecommendations.length > 0) {
            this.renderProblemRadioList(listContainer);
        }
    }

    /**
     * Render problem recommendations as radio buttons
     */
    renderProblemRadioList(container) {
        container.innerHTML = this.problemRecommendations.map((problem, index) => `
            <div class="jc-problem-card ${this.primaryProblemId == problem.id ? 'jc-selected' : ''}" 
                 data-problem-id="${problem.id}" style="padding: 12px; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">
                <label class="jc-radio-label" style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="radio" 
                           name="primaryProblem" 
                           value="${problem.id}"
                           class="jc-primary-problem-radio"
                           ${this.primaryProblemId == problem.id ? 'checked' : ''}>
                    <div class="jc-problem-content" style="display: flex; align-items: center; gap: 10px;">
                        <span class="jc-problem-number" style="background: #4a90d9; color: white; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-weight: bold;">${index + 1}</span>
                        <span class="jc-problem-title">${this.escapeHtml(problem.title)}</span>
                    </div>
                </label>
            </div>
        `).join('');

        // Re-attach listeners
        this.attachPrimaryProblemListeners();
    }

    /**
     * Attach manual problem entry listener
     */
    attachManualProblemListener(container) {
        const addBtn = container.querySelector('#jc-add-manual-problem');
        const input = container.querySelector('#jc-manual-problem-input');
        if (!addBtn || !input) return;

        addBtn.addEventListener('click', () => {
            const title = input.value.trim();
            if (!title) return;

            this.problemRecommendations.push({
                id: `manual_${Date.now()}`,
                title: title,
                category: ''
            });

            input.value = '';
            this.renderProblemRadioList(container);
            // Re-add the manual form after the list
            container.insertAdjacentHTML('beforeend', `
                <div class="jc-manual-problem-entry" style="display: flex; gap: 10px; margin-top: 10px;">
                    <input type="text" id="jc-manual-problem-input" class="jc-input" 
                           placeholder="Enter another problem statement..." style="flex: 1;">
                    <button type="button" class="button button-primary" id="jc-add-manual-problem">
                        <i class="fas fa-plus"></i> Add Problem
                    </button>
                </div>
            `);
            this.attachManualProblemListener(container);
        });
    }

    /**
     * Render the primary problem selector
     */
    renderPrimaryProblemSelector(container) {
        const hasProblems = this.problemRecommendations.length > 0;
        
        const html = `
            <div class="jc-primary-problem-selector">
                <div class="jc-step-header">
                    <h3>Designate Primary Problem</h3>
                    <p class="jc-step-description">
                        ${hasProblems 
                            ? 'Select the main problem that will be the focus of your journey circle. This becomes the central theme around which all content will be created.'
                            : 'Add problem statements below. These will be saved to the database and you can select one as your primary problem.'
                        }
                    </p>
                </div>

                ${hasProblems ? `
                    <div class="jc-problem-recommendations">
                        <h4>
                            <i class="fas fa-database"></i>
                            Problems in Database
                            <span class="jc-count-badge">${this.problemRecommendations.length}</span>
                        </h4>
                        <p class="jc-help-text">
                            Select one problem as your primary focus. In the next step, you'll select 
                            additional problems to complete your journey circle.
                        </p>

                        <div class="jc-problem-list" id="jc-primary-problem-list">
                            ${this.problemRecommendations.map((problem, index) => `
                                <div class="jc-problem-card ${this.primaryProblemId == problem.id ? 'jc-selected' : ''}" 
                                     data-problem-id="${problem.id}">
                                    <label class="jc-radio-label">
                                        <input type="radio" 
                                               name="primaryProblem" 
                                               value="${problem.id}"
                                               class="jc-primary-problem-radio"
                                               ${this.primaryProblemId == problem.id ? 'checked' : ''}>
                                        <span class="jc-radio-custom"></span>
                                        <div class="jc-problem-content">
                                            <span class="jc-problem-number">${index + 1}</span>
                                            <div class="jc-problem-details">
                                                <span class="jc-problem-title">${this.escapeHtml(problem.title)}</span>
                                                ${problem.category ? `
                                                    <span class="jc-problem-category">
                                                        <i class="fas fa-tag"></i> ${this.escapeHtml(problem.category)}
                                                    </span>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                ` : `
                    <div class="jc-empty-problems-notice">
                        <i class="fas fa-plus-circle fa-3x"></i>
                        <h4>No Problems Added Yet</h4>
                        <p>Add your first problem statement below to get started.</p>
                    </div>
                    <div class="jc-problem-list" id="jc-primary-problem-list">
                        <!-- Problems will appear here as they are added -->
                    </div>
                `}

                <div class="jc-add-custom-problem">
                    <h4><i class="fas fa-plus-circle"></i> Add New Problem</h4>
                    <div class="jc-custom-problem-form">
                        <input type="text" 
                               id="jc-custom-problem-title" 
                               placeholder="Enter a problem statement..."
                               class="jc-input">
                        <input type="text" 
                               id="jc-custom-problem-category" 
                               placeholder="Category (optional)"
                               class="jc-input">
                        <button type="button" class="jc-btn jc-btn-primary jc-add-custom-btn">
                            <i class="fas fa-plus"></i> Add to Database
                        </button>
                    </div>
                </div>

                <div class="jc-selected-primary-display" id="jc-selected-primary-display">
                    ${this.renderSelectedPrimaryProblem()}
                </div>
            </div>
        `;

        container.innerHTML = html;
    }

    /**
     * Render the selected primary problem display
     */
    renderSelectedPrimaryProblem() {
        if (!this.primaryProblemId) {
            return `
                <div class="jc-no-selection">
                    <i class="fas fa-hand-pointer"></i>
                    <span>Select a primary problem above</span>
                </div>
            `;
        }

        const problem = this.problemRecommendations.find(p => p.id == this.primaryProblemId);
        if (!problem) return '';

        return `
            <div class="jc-selected-primary-card">
                <div class="jc-primary-badge">
                    <i class="fas fa-star"></i> Primary Problem
                </div>
                <h4>${this.escapeHtml(problem.title)}</h4>
                ${problem.category ? `
                    <span class="jc-problem-category">
                        <i class="fas fa-tag"></i> ${this.escapeHtml(problem.category)}
                    </span>
                ` : ''}
            </div>
        `;
    }

    /**
     * Attach event listeners for primary problem selection
     */
    attachPrimaryProblemListeners() {
        document.querySelectorAll('.jc-primary-problem-radio').forEach(radio => {
            radio.addEventListener('change', this.handlePrimaryProblemSelect);
        });

        // Card click to select
        document.querySelectorAll('.jc-problem-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.type !== 'radio') {
                    const radio = card.querySelector('.jc-primary-problem-radio');
                    if (radio) {
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change'));
                    }
                }
            });
        });

        // Add new problem to database
        document.querySelector('.jc-add-custom-btn')?.addEventListener('click', async () => {
            const titleInput = document.getElementById('jc-custom-problem-title');
            const categoryInput = document.getElementById('jc-custom-problem-category');
            
            const title = titleInput?.value.trim();
            const category = categoryInput?.value.trim();

            if (!title) {
                this.showNotification('Please enter a problem statement.', 'warning');
                return;
            }

            await this.addProblemToDatabase(title, category);
            
            if (titleInput) titleInput.value = '';
            if (categoryInput) categoryInput.value = '';
        });
    }

    /**
     * Add a new problem to the database
     */
    async addProblemToDatabase(title, category = '') {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) {
            this.showNotification('No journey circle available.', 'error');
            return;
        }

        const btn = document.querySelector('.jc-add-custom-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/problems`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({
                        title,
                        description: category, // Using description field for category
                        is_primary: false,
                        position: this.problemRecommendations.length,
                        status: 'draft'
                    })
                }
            );

            if (!response.ok) {
                throw new Error('Failed to save problem to database');
            }

            const result = await response.json();
            
            // Add to local list
            const newProblem = {
                id: result.id,
                title,
                category,
                position: this.problemRecommendations.length
            };
            
            this.problemRecommendations.push(newProblem);
            
            // Re-render
            const container = document.getElementById('step-5-content');
            if (container) {
                this.renderPrimaryProblemSelector(container);
                this.attachPrimaryProblemListeners();
            }

            this.showNotification('Problem saved to database!', 'success');

        } catch (error) {
            console.error('Error saving problem:', error);
            this.showNotification('Failed to save problem. Please try again.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus"></i> Add to Database';
            }
        }
    }

    /**
     * Handle primary problem selection
     */
    handlePrimaryProblemSelect(e) {
        const problemId = e.target.value;
        this.primaryProblemId = problemId;

        // Update card styles
        document.querySelectorAll('.jc-problem-card').forEach(card => {
            card.classList.toggle('jc-selected', card.dataset.problemId == problemId);
        });

        // Update display
        const displayEl = document.getElementById('jc-selected-primary-display');
        if (displayEl) {
            displayEl.innerHTML = this.renderSelectedPrimaryProblem();
        }

        // Save to state
        this.savePrimaryProblemToState();
    }

    /**
     * Save primary problem to workflow state
     */
    savePrimaryProblemToState() {
        const problem = this.problemRecommendations.find(p => p.id == this.primaryProblemId);
        this.workflow.updateState({
            primaryProblemId: this.primaryProblemId,
            primaryProblem: problem || null
        });
    }

    /**
     * Validate Step 5
     */
    async validateStep5() {
        if (!this.primaryProblemId) {
            this.showNotification('Please select a primary problem.', 'warning');
            return false;
        }

        // Update primary problem in database
        await this.updatePrimaryProblem(this.primaryProblemId);

        return true;
    }

    // =========================================================================
    // STEP 6: PROBLEM TITLE SELECTION (SELECT 5)
    // =========================================================================

    /**
     * Initialize Step 6: Problem Title Selection
     */
    async initStep6() {
        const container = document.getElementById('step-6-content');
        if (!container) return;

        // Show loading state
        container.innerHTML = this.renderLoadingState('Loading problems...');

        // Load saved state
        const state = this.workflow.getState();
        this.primaryProblemId = state.primaryProblemId;
        this.selectedProblems = state.selectedProblems || [];

        // If we don't have recommendations, fetch them
        if (this.problemRecommendations.length === 0) {
            await this.fetchProblemRecommendations();
        }

        // Also fetch any saved problems from database
        const savedProblems = await this.fetchSavedProblems();
        if (savedProblems.length > 0) {
            this.selectedProblems = savedProblems.map((p, index) => ({
                id: p.id,
                databaseId: p.id,
                title: p.title,
                category: p.category || '',
                position: p.position || index,
                isPrimary: p.is_primary || false
            }));
            this.saveProblemsToState();
        }

        // Ensure primary problem is in selected problems
        if (this.primaryProblemId && !this.selectedProblems.find(p => p.id == this.primaryProblemId)) {
            const primaryProblem = this.problemRecommendations.find(p => p.id == this.primaryProblemId);
            if (primaryProblem) {
                this.selectedProblems.unshift({
                    id: primaryProblem.id,
                    title: primaryProblem.title,
                    category: primaryProblem.category || '',
                    position: 0,
                    isPrimary: true
                });
                // Re-assign positions
                this.selectedProblems.forEach((p, i) => p.position = i);
                this.saveProblemsToState();
            }
        }

        // Render the problem title selector
        this.renderProblemTitleSelector(container);

        // Attach event listeners
        this.attachProblemTitleListeners();

        // Update canvas visualization
        this.updateCircleVisualization();
    }

    /**
     * Render the problem title selector
     */
    renderProblemTitleSelector(container) {
        const html = `
            <div class="jc-problem-title-selector">
                <div class="jc-step-header">
                    <h3>Select Problem Titles</h3>
                    <p class="jc-step-description">
                        Choose exactly <strong>5 problems</strong> from the list below. 
                        These will form the outer ring of your journey circle.
                    </p>
                </div>

                <div class="jc-selection-status" id="jc-selection-status">
                    ${this.renderSelectionStatus()}
                </div>

                <div class="jc-problem-title-grid" id="jc-problem-title-grid">
                    ${this.renderProblemTitleCards()}
                </div>

                <div class="jc-add-custom-problem jc-add-custom-step6">
                    <h4><i class="fas fa-plus-circle"></i> Add New Problem to Database</h4>
                    <div class="jc-custom-problem-form">
                        <input type="text" 
                               id="jc-custom-problem-title-step6" 
                               placeholder="Enter a problem statement..."
                               class="jc-input">
                        <input type="text" 
                               id="jc-custom-problem-category-step6" 
                               placeholder="Category (optional)"
                               class="jc-input">
                        <button type="button" class="jc-btn jc-btn-primary jc-add-custom-btn-step6">
                            <i class="fas fa-plus"></i> Add to Database
                        </button>
                    </div>
                </div>

                <div class="jc-selected-problems-summary" id="jc-selected-problems-summary">
                    ${this.renderSelectedProblemsSummary()}
                </div>
            </div>
        `;

        container.innerHTML = html;
    }

    /**
     * Render selection status indicator
     */
    renderSelectionStatus() {
        const count = this.selectedProblems.length;
        const remaining = 5 - count;
        let statusClass = 'jc-status-incomplete';
        let statusText = '';

        if (count === 5) {
            statusClass = 'jc-status-complete';
            statusText = '<i class="fas fa-check-circle"></i> Perfect! You\'ve selected 5 problems.';
        } else if (count > 5) {
            statusClass = 'jc-status-error';
            statusText = `<i class="fas fa-exclamation-circle"></i> Too many! Please deselect ${count - 5} problem(s).`;
        } else {
            statusText = `<i class="fas fa-info-circle"></i> Select ${remaining} more problem${remaining !== 1 ? 's' : ''} (${count}/5 selected)`;
        }

        return `
            <div class="jc-status-indicator ${statusClass}">
                ${statusText}
            </div>
            <div class="jc-selection-progress">
                <div class="jc-progress-bar">
                    <div class="jc-progress-fill" style="width: ${Math.min(count / 5 * 100, 100)}%"></div>
                </div>
                <span class="jc-progress-text">${count}/5</span>
            </div>
        `;
    }

    /**
     * Render problem title cards
     */
    renderProblemTitleCards() {
        const allProblems = this.problemRecommendations;

        if (allProblems.length === 0) {
            return `
                <div class="jc-empty-recommendations">
                    <i class="fas fa-database"></i>
                    <p>No problems in database yet. Add problems using the form below.</p>
                </div>
            `;
        }

        return allProblems.map((problem, index) => {
            const isSelected = this.selectedProblems.find(p => p.id == problem.id);
            const isPrimary = this.primaryProblemId == problem.id;
            const position = isSelected ? this.selectedProblems.findIndex(p => p.id == problem.id) : -1;

            return `
                <div class="jc-problem-title-card ${isSelected ? 'jc-selected' : ''} ${isPrimary ? 'jc-primary' : ''}" 
                     data-problem-id="${problem.id}">
                    <label class="jc-checkbox-label">
                        <input type="checkbox" 
                               class="jc-problem-title-checkbox"
                               value="${problem.id}"
                               data-title="${this.escapeHtml(problem.title)}"
                               data-category="${problem.category || ''}"
                               ${isSelected ? 'checked' : ''}
                               ${isPrimary ? 'disabled' : ''}>
                        <span class="jc-checkbox-custom"></span>
                    </label>
                    <div class="jc-card-content">
                        ${isPrimary ? '<span class="jc-primary-indicator"><i class="fas fa-star"></i> Primary</span>' : ''}
                        ${isSelected && position >= 0 ? `<span class="jc-position-badge">#${position + 1}</span>` : ''}
                        <p class="jc-problem-title-text">${this.escapeHtml(problem.title)}</p>
                        ${problem.category ? `
                            <span class="jc-category-tag">
                                <i class="fas fa-tag"></i> ${this.escapeHtml(problem.category)}
                            </span>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Render selected problems summary
     */
    renderSelectedProblemsSummary() {
        if (this.selectedProblems.length === 0) {
            return `
                <div class="jc-empty-summary">
                    <i class="fas fa-list-ol"></i>
                    <span>Selected problems will appear here</span>
                </div>
            `;
        }

        // Sort by position
        const sortedProblems = [...this.selectedProblems].sort((a, b) => a.position - b.position);

        return `
            <h4><i class="fas fa-list-ol"></i> Selected Problems (Drag to reorder)</h4>
            <div class="jc-selected-problems-list" id="jc-selected-problems-list">
                ${sortedProblems.map((problem, index) => `
                    <div class="jc-selected-problem-item" 
                         data-problem-id="${problem.id}"
                         draggable="true">
                        <span class="jc-drag-handle">
                            <i class="fas fa-grip-vertical"></i>
                        </span>
                        <span class="jc-position-number">${index + 1}</span>
                        <span class="jc-problem-text">${this.escapeHtml(problem.title)}</span>
                        ${problem.isPrimary ? '<span class="jc-primary-tag"><i class="fas fa-star"></i></span>' : ''}
                        ${!problem.isPrimary ? `
                            <button type="button" class="jc-remove-problem" data-problem-id="${problem.id}">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Attach event listeners for problem title selection
     */
    attachProblemTitleListeners() {
        // Checkbox changes
        document.querySelectorAll('.jc-problem-title-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', this.handleProblemTitleToggle);
        });

        // Card click to toggle
        document.querySelectorAll('.jc-problem-title-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.type !== 'checkbox' && !e.target.closest('.jc-checkbox-label')) {
                    const checkbox = card.querySelector('.jc-problem-title-checkbox');
                    if (checkbox && !checkbox.disabled) {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change'));
                    }
                }
            });
        });

        // Add new problem to database (step 6)
        document.querySelector('.jc-add-custom-btn-step6')?.addEventListener('click', async () => {
            const titleInput = document.getElementById('jc-custom-problem-title-step6');
            const categoryInput = document.getElementById('jc-custom-problem-category-step6');
            
            const title = titleInput?.value.trim();
            const category = categoryInput?.value.trim();

            if (!title) {
                this.showNotification('Please enter a problem statement.', 'warning');
                return;
            }

            await this.addProblemToDatabaseStep6(title, category);
            
            if (titleInput) titleInput.value = '';
            if (categoryInput) categoryInput.value = '';
        });

        // Remove problem buttons
        document.querySelectorAll('.jc-remove-problem').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const problemId = btn.dataset.problemId;
                this.removeProblem(problemId);
            });
        });

        // Drag and drop for reordering
        this.initDragAndDrop();
    }

    /**
     * Add new problem to database in Step 6
     */
    async addProblemToDatabaseStep6(title, category = '') {
        const journeyCircleId = this.workflow.getState().journeyCircleId;
        if (!journeyCircleId) {
            this.showNotification('No journey circle available.', 'error');
            return;
        }

        const btn = document.querySelector('.jc-add-custom-btn-step6');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        try {
            const response = await fetch(
                `${this.options.apiBase}/journey-circles/${journeyCircleId}/problems`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.options.nonce
                    },
                    body: JSON.stringify({
                        title,
                        description: category,
                        is_primary: false,
                        position: this.problemRecommendations.length,
                        status: 'draft'
                    })
                }
            );

            if (!response.ok) {
                throw new Error('Failed to save problem');
            }

            const result = await response.json();
            
            // Add to local list
            const newProblem = {
                id: result.id,
                title,
                category,
                position: this.problemRecommendations.length
            };
            
            this.problemRecommendations.push(newProblem);
            
            // Refresh the grid
            const gridEl = document.getElementById('jc-problem-title-grid');
            if (gridEl) {
                gridEl.innerHTML = this.renderProblemTitleCards();
                this.attachProblemTitleListeners();
            }

            this.showNotification('Problem saved to database!', 'success');

        } catch (error) {
            console.error('Error saving problem:', error);
            this.showNotification('Failed to save problem. Please try again.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus"></i> Add to Database';
            }
        }
    }

    /**
     * Handle problem title checkbox toggle
     */
    handleProblemTitleToggle(e) {
        const checkbox = e.target;
        const problemId = checkbox.value;
        const title = checkbox.dataset.title;
        const category = checkbox.dataset.category;

        if (checkbox.checked) {
            // Check if already at 5
            if (this.selectedProblems.length >= 5) {
                checkbox.checked = false;
                this.showNotification('You can only select 5 problems. Please deselect one first.', 'warning');
                return;
            }

            // Add to selected
            const isPrimary = problemId == this.primaryProblemId;
            this.selectedProblems.push({
                id: problemId,
                title,
                category,
                position: this.selectedProblems.length,
                isPrimary
            });

            // Update card style
            const card = checkbox.closest('.jc-problem-title-card');
            if (card) {
                card.classList.add('jc-selected');
            }
        } else {
            // Remove from selected
            this.selectedProblems = this.selectedProblems.filter(p => p.id != problemId);
            
            // Re-assign positions
            this.selectedProblems.forEach((p, i) => p.position = i);

            // Update card style
            const card = checkbox.closest('.jc-problem-title-card');
            if (card) {
                card.classList.remove('jc-selected');
            }
        }

        this.updateStep6Display();
        this.saveProblemsToState();
        this.updateCircleVisualization();
    }

    /**
     * Remove a problem from selection
     */
    removeProblem(problemId) {
        const problem = this.selectedProblems.find(p => p.id == problemId);
        if (problem?.isPrimary) {
            this.showNotification('Cannot remove the primary problem.', 'warning');
            return;
        }

        this.selectedProblems = this.selectedProblems.filter(p => p.id != problemId);
        
        // Re-assign positions
        this.selectedProblems.forEach((p, i) => p.position = i);

        // Update checkbox
        const checkbox = document.querySelector(`.jc-problem-title-checkbox[value="${problemId}"]`);
        if (checkbox) {
            checkbox.checked = false;
            const card = checkbox.closest('.jc-problem-title-card');
            if (card) card.classList.remove('jc-selected');
        }

        this.updateStep6Display();
        this.saveProblemsToState();
        this.updateCircleVisualization();
    }

    /**
     * Initialize drag and drop for reordering
     */
    initDragAndDrop() {
        const list = document.getElementById('jc-selected-problems-list');
        if (!list) return;

        let draggedItem = null;

        list.querySelectorAll('.jc-selected-problem-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                draggedItem = item;
                item.classList.add('jc-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('jc-dragging');
                draggedItem = null;
            });

            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });

            item.addEventListener('drop', (e) => {
                e.preventDefault();
                if (draggedItem && draggedItem !== item) {
                    const allItems = [...list.querySelectorAll('.jc-selected-problem-item')];
                    const draggedIndex = allItems.indexOf(draggedItem);
                    const targetIndex = allItems.indexOf(item);

                    if (draggedIndex < targetIndex) {
                        item.parentNode.insertBefore(draggedItem, item.nextSibling);
                    } else {
                        item.parentNode.insertBefore(draggedItem, item);
                    }

                    // Update positions
                    this.updatePositionsFromDOM();
                }
            });
        });
    }

    /**
     * Update positions from DOM order
     */
    updatePositionsFromDOM() {
        const list = document.getElementById('jc-selected-problems-list');
        if (!list) return;

        const items = list.querySelectorAll('.jc-selected-problem-item');
        items.forEach((item, index) => {
            const problemId = item.dataset.problemId;
            const problem = this.selectedProblems.find(p => p.id == problemId);
            if (problem) {
                problem.position = index;
            }
            // Update position number display
            const posNum = item.querySelector('.jc-position-number');
            if (posNum) posNum.textContent = index + 1;
        });

        this.saveProblemsToState();
        this.updateCircleVisualization();
        
        // Update position badges in grid
        this.updatePositionBadges();
    }

    /**
     * Update position badges in the grid
     */
    updatePositionBadges() {
        document.querySelectorAll('.jc-problem-title-card').forEach(card => {
            const problemId = card.dataset.problemId;
            const problem = this.selectedProblems.find(p => p.id == problemId);
            const badge = card.querySelector('.jc-position-badge');
            
            if (problem && badge) {
                badge.textContent = `#${problem.position + 1}`;
            }
        });
    }

    /**
     * Update Step 6 display elements
     */
    updateStep6Display() {
        // Update status
        const statusEl = document.getElementById('jc-selection-status');
        if (statusEl) {
            statusEl.innerHTML = this.renderSelectionStatus();
        }

        // Update summary
        const summaryEl = document.getElementById('jc-selected-problems-summary');
        if (summaryEl) {
            summaryEl.innerHTML = this.renderSelectedProblemsSummary();
            
            // Re-attach listeners
            summaryEl.querySelectorAll('.jc-remove-problem').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.removeProblem(btn.dataset.problemId);
                });
            });
            
            this.initDragAndDrop();
        }

        // Update position badges
        this.updatePositionBadges();
    }

    /**
     * Save problems to workflow state
     */
    saveProblemsToState() {
        this.workflow.updateState({
            selectedProblems: this.selectedProblems
        });
    }

    /**
     * Update circle visualization
     */
    updateCircleVisualization() {
        if (this.workflow.renderer && typeof this.workflow.renderer.updateProblems === 'function') {
            this.workflow.renderer.updateProblems(this.selectedProblems, this.primaryProblemId);
        }
    }

    /**
     * Validate Step 6
     */
    async validateStep6() {
        if (this.selectedProblems.length !== 5) {
            this.showNotification(`Please select exactly 5 problems. You have ${this.selectedProblems.length} selected.`, 'warning');
            return false;
        }

        // Save to database
        try {
            await this.saveProblemsToDatabase(this.selectedProblems);
            return true;
        } catch (error) {
            return false;
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Show notification
     */
    showNotification(message, type = 'info') {
        // Delegate to the shared JCNotifications utility which uses
        // Campaign Builder's notification framework (base.css classes).
        if (window.JCNotifications) {
            window.JCNotifications.show(type, message);
        } else if (this.workflow && this.workflow.showNotification) {
            this.workflow.showNotification(message, type);
        } else {
            const method = type === 'error' ? 'error' : type === 'warning' ? 'warn' : 'log';
            console[method](`[JourneyCircle] ${message}`);
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Convert string to slug
     */
    slugify(text) {
        return text
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    }

    /**
     * Load saved data from state
     */
    loadFromState(state) {
        this.selectedIndustries = state.industries || [];
        this.primaryProblemId = state.primaryProblemId || null;
        this.selectedProblems = state.selectedProblems || [];
    }
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProblemSolutionManager;
}

// Auto-initialize when workflow is ready
(function($) {
    'use strict';

    $(document).ready(function() {
        if (window.drJourneyCircle) {
            const workflow = window.drJourneyCircle;
            const psManager = new ProblemSolutionManager(workflow, {
                apiBase: workflow.config.restUrl,
                nonce: workflow.config.restNonce
            });

            // Store reference globally
            window.drProblemSolutionManager = psManager;

            // Listen for step changes to initialize step UI
            // Note: Steps 5, 6, 7 are handled by steps567-manager.js
            $(document).on('jc:stepChanged', function(e, step) {
                if (step === 4) {
                    psManager.initStep4();
                }
            });

            // If already on step 4, init immediately
            const currentStep = workflow.getState ? workflow.getState().currentStep : (workflow.state ? workflow.state.currentStep : null);
            if (currentStep === 4) {
                psManager.initStep4();
            }

            console.log('ProblemSolutionManager initialized');
        }
    });
})(jQuery);