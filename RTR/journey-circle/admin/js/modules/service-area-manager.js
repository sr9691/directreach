/**
 * Service Area Manager - JavaScript Module
 * 
 * Handles service area selection and creation for Journey Circle
 * 
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0
 */

(function($) {
    'use strict';

    class ServiceAreaManager {
        constructor(workflow) {
            this.workflow = workflow;
            this.serviceAreas = [];
            this.selectedServiceAreaId = null;
            
            this.init();
        }

        /**
         * Initialize service area manager
         */
        init() {
            this.bindEvents();
            this.loadServiceAreas();
            
            console.log('Service Area Manager initialized');
        }

        /**
         * Bind event handlers
         */
        bindEvents() {
            // Create service area form
            $('#jc-create-service-area-form').on('submit', (e) => {
                e.preventDefault();
                this.createServiceArea();
            });

            // Select service area (ignore clicks on action buttons)
            $(document).on('click', '.jc-service-area-card', (e) => {
                if ($(e.target).closest('.jc-sa-action-btn').length) {
                    return; // Let the action button handler deal with it
                }
                const serviceAreaId = $(e.currentTarget).data('id');
                this.selectServiceArea(serviceAreaId);
            });

            // Edit service area
            $(document).on('click', '.jc-sa-edit-btn', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const id = $(e.currentTarget).data('id');
                this.openEditModal(id);
            });

            // Delete service area
            $(document).on('click', '.jc-sa-delete-btn', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const id = $(e.currentTarget).data('id');
                this.confirmDeleteServiceArea(id);
            });

            // Edit modal — save
            $(document).on('click', '#jc-sa-edit-save', () => {
                this.saveEditServiceArea();
            });

            // Edit modal — cancel / close
            $(document).on('click', '#jc-sa-edit-cancel, .jc-sa-edit-modal .jc-modal-close', () => {
                this.closeEditModal();
            });

            // Delete confirm modal — confirm
            $(document).on('click', '#jc-sa-delete-confirm', () => {
                this.executeDeleteServiceArea();
            });

            // Delete confirm modal — cancel / close
            $(document).on('click', '#jc-sa-delete-cancel, .jc-sa-delete-modal .jc-modal-close', () => {
                this.closeDeleteModal();
            });

            // Restore state
            $(document).on('jc:restoreState', (e, state) => {
                if (state.serviceAreaId) {
                    this.selectedServiceAreaId = state.serviceAreaId;
                    this.highlightSelectedServiceArea();
                }
            });

            // When entering step 2, load service areas
            $(document).on('jc:stepChanged', (e, step) => {
                if (step === 2) {
                    this.loadServiceAreas();
                }
            });
        }

        /**
         * Load service areas for current client
         */
        async loadServiceAreas() {
            const $list = $('#jc-service-area-list');
            
            try {
                // Show loading state
                $list.html(`
                    <div class="jc-loading-state">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading service areas...</p>
                    </div>
                `);

                // Fetch service areas
                const response = await fetch(
                    `${this.workflow.config.restUrl}/service-areas?client_id=${this.workflow.config.clientId}`,
                    {
                        headers: {
                            'X-WP-Nonce': this.workflow.config.restNonce
                        }
                    }
                );

                if (!response.ok) {
                    throw new Error('Failed to load service areas');
                }

                this.serviceAreas = await response.json();
                this.renderServiceAreaList();

            } catch (error) {
                console.error('Error loading service areas:', error);
                $list.html(`
                    <div class="jc-error-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading service areas. Please try again.</p>
                        <button type="button" class="button button-secondary jc-retry-load">
                            <i class="fas fa-redo"></i> Retry
                        </button>
                    </div>
                `);
                
                $('.jc-retry-load').on('click', () => this.loadServiceAreas());
            }
        }

        /**
         * Render service area list
         */
        renderServiceAreaList() {
            const $list = $('#jc-service-area-list');
            
            if (this.serviceAreas.length === 0) {
                $list.html(`
                    <div class="jc-empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No service areas found</p>
                        <p class="jc-help-text">Create your first service area below to get started.</p>
                    </div>
                `);
                return;
            }

            $list.empty();
            
            this.serviceAreas.forEach(serviceArea => {
                const $card = this.createServiceAreaCard(serviceArea);
                $list.append($card);
            });

            // Highlight selected service area
            this.highlightSelectedServiceArea();
        }

        /**
         * Create service area card HTML
         */
        createServiceAreaCard(serviceArea) {
            const isSelected = serviceArea.id === this.selectedServiceAreaId;
            const hasJourneyCircle = serviceArea.has_journey_circle || false;
            
            return $(`
                <div class="jc-service-area-card ${isSelected ? 'selected' : ''}" 
                     data-id="${serviceArea.id}">
                    <div class="jc-sa-card-header">
                        <h4>${this.escapeHtml(serviceArea.name)}</h4>
                        <div class="jc-sa-card-actions">
                            ${hasJourneyCircle ? '<span class="jc-sa-badge">Has Journey Circle</span>' : ''}
                            <button type="button" class="jc-sa-action-btn jc-sa-edit-btn" 
                                    data-id="${serviceArea.id}" title="Edit">
                                <i class="fas fa-pencil-alt"></i>
                            </button>
                            <button type="button" class="jc-sa-action-btn jc-sa-delete-btn" 
                                    data-id="${serviceArea.id}" title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                    ${serviceArea.description ? `
                        <div class="jc-sa-card-description">
                            ${this.escapeHtml(serviceArea.description)}
                        </div>
                    ` : ''}
                    <div class="jc-sa-card-footer">
                        <span class="jc-sa-status jc-sa-status-${serviceArea.status}">
                            ${this.formatStatus(serviceArea.status)}
                        </span>
                        <span class="jc-sa-date">
                            Created ${this.formatDate(serviceArea.created_at)}
                        </span>
                    </div>
                    ${isSelected ? `
                        <div class="jc-sa-selected-indicator">
                            <i class="fas fa-check-circle"></i> Selected
                        </div>
                    ` : ''}
                </div>
            `);
        }

        /**
         * Create new service area
         */
        async createServiceArea() {
            const $form = $('#jc-create-service-area-form');
            const $submitBtn = $('.jc-create-sa-btn');
            const originalBtnText = $submitBtn.html();
            
            // Get form data
            const name = $('#jc-sa-name').val().trim();
            const description = $('#jc-sa-description').val().trim();

            // Validate
            if (!name) {
                this.workflow.showNotification('Please enter a service area name', 'error');
                return;
            }

            try {
                // Show loading state
                $submitBtn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin"></i> Creating...');

                // Create service area via API
                const response = await fetch(`${this.workflow.config.restUrl}/service-areas`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.workflow.config.restNonce
                    },
                    body: JSON.stringify({
                        client_id: this.workflow.config.clientId,
                        title: name,
                        description: description,
                        status: 'draft'
                    })
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to create service area');
                }

                const newServiceArea = await response.json();
                
                // Add to list
                this.serviceAreas.push(newServiceArea);
                this.renderServiceAreaList();
                
                // Clear form
                $form[0].reset();
                
                // Auto-select the new service area
                this.selectServiceArea(newServiceArea.id);
                
                this.workflow.showNotification('Service area created successfully', 'success');

            } catch (error) {
                console.error('Error creating service area:', error);
                this.workflow.showNotification(`Error: ${error.message}`, 'error');
            } finally {
                $submitBtn.prop('disabled', false).html(originalBtnText);
            }
        }

        /**
         * Select service area
         */
        async selectServiceArea(serviceAreaId) {
            // Detect if switching to a DIFFERENT service area
            const currentSA = this.workflow.getState('serviceAreaId');
            const isSwitching = currentSA && currentSA !== serviceAreaId;

            this.selectedServiceAreaId = serviceAreaId;
            
            // Update UI
            this.highlightSelectedServiceArea();

            // If switching service areas, clear steps 4-11 data from old SA
            if (isSwitching) {
                this.workflow.clearServiceAreaData();
            }
            
            // Save to workflow state
            this.workflow.updateState('serviceAreaId', serviceAreaId);
            
            // Create or load journey circle for this service area
            await this.ensureJourneyCircle(serviceAreaId);
            
            this.workflow.showNotification('Service area selected', 'success');
        }

        /**
         * Ensure journey circle exists for service area
         */
        async ensureJourneyCircle(serviceAreaId) {
            try {
                // Check if journey circle exists
                const response = await fetch(
                    `${this.workflow.config.restUrl}/journey-circles?service_area_id=${serviceAreaId}`,
                    {
                        headers: {
                            'X-WP-Nonce': this.workflow.config.restNonce
                        }
                    }
                );

                if (response.ok) {
                    const journeyCircle = await response.json();
                    
                    if (journeyCircle && journeyCircle.id) {
                        // Journey circle exists, load its data
                        this.loadJourneyCircleData(journeyCircle);
                        return;
                    }
                }

                // Journey circle doesn't exist, create it
                await this.createJourneyCircle(serviceAreaId);

            } catch (error) {
                console.error('Error ensuring journey circle:', error);
            }
        }

        /**
         * Create journey circle for service area
         */
        async createJourneyCircle(serviceAreaId) {
            try {
                const response = await fetch(`${this.workflow.config.restUrl}/journey-circles`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.workflow.config.restNonce
                    },
                    body: JSON.stringify({
                        service_area_id: serviceAreaId,
                        industries: [],
                        brain_content: this.workflow.getState('brainContent') || [],
                        status: 'incomplete'
                    })
                });

                if (!response.ok) {
                    throw new Error('Failed to create journey circle');
                }

                const journeyCircle = await response.json();
                console.log('Journey circle created:', journeyCircle);

                // Store the journey circle ID in workflow state
                if (journeyCircle && journeyCircle.id) {
                    this.workflow.updateState('journeyCircleId', journeyCircle.id);
                    this.loadJourneyCircleData(journeyCircle);
                }

            } catch (error) {
                console.error('Error creating journey circle:', error);
                this.workflow.showNotification('Error creating journey circle', 'error');
            }
        }

        /**
         * Load journey circle data into state
         *
         * FIX: Only overwrite state fields when API returns non-empty data.
         * Previously this unconditionally set state.industries = journeyCircle.industries,
         * which overwrote valid localStorage data with empty arrays from the API
         * (caused by the format_journey_circle PHP bug reading from corrupted taxonomy).
         */
        loadJourneyCircleData(journeyCircle) {
            // Always store journey circle ID
            this.workflow.updateState('journeyCircleId', journeyCircle.id);
            
            // Only overwrite state with API data if it's non-empty.
            // This prevents empty API responses from wiping valid localStorage data.
            if (journeyCircle.industries && Array.isArray(journeyCircle.industries) && journeyCircle.industries.length > 0) {
                this.workflow.updateState('industries', journeyCircle.industries);
            }
            if (journeyCircle.primary_problem_id) {
                this.workflow.updateState('primaryProblemId', journeyCircle.primary_problem_id);
            }
            // Load problems, solutions, offers if they exist
            // This will be expanded as we implement more steps
            
            console.log('Journey circle data loaded:', journeyCircle);
        }

        // ================================================================
        // EDIT SERVICE AREA
        // ================================================================

        /**
         * Open modal to edit a service area
         */
        openEditModal(serviceAreaId) {
            const sa = this.serviceAreas.find(s => s.id === serviceAreaId);
            if (!sa) return;

            this._editingId = serviceAreaId;

            // Remove any existing modal, then create fresh
            $('.jc-sa-edit-modal').remove();

            const modal = $(`
                <div class="jc-modal jc-sa-edit-modal">
                    <div class="jc-modal-content">
                        <div class="jc-modal-header">
                            <h3>Edit Service Area</h3>
                            <button type="button" class="jc-modal-close"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="jc-modal-body">
                            <div class="jc-form-group">
                                <label for="jc-sa-edit-name">Name <span class="required">*</span></label>
                                <input type="text" id="jc-sa-edit-name" class="jc-input"
                                       value="${this.escapeHtml(sa.name)}" maxlength="255" />
                            </div>
                            <div class="jc-form-group">
                                <label for="jc-sa-edit-description">Description</label>
                                <textarea id="jc-sa-edit-description" class="jc-textarea" rows="3">${this.escapeHtml(sa.description || '')}</textarea>
                            </div>
                        </div>
                        <div class="jc-modal-footer">
                            <button type="button" id="jc-sa-edit-cancel" class="btn btn-secondary">Cancel</button>
                            <button type="button" id="jc-sa-edit-save" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            `);

            $('body').append(modal);
            $('#jc-sa-edit-name').focus();
        }

        /**
         * Save edited service area via REST API
         */
        async saveEditServiceArea() {
            const id = this._editingId;
            if (!id) return;

            const name = $('#jc-sa-edit-name').val().trim();
            const description = $('#jc-sa-edit-description').val().trim();

            if (!name) {
                this.workflow.showNotification('Service area name is required', 'error');
                return;
            }

            const $btn = $('#jc-sa-edit-save');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

            try {
                const response = await fetch(`${this.workflow.config.restUrl}/service-areas/${id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.workflow.config.restNonce
                    },
                    body: JSON.stringify({ title: name, description: description })
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to update service area');
                }

                const updated = await response.json();

                // Update local cache
                const idx = this.serviceAreas.findIndex(s => s.id === id);
                if (idx !== -1) {
                    this.serviceAreas[idx] = { ...this.serviceAreas[idx], ...updated };
                }

                this.closeEditModal();
                this.renderServiceAreaList();
                this.workflow.showNotification('Service area updated', 'success');

            } catch (error) {
                console.error('Error updating service area:', error);
                this.workflow.showNotification(`Error: ${error.message}`, 'error');
            } finally {
                $btn.prop('disabled', false).html(originalHtml);
            }
        }

        /**
         * Close the edit modal
         */
        closeEditModal() {
            $('.jc-sa-edit-modal').remove();
            this._editingId = null;
        }

        // ================================================================
        // DELETE SERVICE AREA
        // ================================================================

        /**
         * Show delete confirmation modal
         */
        confirmDeleteServiceArea(serviceAreaId) {
            const sa = this.serviceAreas.find(s => s.id === serviceAreaId);
            if (!sa) return;

            this._deletingId = serviceAreaId;

            // Remove any existing modal, then create fresh
            $('.jc-sa-delete-modal').remove();

            const hasJC = sa.has_journey_circle || false;
            const warningMsg = hasJC
                ? '<p class="jc-delete-warning"><i class="fas fa-exclamation-triangle"></i> This service area has an associated journey circle. Deleting it will also remove the journey circle and all its problems, solutions, and offers.</p>'
                : '';

            const modal = $(`
                <div class="jc-modal jc-sa-delete-modal">
                    <div class="jc-modal-content" style="max-width:480px;">
                        <div class="jc-modal-header">
                            <h3>Delete Service Area</h3>
                            <button type="button" class="jc-modal-close"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="jc-modal-body">
                            <p>Are you sure you want to delete <strong>${this.escapeHtml(sa.name)}</strong>?</p>
                            ${warningMsg}
                            <p style="color: var(--text-color-light, #666); font-size: 13px;">This action cannot be undone.</p>
                        </div>
                        <div class="jc-modal-footer">
                            <button type="button" id="jc-sa-delete-cancel" class="btn btn-secondary">Cancel</button>
                            <button type="button" id="jc-sa-delete-confirm" class="btn btn-danger">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            `);

            $('body').append(modal);
        }

        /**
         * Execute the deletion via REST API
         */
        async executeDeleteServiceArea() {
            const id = this._deletingId;
            if (!id) return;

            const $btn = $('#jc-sa-delete-confirm');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

            try {
                const response = await fetch(`${this.workflow.config.restUrl}/service-areas/${id}?force=true`, {
                    method: 'DELETE',
                    headers: {
                        'X-WP-Nonce': this.workflow.config.restNonce
                    }
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to delete service area');
                }

                // Remove from local cache
                this.serviceAreas = this.serviceAreas.filter(s => s.id !== id);

                // If the deleted SA was selected, reset the entire workflow state
                // so stale data (brain content, problems, solutions, etc.) doesn't
                // persist into later steps or a new SA selection.
                if (this.selectedServiceAreaId === id) {
                    this.selectedServiceAreaId = null;
                    this.workflow.resetState();
                }

                this.closeDeleteModal();
                this.renderServiceAreaList();

                // Navigate to Step 2 so the user can pick / create a new SA
                this.workflow.goToStep(2, true);

                this.workflow.showNotification('Service area and all related data deleted', 'success');

            } catch (error) {
                console.error('Error deleting service area:', error);
                this.workflow.showNotification(`Error: ${error.message}`, 'error');
            } finally {
                $btn.prop('disabled', false).html(originalHtml);
            }
        }

        /**
         * Close the delete confirmation modal
         */
        closeDeleteModal() {
            $('.jc-sa-delete-modal').remove();
            this._deletingId = null;
        }

        /**
         * Highlight selected service area
         */
        highlightSelectedServiceArea() {
            $('.jc-service-area-card').removeClass('selected');
            $(`.jc-service-area-card[data-id="${this.selectedServiceAreaId}"]`).addClass('selected');
        }

        /**
         * Format status
         */
        formatStatus(status) {
            const statusMap = {
                'active': 'Active',
                'draft': 'Draft',
                'archived': 'Archived'
            };
            return statusMap[status] || status;
        }

        /**
         * Format date
         */
        formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffTime = Math.abs(now - date);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays === 0) {
                return 'today';
            } else if (diffDays === 1) {
                return 'yesterday';
            } else if (diffDays < 7) {
                return `${diffDays} days ago`;
            } else if (diffDays < 30) {
                return `${Math.floor(diffDays / 7)} weeks ago`;
            } else {
                return date.toLocaleDateString();
            }
        }

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialize when workflow is ready
    $(document).ready(function() {
        if (window.drJourneyCircle) {
            window.drServiceAreaManager = new ServiceAreaManager(window.drJourneyCircle);
        }
    });

})(jQuery);