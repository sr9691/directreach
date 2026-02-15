/**
 * Journey Circle Workflow State Machine
 * 
 * Manages the 11-step workflow including:
 * - Step navigation
 * - State persistence (localStorage + API)
 * - Validation
 * - Progress tracking
 * 
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0
 */

(function($) {
    'use strict';

    /**
     * Journey Circle Workflow Manager
     */
    class JourneyCircleWorkflow {
        constructor(config) {
            this.config = config;
            this.currentStep = 1;
            this.totalSteps = 11;
            this.state = this.loadState();
            this.autoSaveInterval = null;
            
            this.init();
        }

        /**
         * Initialize workflow
         */
        init() {
            this.bindEvents();
            this.restoreState();
            this.startAutoSave();
            this.updateUI();
            
            console.log('Journey Circle Workflow initialized', this.state);
        }

        /**
         * Bind event handlers
         */
        bindEvents() {
            // Navigation buttons
            $('.jc-next-btn').on('click', () => this.goToNextStep());
            $('.jc-prev-btn').on('click', () => this.goToPreviousStep());
            $('.jc-return-btn').on('click', () => this.returnToCampaignBuilder());
            
            // Progress step clicks
            $('.jc-progress-step').on('click', (e) => {
                const step = $(e.currentTarget).data('step');
                if (step <= this.getMaxAccessibleStep()) {
                    this.goToStep(step);
                }
            });
            
            // Browser back button / page unload
            $(window).on('beforeunload', () => {
                this.saveState();
            });
        }

        /**
         * Navigate to specific step
         */
        goToStep(stepNumber, skipValidation = false) {
            if (stepNumber < 1 || stepNumber > this.totalSteps) {
                return;
            }

            // Validate current step before proceeding (skip if already validated by caller)
            if (!skipValidation && stepNumber > this.currentStep && !this.validateCurrentStep()) {
                return;
            }

            // Hide current step

            // Hide current step
            $(`#jc-step-${this.currentStep}`).fadeOut(200, () => {
                // Update current step
                this.currentStep = stepNumber;
                
                // Show new step
                $(`#jc-step-${this.currentStep}`).fadeIn(200);
                
                // Update UI
                this.updateUI();
                
                // Scroll to top
                $('.jc-main-content').animate({ scrollTop: 0 }, 300);
                
                // Trigger step change event
                $(document).trigger('jc:stepChanged', [this.currentStep]);
                
                // Save state
                this.saveState();
            });
        }

        /**
         * Go to next step
         */
        goToNextStep() {
            if (this.currentStep < this.totalSteps && this.validateCurrentStep()) {
                this.goToStep(this.currentStep + 1, true);
            }
        }

        /**
         * Go to previous step
         */
        goToPreviousStep() {
            if (this.currentStep > 1) {
                this.goToStep(this.currentStep - 1);
            }
        }

        /**
         * Validate current step
         */
        validateCurrentStep() {
            let isValid = true;
            let errorMessage = '';
            
            console.log('[JC Workflow] Validating step', this.currentStep, 'state:', {
                selectedProblems: this.state.selectedProblems,
                primaryProblemId: this.state.primaryProblemId,
                selectedSolutions: this.state.selectedSolutions
            });

            switch (this.currentStep) {
                case 1: // Brain Content
                    if (!this.state.brainContent || this.state.brainContent.length === 0) {
                        isValid = false;
                        errorMessage = 'Please add at least one resource (URL, text, or file) before proceeding.';
                    }
                    break;

                case 2: // Service Area
                    if (!this.state.serviceAreaId) {
                        isValid = false;
                        errorMessage = 'Please select or create a service area before proceeding.';
                    }
                    break;

                case 3: // Existing Assets (optional)
                    isValid = true;
                    break;

                case 4: // Industries
                    if (!this.state.industries || this.state.industries.length === 0) {
                        isValid = false;
                        errorMessage = 'Please select at least one industry before proceeding.';
                    }
                    break;

                case 5: // Primary Problem
                    if (!this.state.primaryProblemId) {
                        isValid = false;
                        errorMessage = 'Please designate a primary problem before proceeding.';
                    }
                    break;

                case 6: // Problem Titles
                    if (!this.state.selectedProblems || this.state.selectedProblems.length !== 5) {
                        isValid = false;
                        errorMessage = 'Please select exactly 5 problem titles before proceeding.';
                    }
                    break;

                case 7: // Solution Titles
                    if (!this.state.selectedSolutions || Object.keys(this.state.selectedSolutions).length < 5) {
                        isValid = false;
                        errorMessage = 'Please select a solution for each problem before proceeding.';
                    }
                    break;

                case 8: // Offer Mapping
                    // At least one offer total is required
                    {
                        const offers = this.state.offers || {};
                        const totalOffers = Object.values(offers).reduce((sum, arr) => {
                            return sum + (Array.isArray(arr) ? arr.length : 0);
                        }, 0);
                        if (totalOffers === 0) {
                            isValid = false;
                            errorMessage = 'Please add at least one offer before proceeding.';
                        }
                    }
                    break;

                case 9: // Asset Creation (optional - can proceed without creating all)
                    isValid = true;
                    break;

                case 10: // Link Published Assets (optional)
                    isValid = true;
                    break;

                case 11: // Complete
                    isValid = true;
                    break;
            }

            if (!isValid) {
                console.warn('[JC Workflow] Validation FAILED for step', this.currentStep, ':', errorMessage);
                this.showNotification(errorMessage, 'error');
            } else {
                console.log('[JC Workflow] Validation PASSED for step', this.currentStep);
            }

            return isValid;
        }


        /**
         * Get maximum accessible step based on completed steps
         */
        getMaxAccessibleStep() {
            let maxStep = 1;
            
            if (this.state.brainContent && this.state.brainContent.length > 0) {
                maxStep = 2;
            }
            if (this.state.serviceAreaId) {
                maxStep = 3; // Step 3 is optional, so also unlock 4
            }
            if (maxStep >= 3) {
                maxStep = 4; // Industries
            }
            if (this.state.industries && this.state.industries.length > 0) {
                maxStep = 5;
            }
            if (this.state.primaryProblemId) {
                maxStep = 6;
            }
            if (this.state.selectedProblems && this.state.selectedProblems.length === 5) {
                maxStep = 7;
            }
            if (this.state.selectedSolutions && Object.keys(this.state.selectedSolutions).length >= 5) {
                maxStep = 8;
            }
            // Steps 8+ are progressively unlockable
            if (maxStep >= 8) {
                const offers = this.state.offers || {};
                const totalOffers = Object.values(offers).reduce((sum, arr) => {
                    return sum + (Array.isArray(arr) ? arr.length : 0);
                }, 0);
                if (totalOffers > 0) {
                    maxStep = 11; // Once offers exist, allow free navigation to all remaining steps
                }
            }
            
            return Math.max(maxStep, this.currentStep);
        }

        /**
         * Update UI elements
         */
        updateUI() {
            // Update progress indicator
            this.updateProgressIndicator();
            
            // Update step indicator
            $('.jc-current-step').text(this.currentStep);
            
            // Update navigation buttons
            this.updateNavigationButtons();
            
            // Update progress bar
            const progressPercent = ((this.currentStep - 1) / (this.totalSteps - 1)) * 100;
            $('.jc-progress-fill').css('width', `${progressPercent}%`);
        }

        /**
         * Update progress step indicator
         */
        updateProgressIndicator() {
            $('.jc-progress-step').each((index, element) => {
                const $step = $(element);
                const step = $step.data('step');
                
                $step.removeClass('active completed accessible');
                
                if (step === this.currentStep) {
                    $step.addClass('active');
                } else if (step < this.currentStep) {
                    $step.addClass('completed');
                } else if (step <= this.getMaxAccessibleStep()) {
                    $step.addClass('accessible');
                }
            });
        }

        /**
         * Update navigation buttons
         */
        updateNavigationButtons() {
            // Previous button
            if (this.currentStep === 1) {
                $('.jc-prev-btn').prop('disabled', true);
            } else {
                $('.jc-prev-btn').prop('disabled', false);
            }

            // Next button
            const $nextBtn = $('.jc-next-btn');
            if (this.currentStep === this.totalSteps) {
                $nextBtn.html('<i class="fas fa-check"></i> Complete Journey');
                $nextBtn.removeClass('button-primary').addClass('button-success');
            } else {
                $nextBtn.html('Next <i class="fas fa-arrow-right"></i>');
                $nextBtn.removeClass('button-success').addClass('button-primary');
            }
        }

        /**
         * Load state from localStorage
         */
        loadState() {
            const stateKey = `dr_journey_circle_${this.config.clientId}`;
            const savedState = localStorage.getItem(stateKey);
            
            if (savedState) {
                try {
                    return JSON.parse(savedState);
                } catch (e) {
                    console.error('Error parsing saved state:', e);
                }
            }
            
            // Default state
            return {
                clientId: this.config.clientId,
                serviceAreaId: this.config.serviceAreaId || null,
                journeyCircleId: null,
                currentStep: 1,
                brainContent: [],
                existingAssets: [],
                industries: [],
                primaryProblemId: null,
                problems: [],
                solutions: [],
                offers: [],
                assets: {},
                lastSaved: null
            };
        }

        /**
         * Save state to localStorage
         */
        saveState() {
            const stateKey = `dr_journey_circle_${this.config.clientId}`;
            this.state.currentStep = this.currentStep;
            this.state.lastSaved = new Date().toISOString();
            
            try {
                localStorage.setItem(stateKey, JSON.stringify(this.state));
                console.log('State saved to localStorage', this.state);
            } catch (e) {
                console.error('Error saving state:', e);
            }
        }

        /**
         * Restore state from localStorage
         */
        restoreState() {
            // ── Always start at Step 1 when launched from Campaign Builder ──
            // CB's client-manager.js sets 'dr_journey_client' in sessionStorage
            // right before navigating here. If that flag is present, this is a
            // fresh launch — force Step 1 regardless of saved state.
            const freshLaunch = sessionStorage.getItem('dr_journey_client');
            if (freshLaunch) {
                // Consume the flag so a page refresh stays on the current step
                sessionStorage.removeItem('dr_journey_client');
                this.state.currentStep = 1;
                this.currentStep = 1;
                console.log('[JC Workflow] Fresh launch from Campaign Builder — starting at Step 1');
                // Still trigger restore so modules can hydrate from persisted data
                $(document).trigger('jc:restoreState', [this.state]);
                const restoredStep = this.currentStep;
                setTimeout(() => {
                    $(document).trigger('jc:stepChanged', [restoredStep]);
                }, 100);
                return;
            }

            if (this.state.currentStep && this.state.currentStep !== this.currentStep) {
                this.currentStep = this.state.currentStep;
                $(`#jc-step-${this.currentStep}`).show();
                $('#jc-step-1').hide();
            }
            
            // Trigger restore events for each module
            $(document).trigger('jc:restoreState', [this.state]);
            
            // Also trigger stepChanged so modules that listen for it
            // (e.g. ProblemSolutionManager) initialize their UI on page load.
            // Use setTimeout to allow all modules to register their listeners first.
            const restoredStep = this.currentStep;
            setTimeout(() => {
                $(document).trigger('jc:stepChanged', [restoredStep]);
            }, 100);
        }

        /**
         * Sync state with API
         */
        async syncStateToAPI() {
            if (!this.state.journeyCircleId) {
                return; // Can't sync without journey circle ID
            }

            try {
                const response = await fetch(`${this.config.restUrl}/journey-circles/${this.state.journeyCircleId}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.restNonce
                    },
                    body: JSON.stringify({
                        brain_content: this.state.brainContent,
                        industries: this.state.industries,
                        primary_problem_id: this.state.primaryProblemId
                    })
                });

                if (!response.ok) {
                    throw new Error('API sync failed');
                }

                console.log('State synced to API');
            } catch (error) {
                console.error('Error syncing state to API:', error);
            }
        }

        /**
         * Start auto-save interval
         */
        startAutoSave() {
            // Save every 30 seconds
            this.autoSaveInterval = setInterval(() => {
                this.saveState();
                this.syncStateToAPI();
            }, 30000);
        }

        /**
         * Stop auto-save interval
         */
        stopAutoSave() {
            if (this.autoSaveInterval) {
                clearInterval(this.autoSaveInterval);
                this.autoSaveInterval = null;
            }
        }

        /**
         * Return to Campaign Builder
         */
        returnToCampaignBuilder() {
            // Save state before leaving
            this.saveState();
            this.stopAutoSave();
            
            // Check if journey is complete
            const isComplete = this.isJourneyComplete();
            
            if (isComplete) {
                // Store completion data in sessionStorage
                sessionStorage.setItem('dr_journey_completed', JSON.stringify({
                    success: true,
                    clientId: this.state.clientId,
                    serviceAreaId: this.state.serviceAreaId,
                    circleComplete: true
                }));
            }
            
            // Navigate back
            window.location.href = this.config.campaignBuilderUrl;
        }

        /**
         * Check if journey is complete
         */
        isJourneyComplete() {
            const problems = this.state.selectedProblems || [];
            const solutions = this.state.selectedSolutions || {};
            const offers = this.state.offers || {};
            const totalOffers = Object.values(offers).reduce((sum, arr) => {
                return sum + (Array.isArray(arr) ? arr.length : 0);
            }, 0);

            return problems.length === 5 &&
                   Object.keys(solutions).length >= 5 &&
                   totalOffers > 0;
        }

        /**
         * Show notification
         * Delegates to the shared JCNotifications utility which uses
         * Campaign Builder's notification framework (base.css classes).
         */
        showNotification(message, type = 'info') {
            if (window.JCNotifications) {
                window.JCNotifications.show(type, message);
            } else {
                // Absolute fallback — should never happen if jc-notifications.js loads
                console.warn('[JC Workflow]', type.toUpperCase(), message);
            }
        }

        /**
         * Update state property
         */
        updateState(key, value) {
            // Support both updateState('key', value) and updateState({key: value, ...})
            if (typeof key === 'object' && key !== null && value === undefined) {
                Object.assign(this.state, key);
            } else {
                this.state[key] = value;
            }
            this.saveState();
        }

        /**
         * Reset workflow state to defaults.
         *
         * Called after a service area is deleted so that stale journey data
         * (brain content, problems, solutions, offers, assets, etc.) doesn't
         * bleed into subsequent steps or a new service area selection.
         *
         * Preserves clientId (we're still on the same client).
         * Navigates back to Step 1 and fires jc:stateReset so modules can
         * clear their own internal caches.
         */
        resetState() {
            const clientId = this.config.clientId;

            this.state = {
                clientId: clientId,
                serviceAreaId: null,
                journeyCircleId: null,
                currentStep: 1,
                brainContent: [],
                existingAssets: [],
                industries: [],
                primaryProblemId: null,
                problems: [],
                solutions: [],
                offers: [],
                assets: {},
                selectedProblems: [],
                selectedSolutions: {},
                lastSaved: null
            };

            this.currentStep = 1;
            this.saveState();
            this.updateUI();

            // Show Step 1, hide whatever was visible
            $('.jc-step').hide();
            $('#jc-step-1').show();

            // Let every module know the slate is clean
            $(document).trigger('jc:stateReset');
            $(document).trigger('jc:stepChanged', [1]);

            console.log('[JC Workflow] State reset to defaults');
        }

        /**
         * Get state property
         */
        getState(key) {
            if (key === undefined) {
                return this.state;
            }
            return this.state[key];
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof drJourneyCircleConfig !== 'undefined') {
            window.drJourneyCircle = new JourneyCircleWorkflow(drJourneyCircleConfig);
        }
    });

})(jQuery);