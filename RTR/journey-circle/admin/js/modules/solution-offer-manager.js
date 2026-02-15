/**
 * Solution & Offer Manager Module
 *
 * Handles Step 7 (Solution Title Selection) and Step 8 (Offer Mapping)
 * of the Journey Circle Creator workflow.
 *
 * All data is database-driven - no mock data or template generation.
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 */

(function(window, document, $) {
    'use strict';

    /**
     * SolutionOfferManager Class
     *
     * Manages the solution selection and offer mapping workflow steps.
     */
    class SolutionOfferManager {
        /**
         * Constructor
         *
         * @param {Object} options Configuration options
         */
        constructor(options = {}) {
            // Configuration
            this.config = {
                circleId: options.circleId || null,
                clientId: options.clientId || null,
                apiBase: options.apiBase || '/wp-json/directreach/v2',
                nonce: options.nonce || window.drJourneyCircle?.nonce || '',
                onUpdate: options.onUpdate || null,
                onError: options.onError || null,
                ...options
            };

            // State
            this.state = {
                problems: [],
                solutions: [],
                offers: [],
                loading: false,
                currentStep: null,
                activeSolutionId: null,
                editingOfferId: null
            };

            // DOM Elements
            this.elements = {
                step7Container: null,
                step8Container: null,
                solutionsList: null,
                offersList: null
            };

            // Bind methods
            this.init = this.init.bind(this);
            this.loadProblems = this.loadProblems.bind(this);
            this.loadSolutions = this.loadSolutions.bind(this);
            this.loadOffers = this.loadOffers.bind(this);
            this.renderStep7 = this.renderStep7.bind(this);
            this.renderStep8 = this.renderStep8.bind(this);
        }

        /**
         * Initialize the manager
         *
         * @param {string} step Current step ('step7' or 'step8')
         */
        async init(step = 'step7') {
            this.state.currentStep = step;

            // Find containers
            this.elements.step7Container = document.getElementById('jc-step7-container');
            this.elements.step8Container = document.getElementById('jc-step8-container');

            // Load data
            try {
                this.setLoading(true);
                await this.loadProblems();
                await this.loadSolutions();
                await this.loadOffers();

                // Render appropriate step
                if (step === 'step7') {
                    this.renderStep7();
                } else if (step === 'step8') {
                    this.renderStep8();
                }
            } catch (error) {
                this.handleError('Failed to initialize solution manager', error);
            } finally {
                this.setLoading(false);
            }

            // Set up event listeners
            this.setupEventListeners();
        }

        /**
         * Set loading state
         *
         * @param {boolean} loading Loading state
         */
        setLoading(loading) {
            this.state.loading = loading;
            const containers = [this.elements.step7Container, this.elements.step8Container];
            
            containers.forEach(container => {
                if (container) {
                    container.classList.toggle('jc-loading', loading);
                }
            });
        }

        /**
         * Handle errors
         *
         * @param {string} message Error message
         * @param {Error} error Error object
         */
        handleError(message, error) {
            console.error(message, error);
            
            if (this.config.onError) {
                this.config.onError(message, error);
            }

            // Show error notification
            this.showNotification(message, 'error');
        }

        /**
         * Show notification
         * Delegates to the shared JCNotifications utility which uses
         * Campaign Builder's notification framework (base.css classes).
         *
         * @param {string} message Notification message
         * @param {string} type Notification type ('success', 'error', 'warning')
         */
        showNotification(message, type = 'success') {
            if (window.JCNotifications) {
                window.JCNotifications.show(type, message);
            } else {
                console.warn('[SolutionOfferManager]', type.toUpperCase(), message);
            }
        }

        /**
         * Escape HTML for safe display
         *
         * @param {string} text Text to escape
         * @returns {string} Escaped text
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // =========================================================================
        // API METHODS
        // =========================================================================

        /**
         * Make API request
         *
         * @param {string} endpoint API endpoint
         * @param {string} method HTTP method
         * @param {Object} data Request data
         * @returns {Promise<Object>} Response data
         */
        async apiRequest(endpoint, method = 'GET', data = null) {
            const url = `${this.config.apiBase}${endpoint}`;
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce
                }
            };

            if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
                options.body = JSON.stringify(data);
            }

            const response = await fetch(url, options);
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || 'API request failed');
            }

            return result;
        }

        /**
         * Load problems from database
         */
        async loadProblems() {
            if (!this.config.circleId) {
                throw new Error('Circle ID is required');
            }

            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/problems`
            );

            this.state.problems = result.data || [];
            return this.state.problems;
        }

        /**
         * Load solutions from database
         */
        async loadSolutions() {
            if (!this.config.circleId) {
                throw new Error('Circle ID is required');
            }

            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/solutions`
            );

            this.state.solutions = result.data || [];
            return this.state.solutions;
        }

        /**
         * Load offers from database
         */
        async loadOffers() {
            if (!this.config.circleId) {
                throw new Error('Circle ID is required');
            }

            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/offers`
            );

            this.state.offers = result.data || [];
            return this.state.offers;
        }

        /**
         * Create a new solution
         *
         * @param {number} problemId Problem ID
         * @param {string} title Solution title
         * @param {string} description Solution description
         * @returns {Promise<Object>} Created solution
         */
        async createSolution(problemId, title, description = '') {
            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/solutions`,
                'POST',
                { problem_id: problemId, title, description }
            );

            if (result.success && result.data) {
                this.state.solutions.push(result.data);
                this.triggerUpdate();
            }

            return result.data;
        }

        /**
         * Update a solution
         *
         * @param {number} solutionId Solution ID
         * @param {Object} data Update data
         * @returns {Promise<Object>} Updated solution
         */
        async updateSolution(solutionId, data) {
            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/solutions/${solutionId}`,
                'PUT',
                data
            );

            if (result.success && result.data) {
                const index = this.state.solutions.findIndex(s => s.id === solutionId);
                if (index !== -1) {
                    this.state.solutions[index] = result.data;
                }
                this.triggerUpdate();
            }

            return result.data;
        }

        /**
         * Delete a solution
         *
         * @param {number} solutionId Solution ID
         */
        async deleteSolution(solutionId) {
            await this.apiRequest(
                `/journey-circles/${this.config.circleId}/solutions/${solutionId}`,
                'DELETE'
            );

            this.state.solutions = this.state.solutions.filter(s => s.id !== solutionId);
            // Also remove associated offers from state
            this.state.offers = this.state.offers.filter(o => o.solution_id !== solutionId);
            this.triggerUpdate();
        }

        /**
         * Create a new offer
         *
         * @param {number} solutionId Solution ID
         * @param {string} title Offer title
         * @param {string} url Offer URL
         * @param {string} description Offer description
         * @returns {Promise<Object>} Created offer
         */
        async createOffer(solutionId, title, url, description = '') {
            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/offers`,
                'POST',
                { solution_id: solutionId, title, url, description }
            );

            if (result.success && result.data) {
                this.state.offers.push(result.data);
                this.triggerUpdate();
            }

            return result.data;
        }

        /**
         * Update an offer
         *
         * @param {number} offerId Offer ID
         * @param {Object} data Update data
         * @returns {Promise<Object>} Updated offer
         */
        async updateOffer(offerId, data) {
            const result = await this.apiRequest(
                `/journey-circles/${this.config.circleId}/offers/${offerId}`,
                'PUT',
                data
            );

            if (result.success && result.data) {
                const index = this.state.offers.findIndex(o => o.id === offerId);
                if (index !== -1) {
                    this.state.offers[index] = result.data;
                }
                this.triggerUpdate();
            }

            return result.data;
        }

        /**
         * Delete an offer
         *
         * @param {number} offerId Offer ID
         */
        async deleteOffer(offerId) {
            await this.apiRequest(
                `/journey-circles/${this.config.circleId}/offers/${offerId}`,
                'DELETE'
            );

            this.state.offers = this.state.offers.filter(o => o.id !== offerId);
            this.triggerUpdate();
        }

        /**
         * Trigger update callback
         */
        triggerUpdate() {
            if (this.config.onUpdate) {
                this.config.onUpdate({
                    solutions: this.state.solutions,
                    offers: this.state.offers
                });
            }

            // Dispatch custom event
            document.dispatchEvent(new CustomEvent('jc:solutionsUpdated', {
                detail: {
                    solutions: this.state.solutions,
                    offers: this.state.offers
                }
            }));
        }

        // =========================================================================
        // STEP 7: SOLUTION TITLE SELECTION
        // =========================================================================

        /**
         * Render Step 7 UI
         */
        renderStep7() {
            const container = this.elements.step7Container;
            if (!container) {
                console.error('Step 7 container not found');
                return;
            }

            // Check prerequisites
            if (this.state.problems.length === 0) {
                container.innerHTML = this.renderEmptyState(
                    'No Problems Found',
                    'Please complete Step 6 to select problems before adding solutions.',
                    'fa-exclamation-triangle'
                );
                return;
            }

            // Build problem-solution cards
            const problemCards = this.state.problems.map(problem => {
                const solution = this.state.solutions.find(s => s.problem_id === problem.id);
                return this.renderProblemSolutionCard(problem, solution);
            }).join('');

            // Summary section
            const completedCount = this.state.problems.filter(p => 
                this.state.solutions.some(s => s.problem_id === p.id)
            ).length;
            const totalCount = this.state.problems.length;

            container.innerHTML = `
                <div class="jc-step7">
                    <div class="jc-step7__header">
                        <h3 class="jc-step7__title">
                            <i class="fas fa-lightbulb"></i>
                            Add Solutions for Each Problem
                        </h3>
                        <p class="jc-step7__description">
                            For each problem identified, create a solution that addresses it.
                            Each problem must have exactly one solution.
                        </p>
                        <div class="jc-step7__progress">
                            <div class="jc-step7__progress-bar">
                                <div class="jc-step7__progress-fill" style="width: ${(completedCount / totalCount) * 100}%"></div>
                            </div>
                            <span class="jc-step7__progress-text">
                                ${completedCount} of ${totalCount} solutions added
                            </span>
                        </div>
                    </div>

                    <div class="jc-step7__cards">
                        ${problemCards}
                    </div>

                    <div class="jc-step7__validation ${completedCount === totalCount ? 'jc-step7__validation--complete' : ''}">
                        ${completedCount === totalCount 
                            ? '<i class="fas fa-check-circle"></i> All problems have solutions. You can proceed to the next step.'
                            : `<i class="fas fa-info-circle"></i> Add solutions to ${totalCount - completedCount} more problem${totalCount - completedCount > 1 ? 's' : ''} to continue.`
                        }
                    </div>
                </div>
            `;
        }

        /**
         * Render a problem-solution card
         *
         * @param {Object} problem Problem data
         * @param {Object|null} solution Solution data (if exists)
         * @returns {string} HTML string
         */
        renderProblemSolutionCard(problem, solution) {
            const isPrimary = problem.is_primary;
            const hasSolution = !!solution;

            return `
                <div class="jc-solution-card ${hasSolution ? 'jc-solution-card--has-solution' : ''} ${isPrimary ? 'jc-solution-card--primary' : ''}"
                     data-problem-id="${problem.id}"
                     data-solution-id="${solution?.id || ''}">
                    
                    <div class="jc-solution-card__problem">
                        <div class="jc-solution-card__problem-header">
                            <span class="jc-solution-card__position">${problem.position + 1}</span>
                            <span class="jc-solution-card__problem-label">Problem</span>
                            ${isPrimary ? '<span class="jc-badge jc-badge--primary">Primary</span>' : ''}
                        </div>
                        <h4 class="jc-solution-card__problem-title">${this.escapeHtml(problem.title)}</h4>
                    </div>

                    <div class="jc-solution-card__arrow">
                        <i class="fas fa-arrow-down"></i>
                    </div>

                    <div class="jc-solution-card__solution">
                        ${hasSolution 
                            ? this.renderExistingSolution(solution)
                            : this.renderSolutionForm(problem.id)
                        }
                    </div>
                </div>
            `;
        }

        /**
         * Render existing solution display
         *
         * @param {Object} solution Solution data
         * @returns {string} HTML string
         */
        renderExistingSolution(solution) {
            return `
                <div class="jc-solution-card__solution-display">
                    <div class="jc-solution-card__solution-header">
                        <span class="jc-solution-card__solution-label">Solution</span>
                        <div class="jc-solution-card__solution-actions">
                            <button type="button" class="jc-btn jc-btn--icon jc-btn--edit-solution"
                                    data-solution-id="${solution.id}"
                                    title="Edit solution">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="jc-btn jc-btn--icon jc-btn--danger jc-btn--delete-solution"
                                    data-solution-id="${solution.id}"
                                    title="Delete solution">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <h4 class="jc-solution-card__solution-title">${this.escapeHtml(solution.title)}</h4>
                    ${solution.description ? `<p class="jc-solution-card__solution-desc">${this.escapeHtml(solution.description)}</p>` : ''}
                    <div class="jc-solution-card__solution-status">
                        <i class="fas fa-check-circle"></i>
                        <span>Solution added</span>
                    </div>
                </div>
            `;
        }

        /**
         * Render solution form
         *
         * @param {number} problemId Problem ID
         * @param {Object|null} existingSolution Existing solution for editing
         * @returns {string} HTML string
         */
        renderSolutionForm(problemId, existingSolution = null) {
            const isEditing = !!existingSolution;
            
            return `
                <form class="jc-solution-form" data-problem-id="${problemId}" 
                      ${isEditing ? `data-solution-id="${existingSolution.id}"` : ''}>
                    <div class="jc-solution-form__header">
                        <span class="jc-solution-card__solution-label">
                            ${isEditing ? 'Edit Solution' : 'Add Solution'}
                        </span>
                    </div>
                    
                    <div class="jc-form-group">
                        <label for="solution-title-${problemId}" class="jc-form-label">
                            Solution Title <span class="jc-required">*</span>
                        </label>
                        <input type="text" 
                               id="solution-title-${problemId}"
                               name="title" 
                               class="jc-form-input"
                               placeholder="Enter the solution that addresses this problem..."
                               value="${isEditing ? this.escapeHtml(existingSolution.title) : ''}"
                               required>
                    </div>

                    <div class="jc-form-group">
                        <label for="solution-desc-${problemId}" class="jc-form-label">
                            Description <span class="jc-optional">(optional)</span>
                        </label>
                        <textarea id="solution-desc-${problemId}"
                                  name="description"
                                  class="jc-form-textarea"
                                  placeholder="Briefly describe how this solution addresses the problem..."
                                  rows="2">${isEditing ? this.escapeHtml(existingSolution.description || '') : ''}</textarea>
                    </div>

                    <div class="jc-solution-form__actions">
                        ${isEditing ? `
                            <button type="button" class="jc-btn jc-btn--secondary jc-btn--cancel-edit">
                                Cancel
                            </button>
                        ` : ''}
                        <button type="submit" class="jc-btn jc-btn--primary">
                            <i class="fas fa-save"></i>
                            ${isEditing ? 'Update Solution' : 'Add Solution'}
                        </button>
                    </div>
                </form>
            `;
        }

        // =========================================================================
        // STEP 8: OFFER MAPPING
        // =========================================================================

        /**
         * Render Step 8 UI
         */
        renderStep8() {
            const container = this.elements.step8Container;
            if (!container) {
                console.error('Step 8 container not found');
                return;
            }

            // Check prerequisites
            if (this.state.solutions.length === 0) {
                container.innerHTML = this.renderEmptyState(
                    'No Solutions Found',
                    'Please complete Step 7 to add solutions before mapping offers.',
                    'fa-exclamation-triangle'
                );
                return;
            }

            // Build solution-offer sections
            const solutionSections = this.state.solutions.map(solution => {
                const offers = this.state.offers.filter(o => o.solution_id === solution.id);
                return this.renderSolutionOffersSection(solution, offers);
            }).join('');

            // Calculate total offers
            const totalOffers = this.state.offers.length;

            container.innerHTML = `
                <div class="jc-step8">
                    <div class="jc-step8__header">
                        <h3 class="jc-step8__title">
                            <i class="fas fa-link"></i>
                            Map Offers to Solutions
                        </h3>
                        <p class="jc-step8__description">
                            For each solution, add one or more offers (products, services, or calls-to-action)
                            that help implement the solution. Each offer requires a title and URL.
                        </p>
                        <div class="jc-step8__summary">
                            <span class="jc-step8__summary-item">
                                <i class="fas fa-lightbulb"></i>
                                ${this.state.solutions.length} Solutions
                            </span>
                            <span class="jc-step8__summary-item">
                                <i class="fas fa-tag"></i>
                                ${totalOffers} Offers
                            </span>
                        </div>
                    </div>

                    <div class="jc-step8__sections">
                        ${solutionSections}
                    </div>

                    <div class="jc-step8__validation ${totalOffers > 0 ? 'jc-step8__validation--complete' : ''}">
                        ${totalOffers > 0
                            ? '<i class="fas fa-check-circle"></i> Offers have been mapped. You can proceed to the next step.'
                            : '<i class="fas fa-info-circle"></i> Add at least one offer to continue.'
                        }
                    </div>
                </div>
            `;
        }

        /**
         * Render solution offers section
         *
         * @param {Object} solution Solution data
         * @param {Array} offers Offers for this solution
         * @returns {string} HTML string
         */
        renderSolutionOffersSection(solution, offers) {
            const isExpanded = this.state.activeSolutionId === solution.id || offers.length === 0;

            return `
                <div class="jc-offer-section ${isExpanded ? 'jc-offer-section--expanded' : ''}"
                     data-solution-id="${solution.id}">
                    
                    <div class="jc-offer-section__header" data-toggle="section">
                        <div class="jc-offer-section__header-content">
                            <span class="jc-offer-section__position">${solution.position + 1}</span>
                            <div class="jc-offer-section__info">
                                <h4 class="jc-offer-section__solution-title">${this.escapeHtml(solution.title)}</h4>
                                <span class="jc-offer-section__problem-title">
                                    <i class="fas fa-arrow-left"></i>
                                    ${this.escapeHtml(solution.problem_title || 'Unknown Problem')}
                                </span>
                            </div>
                        </div>
                        <div class="jc-offer-section__header-actions">
                            <span class="jc-offer-section__count">
                                ${offers.length} offer${offers.length !== 1 ? 's' : ''}
                            </span>
                            <i class="fas fa-chevron-down jc-offer-section__toggle"></i>
                        </div>
                    </div>

                    <div class="jc-offer-section__content">
                        <div class="jc-offer-section__offers">
                            ${offers.length > 0 
                                ? offers.map(offer => this.renderOfferCard(offer)).join('')
                                : this.renderNoOffersMessage()
                            }
                        </div>

                        <div class="jc-offer-section__add">
                            ${this.renderOfferForm(solution.id)}
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Render offer card
         *
         * @param {Object} offer Offer data
         * @returns {string} HTML string
         */
        renderOfferCard(offer) {
            const isEditing = this.state.editingOfferId === offer.id;

            if (isEditing) {
                return this.renderOfferEditForm(offer);
            }

            return `
                <div class="jc-offer-card" data-offer-id="${offer.id}">
                    <div class="jc-offer-card__content">
                        <div class="jc-offer-card__main">
                            <h5 class="jc-offer-card__title">${this.escapeHtml(offer.title)}</h5>
                            <a href="${this.escapeHtml(offer.url)}" 
                               class="jc-offer-card__url" 
                               target="_blank" 
                               rel="noopener noreferrer">
                                <i class="fas fa-external-link-alt"></i>
                                ${this.escapeHtml(this.truncateUrl(offer.url))}
                            </a>
                            ${offer.description ? `
                                <p class="jc-offer-card__description">${this.escapeHtml(offer.description)}</p>
                            ` : ''}
                        </div>
                        <div class="jc-offer-card__actions">
                            <button type="button" 
                                    class="jc-btn jc-btn--icon jc-btn--edit-offer"
                                    data-offer-id="${offer.id}"
                                    title="Edit offer">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" 
                                    class="jc-btn jc-btn--icon jc-btn--danger jc-btn--delete-offer"
                                    data-offer-id="${offer.id}"
                                    title="Delete offer">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Render offer edit form
         *
         * @param {Object} offer Offer data
         * @returns {string} HTML string
         */
        renderOfferEditForm(offer) {
            return `
                <div class="jc-offer-card jc-offer-card--editing" data-offer-id="${offer.id}">
                    <form class="jc-offer-edit-form" data-offer-id="${offer.id}">
                        <div class="jc-form-row">
                            <div class="jc-form-group jc-form-group--title">
                                <label class="jc-form-label">Title</label>
                                <input type="text" 
                                       name="title" 
                                       class="jc-form-input"
                                       value="${this.escapeHtml(offer.title)}"
                                       required>
                            </div>
                            <div class="jc-form-group jc-form-group--url">
                                <label class="jc-form-label">URL</label>
                                <input type="url" 
                                       name="url" 
                                       class="jc-form-input"
                                       value="${this.escapeHtml(offer.url)}"
                                       required>
                            </div>
                        </div>
                        <div class="jc-form-group">
                            <label class="jc-form-label">Description</label>
                            <textarea name="description" 
                                      class="jc-form-textarea"
                                      rows="2">${this.escapeHtml(offer.description || '')}</textarea>
                        </div>
                        <div class="jc-offer-edit-form__actions">
                            <button type="button" class="jc-btn jc-btn--secondary jc-btn--cancel-edit-offer">
                                Cancel
                            </button>
                            <button type="submit" class="jc-btn jc-btn--primary">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </form>
                </div>
            `;
        }

        /**
         * Render offer form
         *
         * @param {number} solutionId Solution ID
         * @returns {string} HTML string
         */
        renderOfferForm(solutionId) {
            return `
                <form class="jc-offer-form" data-solution-id="${solutionId}">
                    <div class="jc-offer-form__header">
                        <h5 class="jc-offer-form__title">
                            <i class="fas fa-plus-circle"></i>
                            Add New Offer
                        </h5>
                    </div>

                    <div class="jc-form-row">
                        <div class="jc-form-group jc-form-group--title">
                            <label for="offer-title-${solutionId}" class="jc-form-label">
                                Offer Title <span class="jc-required">*</span>
                            </label>
                            <input type="text" 
                                   id="offer-title-${solutionId}"
                                   name="title" 
                                   class="jc-form-input"
                                   placeholder="e.g., Free Consultation, Product Demo, eBook Download"
                                   required>
                        </div>
                        <div class="jc-form-group jc-form-group--url">
                            <label for="offer-url-${solutionId}" class="jc-form-label">
                                Offer URL <span class="jc-required">*</span>
                            </label>
                            <input type="url" 
                                   id="offer-url-${solutionId}"
                                   name="url" 
                                   class="jc-form-input"
                                   placeholder="https://example.com/offer"
                                   required>
                        </div>
                    </div>

                    <div class="jc-form-group">
                        <label for="offer-desc-${solutionId}" class="jc-form-label">
                            Description <span class="jc-optional">(optional)</span>
                        </label>
                        <textarea id="offer-desc-${solutionId}"
                                  name="description"
                                  class="jc-form-textarea"
                                  placeholder="Brief description of the offer..."
                                  rows="2"></textarea>
                    </div>

                    <div class="jc-offer-form__actions">
                        <button type="submit" class="jc-btn jc-btn--primary">
                            <i class="fas fa-plus"></i>
                            Add Offer
                        </button>
                    </div>
                </form>
            `;
        }

        /**
         * Render no offers message
         *
         * @returns {string} HTML string
         */
        renderNoOffersMessage() {
            return `
                <div class="jc-no-offers">
                    <i class="fas fa-inbox"></i>
                    <p>No offers added yet. Add an offer below.</p>
                </div>
            `;
        }

        /**
         * Render empty state
         *
         * @param {string} title Title
         * @param {string} message Message
         * @param {string} icon Icon class
         * @returns {string} HTML string
         */
        renderEmptyState(title, message, icon = 'fa-info-circle') {
            return `
                <div class="jc-empty-state">
                    <i class="fas ${icon}"></i>
                    <h4>${this.escapeHtml(title)}</h4>
                    <p>${this.escapeHtml(message)}</p>
                </div>
            `;
        }

        /**
         * Truncate URL for display
         *
         * @param {string} url URL to truncate
         * @param {number} maxLength Maximum length
         * @returns {string} Truncated URL
         */
        truncateUrl(url, maxLength = 50) {
            if (url.length <= maxLength) return url;
            return url.substring(0, maxLength - 3) + '...';
        }

        // =========================================================================
        // EVENT LISTENERS
        // =========================================================================

        /**
         * Set up event listeners
         */
        setupEventListeners() {
            // Use event delegation on document
            document.addEventListener('submit', this.handleFormSubmit.bind(this));
            document.addEventListener('click', this.handleClick.bind(this));
        }

        /**
         * Handle form submissions
         *
         * @param {Event} e Submit event
         */
        async handleFormSubmit(e) {
            const form = e.target;

            // Solution form
            if (form.classList.contains('jc-solution-form')) {
                e.preventDefault();
                await this.handleSolutionFormSubmit(form);
                return;
            }

            // Offer form
            if (form.classList.contains('jc-offer-form')) {
                e.preventDefault();
                await this.handleOfferFormSubmit(form);
                return;
            }

            // Offer edit form
            if (form.classList.contains('jc-offer-edit-form')) {
                e.preventDefault();
                await this.handleOfferEditFormSubmit(form);
                return;
            }
        }

        /**
         * Handle click events
         *
         * @param {Event} e Click event
         */
        async handleClick(e) {
            const target = e.target;

            // Section toggle
            if (target.closest('[data-toggle="section"]')) {
                const section = target.closest('.jc-offer-section');
                if (section) {
                    section.classList.toggle('jc-offer-section--expanded');
                    const solutionId = parseInt(section.dataset.solutionId);
                    this.state.activeSolutionId = section.classList.contains('jc-offer-section--expanded') 
                        ? solutionId 
                        : null;
                }
                return;
            }

            // Edit solution
            if (target.closest('.jc-btn--edit-solution')) {
                const btn = target.closest('.jc-btn--edit-solution');
                const solutionId = parseInt(btn.dataset.solutionId);
                await this.handleEditSolution(solutionId);
                return;
            }

            // Delete solution
            if (target.closest('.jc-btn--delete-solution')) {
                const btn = target.closest('.jc-btn--delete-solution');
                const solutionId = parseInt(btn.dataset.solutionId);
                await this.handleDeleteSolution(solutionId);
                return;
            }

            // Cancel solution edit
            if (target.closest('.jc-btn--cancel-edit')) {
                this.renderStep7();
                return;
            }

            // Edit offer
            if (target.closest('.jc-btn--edit-offer')) {
                const btn = target.closest('.jc-btn--edit-offer');
                const offerId = parseInt(btn.dataset.offerId);
                this.handleEditOffer(offerId);
                return;
            }

            // Delete offer
            if (target.closest('.jc-btn--delete-offer')) {
                const btn = target.closest('.jc-btn--delete-offer');
                const offerId = parseInt(btn.dataset.offerId);
                await this.handleDeleteOffer(offerId);
                return;
            }

            // Cancel offer edit
            if (target.closest('.jc-btn--cancel-edit-offer')) {
                this.state.editingOfferId = null;
                this.renderStep8();
                return;
            }
        }

        /**
         * Handle solution form submission
         *
         * @param {HTMLFormElement} form Form element
         */
        async handleSolutionFormSubmit(form) {
            const problemId = parseInt(form.dataset.problemId);
            const solutionId = form.dataset.solutionId ? parseInt(form.dataset.solutionId) : null;
            const formData = new FormData(form);
            const title = formData.get('title').trim();
            const description = formData.get('description')?.trim() || '';

            if (!title) {
                this.showNotification('Please enter a solution title', 'error');
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                if (solutionId) {
                    await this.updateSolution(solutionId, { title, description });
                    this.showNotification('Solution updated successfully', 'success');
                } else {
                    await this.createSolution(problemId, title, description);
                    this.showNotification('Solution added successfully', 'success');
                }
                this.renderStep7();
            } catch (error) {
                this.handleError('Failed to save solution', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> ' + (solutionId ? 'Update Solution' : 'Add Solution');
            }
        }

        /**
         * Handle edit solution
         *
         * @param {number} solutionId Solution ID
         */
        handleEditSolution(solutionId) {
            const solution = this.state.solutions.find(s => s.id === solutionId);
            if (!solution) return;

            const card = document.querySelector(`[data-solution-id="${solutionId}"]`);
            if (!card) return;

            const solutionDiv = card.querySelector('.jc-solution-card__solution');
            if (!solutionDiv) return;

            solutionDiv.innerHTML = this.renderSolutionForm(solution.problem_id, solution);
        }

        /**
         * Handle delete solution
         *
         * @param {number} solutionId Solution ID
         */
        async handleDeleteSolution(solutionId) {
            const solution = this.state.solutions.find(s => s.id === solutionId);
            if (!solution) return;

            const offersCount = this.state.offers.filter(o => o.solution_id === solutionId).length;
            const confirmMessage = offersCount > 0
                ? `Are you sure you want to delete this solution and its ${offersCount} offer(s)?`
                : 'Are you sure you want to delete this solution?';

            if (!confirm(confirmMessage)) return;

            try {
                await this.deleteSolution(solutionId);
                this.showNotification('Solution deleted successfully', 'success');
                this.renderStep7();
            } catch (error) {
                this.handleError('Failed to delete solution', error);
            }
        }

        /**
         * Handle offer form submission
         *
         * @param {HTMLFormElement} form Form element
         */
        async handleOfferFormSubmit(form) {
            const solutionId = parseInt(form.dataset.solutionId);
            const formData = new FormData(form);
            const title = formData.get('title').trim();
            const url = formData.get('url').trim();
            const description = formData.get('description')?.trim() || '';

            // Validate
            if (!title || !url) {
                this.showNotification('Please enter both title and URL', 'error');
                return;
            }

            if (!this.isValidUrl(url)) {
                this.showNotification('Please enter a valid URL', 'error');
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';

            try {
                await this.createOffer(solutionId, title, url, description);
                this.showNotification('Offer added successfully', 'success');
                form.reset();
                this.renderStep8();
            } catch (error) {
                this.handleError('Failed to add offer', error);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Offer';
            }
        }

        /**
         * Handle edit offer
         *
         * @param {number} offerId Offer ID
         */
        handleEditOffer(offerId) {
            this.state.editingOfferId = offerId;
            this.renderStep8();
        }

        /**
         * Handle offer edit form submission
         *
         * @param {HTMLFormElement} form Form element
         */
        async handleOfferEditFormSubmit(form) {
            const offerId = parseInt(form.dataset.offerId);
            const formData = new FormData(form);
            const title = formData.get('title').trim();
            const url = formData.get('url').trim();
            const description = formData.get('description')?.trim() || '';

            // Validate
            if (!title || !url) {
                this.showNotification('Please enter both title and URL', 'error');
                return;
            }

            if (!this.isValidUrl(url)) {
                this.showNotification('Please enter a valid URL', 'error');
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                await this.updateOffer(offerId, { title, url, description });
                this.showNotification('Offer updated successfully', 'success');
                this.state.editingOfferId = null;
                this.renderStep8();
            } catch (error) {
                this.handleError('Failed to update offer', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
        }

        /**
         * Handle delete offer
         *
         * @param {number} offerId Offer ID
         */
        async handleDeleteOffer(offerId) {
            if (!confirm('Are you sure you want to delete this offer?')) return;

            try {
                await this.deleteOffer(offerId);
                this.showNotification('Offer deleted successfully', 'success');
                this.renderStep8();
            } catch (error) {
                this.handleError('Failed to delete offer', error);
            }
        }

        /**
         * Validate URL
         *
         * @param {string} url URL to validate
         * @returns {boolean} Is valid
         */
        isValidUrl(url) {
            try {
                new URL(url);
                return true;
            } catch {
                return false;
            }
        }

        // =========================================================================
        // PUBLIC METHODS
        // =========================================================================

        /**
         * Get current state
         *
         * @returns {Object} Current state
         */
        getState() {
            return { ...this.state };
        }

        /**
         * Check if step 7 is complete (all problems have solutions)
         *
         * @returns {boolean} Is complete
         */
        isStep7Complete() {
            if (this.state.problems.length === 0) return false;
            return this.state.problems.every(p => 
                this.state.solutions.some(s => s.problem_id === p.id)
            );
        }

        /**
         * Check if step 8 is complete (at least one offer exists)
         *
         * @returns {boolean} Is complete
         */
        isStep8Complete() {
            return this.state.offers.length > 0;
        }

        /**
         * Get data for canvas visualization
         *
         * @returns {Object} Canvas data
         */
        getCanvasData() {
            return {
                solutions: this.state.solutions.map(s => ({
                    id: s.id,
                    title: s.title,
                    problemId: s.problem_id,
                    position: s.position
                })),
                offers: this.state.offers.map(o => ({
                    id: o.id,
                    title: o.title,
                    url: o.url,
                    solutionId: o.solution_id
                })),
                offerCount: this.state.offers.length
            };
        }

        /**
         * Refresh data from server
         */
        async refresh() {
            try {
                this.setLoading(true);
                await this.loadProblems();
                await this.loadSolutions();
                await this.loadOffers();

                if (this.state.currentStep === 'step7') {
                    this.renderStep7();
                } else if (this.state.currentStep === 'step8') {
                    this.renderStep8();
                }
            } catch (error) {
                this.handleError('Failed to refresh data', error);
            } finally {
                this.setLoading(false);
            }
        }

        /**
         * Switch to a specific step
         *
         * @param {string} step Step to switch to
         */
        switchToStep(step) {
            this.state.currentStep = step;
            if (step === 'step7') {
                this.renderStep7();
            } else if (step === 'step8') {
                this.renderStep8();
            }
        }
    }

    // Export to global scope
    window.SolutionOfferManager = SolutionOfferManager;

    // Also export as module if available
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = SolutionOfferManager;
    }

})(window, document, window.jQuery);