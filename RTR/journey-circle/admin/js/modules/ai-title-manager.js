/**
 * AI Title Manager
 *
 * Manages AI-powered title generation for Steps 5, 6, and 7 of the
 * Journey Circle workflow. Replaces mock/hardcoded data with real
 * Gemini API-generated recommendations.
 *
 * Part of Iteration 8: AI Title Recommendations
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 *
 * Dependencies:
 * - jQuery (WordPress bundled)
 * - wp-api-fetch (WordPress REST API helper)
 * - journey-circle-workflow.js (state machine)
 * - problem-solution-manager.js (Steps 5-6 UI — to be augmented)
 * - solution-offer-manager.js (Step 7 UI — to be augmented)
 */

(function(window, document, $) {
    'use strict';

    /**
     * AITitleManager Class
     *
     * Handles all AI title generation interactions including API calls,
     * loading states, error handling, caching indicators, and regeneration.
     */
    class AITitleManager {

        /**
         * Constructor.
         *
         * @param {Object} options Configuration options.
         * @param {string} options.apiBase       REST API base URL.
         * @param {string} options.nonce         WordPress REST nonce.
         * @param {number} options.circleId      Journey circle ID.
         * @param {number} options.clientId      Client ID.
         * @param {Function} options.onError     Error callback.
         * @param {Function} options.onUpdate    Update callback.
         */
        constructor(options = {}) {
            this.config = {
                apiBase: options.apiBase || '/wp-json/directreach/v2',
                nonce: options.nonce || window.drJourneyCircle?.nonce || '',
                circleId: options.circleId || null,
                clientId: options.clientId || null,
                onError: options.onError || null,
                onUpdate: options.onUpdate || null,
                ...options
            };

            // State tracking.
            this.state = {
                aiConfigured: null,       // null = unknown, true/false after check
                problemTitles: [],        // Last generated problem titles
                solutionTitles: {},       // Map: problemId -> [solution titles]
                loading: {
                    problemTitles: false,
                    solutionTitles: {},   // Map: problemId -> bool
                },
                errors: {
                    problemTitles: null,
                    solutionTitles: {},
                },
                manualMode: {
                    problemTitles: false,
                    solutionTitles: {},
                }
            };

            // Abort controllers for cancelling in-flight requests.
            this._abortControllers = {};
        }

        // =====================================================================
        // INITIALIZATION
        // =====================================================================

        /**
         * Initialize the AI Title Manager.
         *
         * Checks AI configuration status and sets up event listeners.
         *
         * @returns {Promise<boolean>} True if AI is configured and ready.
         */
        async init() {
            try {
                const status = await this.checkAIStatus();
                this.state.aiConfigured = status.configured;

                if (!status.configured) {
                    console.warn('[AITitleManager] AI not configured:', status.message);
                }

                return status.configured;
            } catch (error) {
                console.error('[AITitleManager] Init failed:', error);
                this.state.aiConfigured = false;
                return false;
            }
        }

        /**
         * Check if the AI service is configured and available.
         *
         * @returns {Promise<Object>} Status object with {configured, model, message}.
         */
        async checkAIStatus() {
            try {
                const response = await this._apiRequest('GET', '/ai/check-status');
                return response;
            } catch (error) {
                return {
                    configured: false,
                    model: null,
                    message: 'Could not reach AI service.'
                };
            }
        }

        // =====================================================================
        // PROBLEM TITLE GENERATION (Steps 5-6)
        // =====================================================================

        /**
         * Generate problem title recommendations via AI.
         *
         * @param {Object} params Generation parameters.
         * @param {number}  params.serviceAreaId   Service area ID.
         * @param {string}  params.serviceAreaName Service area name.
         * @param {Array}   params.industries      Selected industry names/IDs.
         * @param {Array}   params.brainContent    Brain content items.
         * @param {boolean} params.forceRefresh    Skip cache.
         * @returns {Promise<Object>} Result with {success, titles, error}.
         */
        async generateProblemTitles(params = {}) {
            // Prevent duplicate requests.
            if (this.state.loading.problemTitles) {
                return { success: false, titles: [], error: 'Generation already in progress.' };
            }

            this.state.loading.problemTitles = true;
            this.state.errors.problemTitles = null;
            this._notifyLoadingChange('problemTitles', true);

            try {
                // Cancel any previous request.
                this._cancelRequest('problemTitles');

                const response = await this._apiRequest('POST', '/ai/generate-problem-titles', {
                    service_area_id: params.serviceAreaId || 0,
                    service_area_name: params.serviceAreaName || '',
                    industries: params.industries || [],
                    brain_content: params.brainContent || [],
                    existing_assets: params.existingAssets || [],
                    previous_titles: this.state.problemTitles.map(t => typeof t === 'object' ? t.title : t),
                    force_refresh: params.forceRefresh || false
                }, 'problemTitles');

                if (response.success && response.titles && response.titles.length > 0) {
                    this.state.problemTitles = response.titles;
                    this.state.manualMode.problemTitles = false;
                    return {
                        success: true,
                        titles: response.titles,
                        count: response.count,
                        error: null
                    };
                }

                // API returned but with no titles.
                const errorMsg = response.error || 'No titles were generated. Please try again.';
                this.state.errors.problemTitles = errorMsg;
                return { success: false, titles: [], error: errorMsg };

            } catch (error) {
                // Handle abort.
                if (error.name === 'AbortError') {
                    return { success: false, titles: [], error: 'Request cancelled.' };
                }

                const errorMsg = this._extractErrorMessage(error);
                this.state.errors.problemTitles = errorMsg;
                return { success: false, titles: [], error: errorMsg };

            } finally {
                this.state.loading.problemTitles = false;
                this._notifyLoadingChange('problemTitles', false);
            }
        }

        /**
         * Regenerate problem titles (forces cache skip).
         *
         * @param {Object} params Same as generateProblemTitles.
         * @returns {Promise<Object>} Result.
         */
        async regenerateProblemTitles(params = {}) {
            return this.generateProblemTitles({ ...params, forceRefresh: true });
        }

        // =====================================================================
        // SOLUTION TITLE GENERATION (Step 7)
        // =====================================================================

        /**
         * Generate solution title recommendations for a specific problem.
         *
         * @param {Object} params Generation parameters.
         * @param {number}  params.problemId       Problem ID.
         * @param {string}  params.problemTitle     Problem title text.
         * @param {string}  params.serviceAreaName  Service area name.
         * @param {Array}   params.brainContent     Brain content items.
         * @param {Array}   params.industries       Industry names.
         * @param {boolean} params.forceRefresh     Skip cache.
         * @returns {Promise<Object>} Result with {success, titles, problemId, error}.
         */
        async generateSolutionTitles(params = {}) {
            const problemId = params.problemId;

            if (!problemId || !params.problemTitle) {
                return {
                    success: false,
                    titles: [],
                    problemId,
                    error: 'Problem ID and title are required.'
                };
            }

            // Prevent duplicate requests for the same problem.
            if (this.state.loading.solutionTitles[problemId]) {
                return {
                    success: false,
                    titles: [],
                    problemId,
                    error: 'Generation already in progress for this problem.'
                };
            }

            this.state.loading.solutionTitles[problemId] = true;
            this.state.errors.solutionTitles[problemId] = null;
            this._notifyLoadingChange('solutionTitles', true, problemId);

            try {
                this._cancelRequest('solution_' + problemId);

                const response = await this._apiRequest('POST', '/ai/generate-solution-titles', {
                    problem_id: problemId,
                    problem_title: params.problemTitle,
                    service_area_name: params.serviceAreaName || '',
                    brain_content: params.brainContent || [],
                    industries: params.industries || [],
                    exclude_titles: this.state.solutionTitles.map(t => typeof t === 'object' ? t.title : t),
                    force_refresh: params.forceRefresh || false
                }, 'solution_' + problemId);

                if (response.success && response.titles && response.titles.length > 0) {
                    this.state.solutionTitles[problemId] = response.titles;
                    this.state.manualMode.solutionTitles[problemId] = false;
                    return {
                        success: true,
                        titles: response.titles,
                        problemId,
                        error: null
                    };
                }

                const errorMsg = response.error || 'No solution titles generated. Please try again.';
                this.state.errors.solutionTitles[problemId] = errorMsg;
                return { success: false, titles: [], problemId, error: errorMsg };

            } catch (error) {
                if (error.name === 'AbortError') {
                    return { success: false, titles: [], problemId, error: 'Request cancelled.' };
                }

                const errorMsg = this._extractErrorMessage(error);
                this.state.errors.solutionTitles[problemId] = errorMsg;
                return { success: false, titles: [], problemId, error: errorMsg };

            } finally {
                this.state.loading.solutionTitles[problemId] = false;
                this._notifyLoadingChange('solutionTitles', false, problemId);
            }
        }

        /**
         * Regenerate solution titles for a problem.
         *
         * @param {Object} params Same as generateSolutionTitles.
         * @returns {Promise<Object>} Result.
         */
        async regenerateSolutionTitles(params = {}) {
            return this.generateSolutionTitles({ ...params, forceRefresh: true });
        }

        /**
         * Generate solution titles for ALL selected problems.
         *
         * @param {Array}  problems      Array of {id, title} objects.
         * @param {Object} commonParams  Shared params (serviceAreaName, brainContent, industries).
         * @returns {Promise<Object>} Aggregated results.
         */
        async generateAllSolutionTitles(problems, commonParams = {}) {
            const results = {};
            const errors = [];

            for (const problem of problems) {
                const result = await this.generateSolutionTitles({
                    problemId: problem.id,
                    problemTitle: problem.title,
                    ...commonParams
                });

                results[problem.id] = result;
                if (!result.success) {
                    errors.push({ problemId: problem.id, error: result.error });
                }
            }

            return {
                results,
                allSuccess: errors.length === 0,
                errors
            };
        }

        // =====================================================================
        // MANUAL MODE (FALLBACK)
        // =====================================================================

        /**
         * Switch to manual title entry mode for problem titles.
         *
         * Called when AI fails and user wants to enter titles manually.
         */
        enableManualProblemTitles() {
            this.state.manualMode.problemTitles = true;
            this.state.errors.problemTitles = null;
        }

        /**
         * Switch to manual title entry mode for a specific problem's solutions.
         *
         * @param {number} problemId Problem ID.
         */
        enableManualSolutionTitles(problemId) {
            this.state.manualMode.solutionTitles[problemId] = true;
            this.state.errors.solutionTitles[problemId] = null;
        }

        /**
         * Check if manual mode is active.
         *
         * @param {string} type      'problemTitles' or 'solutionTitles'.
         * @param {number} problemId Problem ID (for solutionTitles).
         * @returns {boolean}
         */
        isManualMode(type, problemId = null) {
            if (type === 'problemTitles') {
                return this.state.manualMode.problemTitles;
            }
            if (type === 'solutionTitles' && problemId) {
                return this.state.manualMode.solutionTitles[problemId] || false;
            }
            return false;
        }

        // =====================================================================
        // UI RENDERING HELPERS
        // =====================================================================

        /**
         * Render the AI loading state HTML for a container.
         *
         * @param {string} message Loading message.
         * @returns {string} HTML string.
         */
        renderLoadingState(message = 'Generating AI recommendations...') {
            return `
                <div class="dr-ai-loading" role="status" aria-live="polite">
                    <div class="dr-ai-loading__spinner">
                        <div class="dr-ai-spinner"></div>
                    </div>
                    <div class="dr-ai-loading__text">
                        <p class="dr-ai-loading__message">${this._escapeHtml(message)}</p>
                        <p class="dr-ai-loading__hint">This may take up to 10 seconds...</p>
                    </div>
                </div>
            `;
        }

        /**
         * Render the AI error state HTML with retry and manual options.
         *
         * @param {string}   errorMessage Error message to display.
         * @param {Object}   options      Render options.
         * @param {Function} options.onRetry   Retry callback.
         * @param {Function} options.onManual  Manual entry callback.
         * @returns {string} HTML string.
         */
        renderErrorState(errorMessage, options = {}) {
            const retryBtn = options.showRetry !== false
                ? `<button type="button" class="button dr-ai-retry-btn">
                       <span class="dashicons dashicons-update"></span> Try Again
                   </button>`
                : '';

            const manualBtn = options.showManual !== false
                ? `<button type="button" class="button dr-ai-manual-btn">
                       <span class="dashicons dashicons-edit"></span> Enter Titles Manually
                   </button>`
                : '';

            return `
                <div class="dr-ai-error" role="alert">
                    <div class="dr-ai-error__icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="dr-ai-error__content">
                        <p class="dr-ai-error__message">${this._escapeHtml(errorMessage)}</p>
                        <div class="dr-ai-error__actions">
                            ${retryBtn}
                            ${manualBtn}
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Render the regenerate button HTML.
         *
         * @param {string} type 'problems' or 'solutions'.
         * @param {number} problemId Optional problem ID for solutions.
         * @returns {string} HTML string.
         */
        renderRegenerateButton(type = 'problems', problemId = null) {
            const dataAttr = problemId ? `data-problem-id="${problemId}"` : '';
            const label = type === 'problems'
                ? 'Regenerate Problem Titles'
                : 'Regenerate Solutions';

            return `
                <button type="button"
                    class="button dr-ai-regenerate-btn"
                    data-type="${type}"
                    ${dataAttr}
                    title="Generate new AI recommendations">
                    <span class="dashicons dashicons-update"></span>
                    ${label}
                </button>
            `;
        }

        /**
         * Render the manual title entry form.
         *
         * @param {string} type  'problem' or 'solution'.
         * @param {number} count Number of inputs to show.
         * @returns {string} HTML string.
         */
        renderManualEntryForm(type = 'problem', count = 1) {
            let inputs = '';
            for (let i = 0; i < count; i++) {
                inputs += `
                    <div class="dr-ai-manual-input">
                        <label for="manual-${type}-${i}">${type === 'problem' ? 'Problem' : 'Solution'} Title ${i + 1}</label>
                        <input type="text"
                            id="manual-${type}-${i}"
                            class="regular-text dr-ai-manual-title-input"
                            data-type="${type}"
                            data-index="${i}"
                            placeholder="Enter a ${type} title..."
                            maxlength="500"
                        />
                    </div>
                `;
            }

            return `
                <div class="dr-ai-manual-form">
                    <p class="dr-ai-manual-form__intro">
                        Enter your ${type} titles manually:
                    </p>
                    ${inputs}
                    <div class="dr-ai-manual-form__actions">
                        <button type="button" class="button button-primary dr-ai-manual-submit-btn" data-type="${type}">
                            Save Titles
                        </button>
                        <button type="button" class="button dr-ai-back-to-ai-btn" data-type="${type}">
                            Back to AI Generation
                        </button>
                    </div>
                </div>
            `;
        }

        /**
         * Render the "AI not configured" notice.
         *
         * @returns {string} HTML string.
         */
        renderNotConfiguredNotice() {
            return `
                <div class="dr-ai-notice notice notice-warning">
                    <p>
                        <strong>AI is not configured.</strong>
                        Please add your Gemini API key in
                        <a href="${window.drJourneyCircle?.settingsUrl || '#'}">DirectReach Settings &gt; AI</a>
                        to enable AI-powered title generation.
                    </p>
                    <p>You can still enter titles manually.</p>
                </div>
            `;
        }

        // =====================================================================
        // PRIVATE: API COMMUNICATION
        // =====================================================================

        /**
         * Make an API request with proper auth and error handling.
         *
         * @param {string} method    HTTP method (GET, POST).
         * @param {string} endpoint  API endpoint path (e.g., '/ai/generate-problem-titles').
         * @param {Object} data      Request body data.
         * @param {string} requestId Unique ID for abort controller.
         * @returns {Promise<Object>} Parsed response data.
         * @private
         */
        async _apiRequest(method, endpoint, data = null, requestId = null) {
            const url = this.config.apiBase + endpoint;

            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce
                }
            };

            if (data && method !== 'GET') {
                options.body = JSON.stringify(data);
            }

            // Set up abort controller.
            if (requestId) {
                const controller = new AbortController();
                this._abortControllers[requestId] = controller;
                options.signal = controller.signal;
            }

            const response = await fetch(url, options);

            // Clean up abort controller.
            if (requestId) {
                delete this._abortControllers[requestId];
            }

            // Parse response.
            const responseData = await response.json();

            if (!response.ok) {
                const error = new Error(responseData.error || responseData.message || `HTTP ${response.status}`);
                error.status = response.status;
                error.code = responseData.code || 'unknown';
                error.data = responseData;
                throw error;
            }

            return responseData;
        }

        /**
         * Cancel an in-flight request.
         *
         * @param {string} requestId Request identifier.
         * @private
         */
        _cancelRequest(requestId) {
            if (this._abortControllers[requestId]) {
                this._abortControllers[requestId].abort();
                delete this._abortControllers[requestId];
            }
        }

        // =====================================================================
        // PRIVATE: UTILITIES
        // =====================================================================

        /**
         * Notify listeners of loading state changes.
         *
         * @param {string}  type      Title type.
         * @param {boolean} isLoading Loading state.
         * @param {number}  problemId Problem ID (for solution titles).
         * @private
         */
        _notifyLoadingChange(type, isLoading, problemId = null) {
            if (typeof this.config.onUpdate === 'function') {
                this.config.onUpdate({
                    event: 'loadingChange',
                    type,
                    isLoading,
                    problemId
                });
            }

            // Dispatch custom DOM event for decoupled listeners.
            document.dispatchEvent(new CustomEvent('dr-ai-loading-change', {
                detail: { type, isLoading, problemId }
            }));
        }

        /**
         * Extract a user-friendly error message from various error types.
         *
         * @param {Error|Object} error Error object.
         * @returns {string} User-friendly error message.
         * @private
         */
        _extractErrorMessage(error) {
            // Network errors.
            if (error instanceof TypeError && error.message === 'Failed to fetch') {
                return 'Network error. Please check your connection and try again.';
            }

            // API error with message.
            if (error.data && error.data.error) {
                return error.data.error;
            }

            // Standard error message.
            if (error.message) {
                // Map technical errors to user-friendly messages.
                const friendlyMessages = {
                    'api_timeout': 'The AI request timed out. Please try again.',
                    'api_rate_limited': 'Too many requests. Please wait a moment and try again.',
                    'api_not_configured': 'AI is not configured. Please set your Gemini API key in settings.',
                    'api_unauthorized': 'Invalid API key. Please check your Gemini API key in settings.',
                };

                if (error.code && friendlyMessages[error.code]) {
                    return friendlyMessages[error.code];
                }

                return error.message;
            }

            return 'An unexpected error occurred. Please try again.';
        }

        /**
         * Escape HTML characters for safe rendering.
         *
         * @param {string} text Raw text.
         * @returns {string} Escaped HTML.
         * @private
         */
        _escapeHtml(text) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // =====================================================================
        // STATE GETTERS
        // =====================================================================

        /**
         * Check if problem title generation is in progress.
         *
         * @returns {boolean}
         */
        isLoadingProblemTitles() {
            return this.state.loading.problemTitles;
        }

        /**
         * Check if solution title generation is in progress for a problem.
         *
         * @param {number} problemId Problem ID.
         * @returns {boolean}
         */
        isLoadingSolutionTitles(problemId) {
            return this.state.loading.solutionTitles[problemId] || false;
        }

        /**
         * Get the last error for problem titles.
         *
         * @returns {string|null}
         */
        getProblemTitlesError() {
            return this.state.errors.problemTitles;
        }

        /**
         * Get the last error for a problem's solution titles.
         *
         * @param {number} problemId Problem ID.
         * @returns {string|null}
         */
        getSolutionTitlesError(problemId) {
            return this.state.errors.solutionTitles[problemId] || null;
        }

        /**
         * Get cached problem titles from last generation.
         *
         * @returns {Array<string>}
         */
        getCachedProblemTitles() {
            return this.state.problemTitles;
        }

        /**
         * Get cached solution titles for a problem.
         *
         * @param {number} problemId Problem ID.
         * @returns {Array<string>}
         */
        getCachedSolutionTitles(problemId) {
            return this.state.solutionTitles[problemId] || [];
        }

        /**
         * Check if AI service is configured.
         *
         * @returns {boolean|null} null if not yet checked.
         */
        isAIConfigured() {
            return this.state.aiConfigured;
        }
    }

    // =========================================================================
    // INTEGRATION BRIDGE: Step 5-6 (Problem Titles)
    // =========================================================================

    /**
     * ProblemTitleAIBridge
     *
     * Bridges the AITitleManager with the existing ProblemSolutionManager
     * UI for Steps 5 and 6. Call this to wire up AI generation into the
     * existing step containers.
     *
     * @param {AITitleManager}        aiManager     AI title manager instance.
     * @param {ProblemSolutionManager} psManager    Existing problem-solution manager.
     * @param {Object}                 workflowState Current workflow state.
     */
    class ProblemTitleAIBridge {
        constructor(aiManager, psManager, workflowState) {
            this.ai = aiManager;
            this.ps = psManager;
            this.workflow = workflowState;
            this._bound = false;
        }

        /**
         * Bind AI generation to Step 6 container.
         *
         * Replaces the mock data rendering with AI-powered generation.
         *
         * @param {HTMLElement} step6Container The Step 6 DOM container.
         */
        bindToStep6(step6Container) {
            if (!step6Container || this._bound) return;
            this._container = step6Container;
            this._bound = true;

            // Listen for regenerate clicks.
            step6Container.addEventListener('click', (e) => {
                const retryBtn = e.target.closest('.dr-ai-retry-btn');
                const regenBtn = e.target.closest('.dr-ai-regenerate-btn[data-type="problems"]');
                const manualBtn = e.target.closest('.dr-ai-manual-btn');
                const backToAiBtn = e.target.closest('.dr-ai-back-to-ai-btn[data-type="problem"]');

                if (retryBtn || regenBtn) {
                    e.preventDefault();
                    this.loadProblemTitles(!!regenBtn);
                }

                if (manualBtn) {
                    e.preventDefault();
                    this.showManualEntry();
                }

                if (backToAiBtn) {
                    e.preventDefault();
                    this.loadProblemTitles(false);
                }
            });
        }

        /**
         * Load problem titles from AI and render into the step container.
         *
         * @param {boolean} forceRefresh Skip cache.
         */
        async loadProblemTitles(forceRefresh = false) {
            if (!this._container) return;

            const listContainer = this._container.querySelector(
                '.dr-problem-recommendations, .dr-step6-recommendations, .problem-title-list'
            ) || this._container;

            // Show loading state.
            listContainer.innerHTML = this.ai.renderLoadingState('Generating problem title recommendations...');

            // Gather context from workflow state.
            const params = {
                serviceAreaId: this.workflow.serviceAreaId || 0,
                serviceAreaName: this.workflow.serviceAreaName || '',
                industries: this.workflow.industries || [],
                brainContent: this.workflow.brainContent || [],
                existingAssets: this.workflow.existingAssets || [],
                forceRefresh
            };

            const result = await this.ai.generateProblemTitles(params);

            if (result.success) {
                this._renderProblemTitleCheckboxes(listContainer, result.titles);
            } else {
                listContainer.innerHTML = this.ai.renderErrorState(result.error);
            }
        }

        /**
         * Show manual title entry form.
         */
        showManualEntry() {
            if (!this._container) return;
            this.ai.enableManualProblemTitles();

            const listContainer = this._container.querySelector(
                '.dr-problem-recommendations, .dr-step6-recommendations, .problem-title-list'
            ) || this._container;

            listContainer.innerHTML = this.ai.renderManualEntryForm('problem', 10);
        }

        /**
         * Render problem titles as selectable checkboxes.
         *
         * @param {HTMLElement} container Target container.
         * @param {Array}       titles    Title strings.
         * @private
         */
        _renderProblemTitleCheckboxes(container, titles) {
            const selectionCount = 5;
            let html = `
                <div class="dr-ai-problem-titles">
                    <div class="dr-ai-titles-header">
                        <p class="dr-ai-titles-instruction">
                            Select exactly <strong>${selectionCount}</strong> problems for your journey circle:
                        </p>
                        ${this.ai.renderRegenerateButton('problems')}
                    </div>
                    <p class="dr-ai-selection-count">
                        <span class="dr-selected-count">0</span> of ${selectionCount} selected
                    </p>
                    <div class="dr-ai-titles-list">
            `;

            titles.forEach((title, index) => {
                html += `
                    <label class="dr-ai-title-option">
                        <input type="checkbox"
                            class="dr-ai-title-checkbox"
                            name="problem_titles[]"
                            value="${this._escapeAttr(title)}"
                            data-index="${index}"
                        />
                        <span class="dr-ai-title-text">${this.ai._escapeHtml(title)}</span>
                    </label>
                `;
            });

            html += `
                    </div>
                </div>
            `;

            container.innerHTML = html;

            // Bind checkbox logic (select exactly 5).
            this._bindCheckboxValidation(container, selectionCount);
        }

        /**
         * Bind checkbox validation to enforce exact selection count.
         *
         * @param {HTMLElement} container  Container with checkboxes.
         * @param {number}      maxSelect  Maximum selections allowed.
         * @private
         */
        _bindCheckboxValidation(container, maxSelect) {
            const checkboxes = container.querySelectorAll('.dr-ai-title-checkbox');
            const countDisplay = container.querySelector('.dr-selected-count');

            checkboxes.forEach(cb => {
                cb.addEventListener('change', () => {
                    const checked = container.querySelectorAll('.dr-ai-title-checkbox:checked');
                    const count = checked.length;

                    if (countDisplay) {
                        countDisplay.textContent = count;
                        countDisplay.classList.toggle('dr-count-complete', count === maxSelect);
                        countDisplay.classList.toggle('dr-count-over', count > maxSelect);
                    }

                    // Disable unchecked boxes if max reached.
                    if (count >= maxSelect) {
                        checkboxes.forEach(c => {
                            if (!c.checked) c.disabled = true;
                        });
                    } else {
                        checkboxes.forEach(c => c.disabled = false);
                    }

                    // Notify parent of selection change.
                    document.dispatchEvent(new CustomEvent('dr-problem-titles-selection-change', {
                        detail: {
                            count,
                            titles: Array.from(checked).map(c => c.value),
                            valid: count === maxSelect
                        }
                    }));
                });
            });
        }

        /**
         * Get the currently selected problem titles.
         *
         * @returns {Array<string>} Selected title strings.
         */
        getSelectedTitles() {
            if (!this._container) return [];
            const checked = this._container.querySelectorAll('.dr-ai-title-checkbox:checked');
            return Array.from(checked).map(c => c.value);
        }

        /**
         * Escape a string for use in HTML attributes.
         *
         * @param {string} str Raw string.
         * @returns {string} Escaped string.
         * @private
         */
        _escapeAttr(str) {
            return str.replace(/&/g, '&amp;')
                      .replace(/"/g, '&quot;')
                      .replace(/'/g, '&#39;')
                      .replace(/</g, '&lt;')
                      .replace(/>/g, '&gt;');
        }
    }

    // =========================================================================
    // INTEGRATION BRIDGE: Step 7 (Solution Titles)
    // =========================================================================

    /**
     * SolutionTitleAIBridge
     *
     * Bridges the AITitleManager with the existing SolutionOfferManager
     * UI for Step 7.
     *
     * @param {AITitleManager}      aiManager    AI title manager instance.
     * @param {SolutionOfferManager} soManager   Existing solution-offer manager.
     * @param {Object}               workflowState Current workflow state.
     */
    class SolutionTitleAIBridge {
        constructor(aiManager, soManager, workflowState) {
            this.ai = aiManager;
            this.so = soManager;
            this.workflow = workflowState;
            this._bound = false;
        }

        /**
         * Bind AI generation to Step 7 container.
         *
         * @param {HTMLElement} step7Container The Step 7 DOM container.
         */
        bindToStep7(step7Container) {
            if (!step7Container || this._bound) return;
            this._container = step7Container;
            this._bound = true;

            // Listen for regenerate and manual entry clicks.
            step7Container.addEventListener('click', (e) => {
                const retryBtn = e.target.closest('.dr-ai-retry-btn');
                const regenBtn = e.target.closest('.dr-ai-regenerate-btn[data-type="solutions"]');
                const manualBtn = e.target.closest('.dr-ai-manual-btn');

                if (retryBtn || regenBtn) {
                    e.preventDefault();
                    const problemId = (retryBtn || regenBtn).dataset.problemId;
                    if (problemId) {
                        this.loadSolutionTitlesForProblem(parseInt(problemId), !!regenBtn);
                    }
                }

                if (manualBtn) {
                    e.preventDefault();
                    const problemId = manualBtn.dataset.problemId;
                    if (problemId) {
                        this.showManualEntry(parseInt(problemId));
                    }
                }
            });
        }

        /**
         * Load solution titles for all problems.
         *
         * @param {Array} problems Array of {id, title} objects.
         */
        async loadAllSolutionTitles(problems) {
            if (!this._container || !problems.length) return;

            const commonParams = {
                serviceAreaName: this.workflow.serviceAreaName || '',
                brainContent: this.workflow.brainContent || [],
                industries: this.workflow.industries || []
            };

            // Render loading state for each problem.
            let html = '<div class="dr-ai-solution-groups">';
            problems.forEach(problem => {
                html += `
                    <div class="dr-ai-solution-group" data-problem-id="${problem.id}">
                        <h4 class="dr-ai-solution-group__title">
                            Solutions for: <em>${this.ai._escapeHtml(problem.title)}</em>
                        </h4>
                        <div class="dr-ai-solution-group__content">
                            ${this.ai.renderLoadingState('Generating solutions...')}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            this._container.innerHTML = html;

            // Generate titles for each problem sequentially.
            for (const problem of problems) {
                await this.loadSolutionTitlesForProblem(problem.id, false, {
                    problemTitle: problem.title,
                    ...commonParams
                });
            }
        }

        /**
         * Load solution titles for a specific problem.
         *
         * @param {number}  problemId    Problem ID.
         * @param {boolean} forceRefresh Skip cache.
         * @param {Object}  params       Additional params.
         */
        async loadSolutionTitlesForProblem(problemId, forceRefresh = false, params = {}) {
            const groupEl = this._container?.querySelector(
                `.dr-ai-solution-group[data-problem-id="${problemId}"]`
            );
            if (!groupEl) return;

            const contentEl = groupEl.querySelector('.dr-ai-solution-group__content');
            if (!contentEl) return;

            // Show loading.
            contentEl.innerHTML = this.ai.renderLoadingState('Generating solutions...');

            // Get problem title from params or from the header.
            const problemTitle = params.problemTitle
                || groupEl.querySelector('.dr-ai-solution-group__title em')?.textContent
                || '';

            const result = await this.ai.generateSolutionTitles({
                problemId,
                problemTitle,
                serviceAreaName: params.serviceAreaName || this.workflow.serviceAreaName || '',
                brainContent: params.brainContent || this.workflow.brainContent || [],
                industries: params.industries || this.workflow.industries || [],
                forceRefresh
            });

            if (result.success) {
                this._renderSolutionRadios(contentEl, problemId, result.titles);
            } else {
                contentEl.innerHTML = this.ai.renderErrorState(result.error, {
                    showRetry: true,
                    showManual: true
                });
                // Add problem ID to buttons for event delegation.
                contentEl.querySelectorAll('.dr-ai-retry-btn, .dr-ai-manual-btn').forEach(btn => {
                    btn.dataset.problemId = problemId;
                });
            }
        }

        /**
         * Show manual entry for a problem's solutions.
         *
         * @param {number} problemId Problem ID.
         */
        showManualEntry(problemId) {
            this.ai.enableManualSolutionTitles(problemId);
            const groupEl = this._container?.querySelector(
                `.dr-ai-solution-group[data-problem-id="${problemId}"]`
            );
            if (!groupEl) return;

            const contentEl = groupEl.querySelector('.dr-ai-solution-group__content');
            if (contentEl) {
                contentEl.innerHTML = this.ai.renderManualEntryForm('solution', 3);
            }
        }

        /**
         * Render solution titles as radio buttons for a problem.
         *
         * @param {HTMLElement} container  Target container.
         * @param {number}      problemId  Problem ID.
         * @param {Array}       titles     Solution title strings.
         * @private
         */
        _renderSolutionRadios(container, problemId, titles) {
            let html = `
                <div class="dr-ai-solution-options">
                    <p class="dr-ai-titles-instruction">
                        Select one solution for this problem:
                    </p>
            `;

            titles.forEach((title, index) => {
                html += `
                    <label class="dr-ai-title-option dr-ai-solution-option">
                        <input type="radio"
                            class="dr-ai-solution-radio"
                            name="solution_for_${problemId}"
                            value="${this._escapeAttr(title)}"
                            data-problem-id="${problemId}"
                            data-index="${index}"
                        />
                        <span class="dr-ai-title-text">${this.ai._escapeHtml(title)}</span>
                    </label>
                `;
            });

            html += `
                    <div class="dr-ai-solution-actions">
                        ${this.ai.renderRegenerateButton('solutions', problemId)}
                    </div>
                </div>
            `;

            container.innerHTML = html;

            // Dispatch event when solution selected.
            container.querySelectorAll('.dr-ai-solution-radio').forEach(radio => {
                radio.addEventListener('change', () => {
                    document.dispatchEvent(new CustomEvent('dr-solution-title-selected', {
                        detail: {
                            problemId,
                            title: radio.value,
                            index: parseInt(radio.dataset.index)
                        }
                    }));
                });
            });
        }

        /**
         * Get selected solution titles mapped to problem IDs.
         *
         * @returns {Object} Map of problemId -> selected title string.
         */
        getSelectedSolutions() {
            if (!this._container) return {};
            const selections = {};
            const radios = this._container.querySelectorAll('.dr-ai-solution-radio:checked');
            radios.forEach(radio => {
                selections[radio.dataset.problemId] = radio.value;
            });
            return selections;
        }

        /**
         * Escape a string for use in HTML attributes.
         *
         * @param {string} str Raw string.
         * @returns {string} Escaped string.
         * @private
         */
        _escapeAttr(str) {
            return str.replace(/&/g, '&amp;')
                      .replace(/"/g, '&quot;')
                      .replace(/'/g, '&#39;')
                      .replace(/</g, '&lt;')
                      .replace(/>/g, '&gt;');
        }
    }

    // =========================================================================
    // EXPORTS
    // =========================================================================

    // Export classes to global scope.
    window.AITitleManager = AITitleManager;
    window.ProblemTitleAIBridge = ProblemTitleAIBridge;
    window.SolutionTitleAIBridge = SolutionTitleAIBridge;

    // Also export for module-style imports if needed.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { AITitleManager, ProblemTitleAIBridge, SolutionTitleAIBridge };
    }

})(window, document, jQuery);
