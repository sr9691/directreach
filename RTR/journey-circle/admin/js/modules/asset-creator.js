/**
 * Asset Creator Module - Iteration 9
 * 
 * Handles Step 9 of the Journey Circle Creator workflow:
 * - Format selection (Long Article, Short Article, Infographic)
 * - Outline generation with feedback loop
 * - Content generation with feedback loop
 * - Download and approval
 * 
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 */

(function() {
    'use strict';

    /**
     * Asset Creator Class
     * Manages the complete Step 9 asset creation workflow.
     */
    class AssetCreator {
        /**
         * Initialize the Asset Creator.
         * 
         * @param {Object} config Configuration object.
         * @param {string} config.containerSelector Selector for the step container.
         * @param {string} config.apiNamespace REST API namespace.
         * @param {string} config.nonce WordPress nonce for API calls.
         */
        constructor(config = {}) {
            this.config = {
                containerSelector: '#step-9-container',
                apiNamespace: '/wp-json/directreach/v2',
                nonce: window.drJourneyCircle?.nonce || '',
                ...config
            };

            this.container = null;
            this.currentPhase = 'select-item'; // select-item, format, outline, content, download
            this.currentItem = null; // { type: 'problem'|'solution', id: number, title: string }
            this.currentAsset = null; // Asset record from database
            this.selectedFormat = null;
            this.currentOutline = '';
            this.currentContent = '';
            this.abortController = null;
            this.feedbackHistory = [];

            // Bind methods
            this.init = this.init.bind(this);
            this.render = this.render.bind(this);
            this.handleFormatSelect = this.handleFormatSelect.bind(this);
            this.handleGenerateOutline = this.handleGenerateOutline.bind(this);
            this.handleReviseOutline = this.handleReviseOutline.bind(this);
            this.handleApproveOutline = this.handleApproveOutline.bind(this);
            this.handleGenerateContent = this.handleGenerateContent.bind(this);
            this.handleReviseContent = this.handleReviseContent.bind(this);
            this.handleApproveContent = this.handleApproveContent.bind(this);
            this.handleDownload = this.handleDownload.bind(this);
        }

        /**
         * Initialize the module.
         */
        init() {
            this.container = document.querySelector(this.config.containerSelector);
            if (!this.container) {
                console.error('AssetCreator: Container not found', this.config.containerSelector);
                return;
            }

            this.bindEvents();
            this.render();

            // Dispatch ready event
            document.dispatchEvent(new CustomEvent('assetCreator:ready', { detail: this }));
        }

        /**
         * Bind event listeners.
         */
        bindEvents() {
            // Use event delegation for dynamic content
            this.container.addEventListener('click', (e) => {
                // Format selection
                if (e.target.closest('.format-card')) {
                    const card = e.target.closest('.format-card');
                    this.handleFormatSelect(card.dataset.format);
                }

                // Item selection
                if (e.target.closest('.asset-item-card')) {
                    const card = e.target.closest('.asset-item-card');
                    this.handleItemSelect(card.dataset.type, parseInt(card.dataset.id), card.dataset.title);
                }

                // Action buttons
                if (e.target.closest('.btn-generate-outline')) {
                    this.handleGenerateOutline();
                }
                if (e.target.closest('.btn-revise-outline')) {
                    this.handleReviseOutline();
                }
                if (e.target.closest('.btn-approve-outline')) {
                    this.handleApproveOutline();
                }
                if (e.target.closest('.btn-generate-content')) {
                    this.handleGenerateContent();
                }
                if (e.target.closest('.btn-revise-content')) {
                    this.handleReviseContent();
                }
                if (e.target.closest('.btn-approve-content')) {
                    this.handleApproveContent();
                }
                if (e.target.closest('.btn-download')) {
                    this.handleDownload();
                }
                if (e.target.closest('.btn-back-to-items')) {
                    this.resetToItemSelection();
                }
                if (e.target.closest('.btn-cancel-generation')) {
                    this.cancelGeneration();
                }
            });
        }

        /**
         * Render the current phase UI.
         */
        render() {
            switch (this.currentPhase) {
                case 'select-item':
                    this.renderItemSelection();
                    break;
                case 'format':
                    this.renderFormatSelection();
                    break;
                case 'outline':
                    this.renderOutlinePhase();
                    break;
                case 'content':
                    this.renderContentPhase();
                    break;
                case 'download':
                    this.renderDownloadPhase();
                    break;
                default:
                    this.renderItemSelection();
            }
        }

        /**
         * Render the item selection phase (problems/solutions to create content for).
         */
        renderItemSelection() {
            const problems = this.getProblems();
            const solutions = this.getSolutions();
            const assets = this.getExistingAssets();

            const html = `
                <div class="asset-creator-phase phase-select-item">
                    <div class="phase-header">
                        <h3>Create Content Assets</h3>
                        <p>Select a problem or solution to create content for.</p>
                    </div>

                    <div class="asset-progress-tracker">
                        ${this.renderProgressTracker(problems, solutions, assets)}
                    </div>

                    <div class="item-selection-grid">
                        <div class="item-section">
                            <h4><span class="ring-indicator ring-problem"></span> Problems</h4>
                            <div class="item-cards">
                                ${problems.map((p, i) => this.renderItemCard('problem', p, i, assets)).join('')}
                            </div>
                        </div>
                        
                        <div class="item-section">
                            <h4><span class="ring-indicator ring-solution"></span> Solutions</h4>
                            <div class="item-cards">
                                ${solutions.map((s, i) => this.renderItemCard('solution', s, i, assets)).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;

            this.container.innerHTML = html;
        }

        /**
         * Render an item card.
         */
        renderItemCard(type, item, index, assets) {
            const hasAsset = assets.some(a => 
                a.linked_to_type === type && 
                a.linked_to_id === item.id &&
                (a.status === 'approved' || a.status === 'published')
            );
            const inProgress = assets.some(a => 
                a.linked_to_type === type && 
                a.linked_to_id === item.id &&
                (a.status === 'outline' || a.status === 'draft')
            );

            let statusBadge = '';
            let statusClass = '';
            if (hasAsset) {
                statusBadge = '<span class="status-badge status-complete">✓ Complete</span>';
                statusClass = 'complete';
            } else if (inProgress) {
                statusBadge = '<span class="status-badge status-progress">In Progress</span>';
                statusClass = 'in-progress';
            }

            return `
                <div class="asset-item-card ${statusClass}" 
                     data-type="${type}" 
                     data-id="${item.id}" 
                     data-title="${this.escapeHtml(item.title)}">
                    <div class="item-number">${index + 1}</div>
                    <div class="item-content">
                        <div class="item-title">${this.escapeHtml(item.title)}</div>
                        ${statusBadge}
                    </div>
                    <div class="item-action">
                        <span class="action-icon">→</span>
                    </div>
                </div>
            `;
        }

        /**
         * Render progress tracker.
         */
        renderProgressTracker(problems, solutions, assets) {
            const problemsWithAssets = problems.filter(p => 
                assets.some(a => a.linked_to_type === 'problem' && a.linked_to_id === p.id && 
                    (a.status === 'approved' || a.status === 'published'))
            ).length;
            const solutionsWithAssets = solutions.filter(s => 
                assets.some(a => a.linked_to_type === 'solution' && a.linked_to_id === s.id && 
                    (a.status === 'approved' || a.status === 'published'))
            ).length;

            const totalItems = problems.length + solutions.length;
            const completedItems = problemsWithAssets + solutionsWithAssets;
            const percentage = totalItems > 0 ? Math.round((completedItems / totalItems) * 100) : 0;

            return `
                <div class="progress-bar-container">
                    <div class="progress-label">
                        <span>Asset Creation Progress</span>
                        <span class="progress-count">${completedItems} of ${totalItems} items</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${percentage}%"></div>
                    </div>
                </div>
            `;
        }

        /**
         * Handle item selection.
         */
        handleItemSelect(type, id, title) {
            this.currentItem = { type, id, title };
            this.currentPhase = 'format';
            this.selectedFormat = null;
            this.currentOutline = '';
            this.currentContent = '';
            this.currentAsset = null;
            this.feedbackHistory = [];

            // Check if there's an existing asset for this item
            const assets = this.getExistingAssets();
            const existing = assets.find(a => 
                a.linked_to_type === type && a.linked_to_id === id
            );

            if (existing) {
                this.currentAsset = existing;
                this.selectedFormat = existing.asset_type;
                this.currentOutline = existing.outline || '';
                this.currentContent = existing.content || '';

                // Determine which phase to show based on status
                if (existing.status === 'approved' || existing.status === 'published') {
                    this.currentPhase = 'download';
                } else if (existing.content) {
                    this.currentPhase = 'content';
                } else if (existing.outline) {
                    this.currentPhase = 'outline';
                }
            }

            this.render();
        }

        /**
         * Render format selection phase.
         */
        renderFormatSelection() {
            const html = `
                <div class="asset-creator-phase phase-format">
                    <div class="phase-header">
                        <button class="btn btn-link btn-back-to-items">
                            <span class="back-icon">←</span> Back to Items
                        </button>
                        <h3>Select Content Format</h3>
                        <p>Creating content for: <strong>${this.escapeHtml(this.currentItem.title)}</strong></p>
                    </div>

                    <div class="format-selection-grid">
                        <div class="format-card ${this.selectedFormat === 'article_long' ? 'selected' : ''}" 
                             data-format="article_long">
                            <div class="format-icon">📝</div>
                            <div class="format-name">Long Article</div>
                            <div class="format-desc">2000-3000 words<br>In-depth analysis & insights</div>
                        </div>
                        
                        <div class="format-card ${this.selectedFormat === 'article_short' ? 'selected' : ''}" 
                             data-format="article_short">
                            <div class="format-icon">📄</div>
                            <div class="format-name">Short Article</div>
                            <div class="format-desc">800-1200 words<br>Quick insights & takeaways</div>
                        </div>
                        
                        <div class="format-card ${this.selectedFormat === 'infographic' ? 'selected' : ''}" 
                             data-format="infographic">
                            <div class="format-icon">📊</div>
                            <div class="format-name">Infographic</div>
                            <div class="format-desc">Visual content<br>Key points & statistics</div>
                        </div>
                    </div>

                    <div class="phase-actions">
                        <button class="btn btn-primary btn-generate-outline" 
                                ${!this.selectedFormat ? 'disabled' : ''}>
                            Generate Outline
                        </button>
                    </div>
                </div>
            `;

            this.container.innerHTML = html;
        }

        /**
         * Handle format selection.
         */
        handleFormatSelect(format) {
            this.selectedFormat = format;
            
            // Update UI
            this.container.querySelectorAll('.format-card').forEach(card => {
                card.classList.toggle('selected', card.dataset.format === format);
            });

            // Enable generate button
            const btn = this.container.querySelector('.btn-generate-outline');
            if (btn) {
                btn.disabled = false;
            }
        }

        /**
         * Render outline phase.
         */
        renderOutlinePhase() {
            const hasOutline = !!this.currentOutline;

            const html = `
                <div class="asset-creator-phase phase-outline">
                    <div class="phase-header">
                        <button class="btn btn-link btn-back-to-items">
                            <span class="back-icon">←</span> Back to Items
                        </button>
                        <div class="phase-breadcrumb">
                            <span class="breadcrumb-item">Format: ${this.getFormatLabel(this.selectedFormat)}</span>
                            <span class="breadcrumb-separator">→</span>
                            <span class="breadcrumb-item active">Outline</span>
                        </div>
                        <h3>Content Outline</h3>
                        <p>Creating: <strong>${this.escapeHtml(this.currentItem.title)}</strong></p>
                    </div>

                    <div class="outline-container">
                        ${hasOutline ? `
                            <div class="outline-preview">
                                <div class="outline-content">
                                    ${this.formatOutlineForDisplay(this.currentOutline)}
                                </div>
                            </div>

                            <div class="feedback-section">
                                <h4>Provide Feedback</h4>
                                <p>Not satisfied? Tell us what to change:</p>
                                <textarea id="outline-feedback" 
                                          class="feedback-input" 
                                          placeholder="E.g., 'Make it more technical', 'Add a section about ROI', 'Shorten the introduction'..."
                                          rows="3"></textarea>
                                <div class="feedback-actions">
                                    <button class="btn btn-secondary btn-revise-outline">
                                        Revise Outline
                                    </button>
                                    <button class="btn btn-primary btn-approve-outline">
                                        Approve & Continue
                                    </button>
                                </div>
                            </div>
                        ` : `
                            <div class="outline-placeholder">
                                <div class="loading-indicator">
                                    <div class="spinner"></div>
                                    <p>Generating outline...</p>
                                    <p class="loading-hint">This may take up to 15 seconds</p>
                                    <button class="btn btn-link btn-cancel-generation">Cancel</button>
                                </div>
                            </div>
                        `}
                    </div>
                </div>
            `;

            this.container.innerHTML = html;
        }

        /**
         * Handle generate outline.
         */
        async handleGenerateOutline() {
            this.currentPhase = 'outline';
            this.render();

            this.abortController = new AbortController();

            try {
                const workflowState = this.getWorkflowState();
                
                const response = await this.apiRequest('/ai/generate-outline', {
                    method: 'POST',
                    body: JSON.stringify({
                        journey_circle_id: workflowState.journeyCircleId,
                        linked_to_type: this.currentItem.type,
                        linked_to_id: this.currentItem.id,
                        asset_type: this.selectedFormat,
                        brain_content: workflowState.brainContent || [],
                        industries: workflowState.industries || [],
                        service_area_name: workflowState.serviceAreaName || '',
                        problem_title: this.currentItem.type === 'problem' 
                            ? this.currentItem.title 
                            : this.getProblemForSolution(this.currentItem.id)?.title || '',
                        solution_title: this.currentItem.type === 'solution' 
                            ? this.currentItem.title 
                            : '',
                    }),
                    signal: this.abortController.signal,
                });

                if (response.success) {
                    this.currentOutline = response.outline;
                    this.currentAsset = {
                        id: response.asset_id,
                        title: response.title,
                        outline: response.outline,
                        status: 'outline'
                    };
                    this.render();

                    // Dispatch event
                    document.dispatchEvent(new CustomEvent('assetCreator:outlineGenerated', {
                        detail: { asset: this.currentAsset, item: this.currentItem }
                    }));
                } else {
                    this.showError(response.message || 'Failed to generate outline');
                    this.currentPhase = 'format';
                    this.render();
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    console.log('Outline generation cancelled');
                } else {
                    console.error('Generate outline error:', error);
                    this.showError('Failed to generate outline. Please try again.');
                }
                this.currentPhase = 'format';
                this.render();
            }

            this.abortController = null;
        }

        /**
         * Handle revise outline.
         */
        async handleReviseOutline() {
            const feedback = document.getElementById('outline-feedback')?.value?.trim();
            if (!feedback) {
                this.showError('Please provide feedback for the revision.');
                return;
            }

            this.feedbackHistory.push({ type: 'outline', feedback, timestamp: Date.now() });

            // Show loading state
            const btn = this.container.querySelector('.btn-revise-outline');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-small"></span> Revising...';
            }

            this.abortController = new AbortController();

            try {
                const response = await this.apiRequest('/ai/revise-outline', {
                    method: 'POST',
                    body: JSON.stringify({
                        asset_id: this.currentAsset.id,
                        current_outline: this.currentOutline,
                        feedback: feedback,
                    }),
                    signal: this.abortController.signal,
                });

                if (response.success) {
                    this.currentOutline = response.outline;
                    if (response.title) {
                        this.currentAsset.title = response.title;
                    }
                    this.render();

                    // Show revision note if available
                    if (response.revision_notes) {
                        this.showSuccess(`Outline revised: ${response.revision_notes}`);
                    }
                } else {
                    this.showError(response.message || 'Failed to revise outline');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Revise Outline';
                    }
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Revise outline error:', error);
                    this.showError('Failed to revise outline. Please try again.');
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Revise Outline';
                }
            }

            this.abortController = null;
        }

        /**
         * Handle approve outline.
         */
        async handleApproveOutline() {
            this.currentPhase = 'content';
            this.render();
            
            // Automatically start content generation
            await this.handleGenerateContent();
        }

        /**
         * Render content phase.
         */
        renderContentPhase() {
            const hasContent = !!this.currentContent;

            const html = `
                <div class="asset-creator-phase phase-content">
                    <div class="phase-header">
                        <button class="btn btn-link btn-back-to-items">
                            <span class="back-icon">←</span> Back to Items
                        </button>
                        <div class="phase-breadcrumb">
                            <span class="breadcrumb-item">Format: ${this.getFormatLabel(this.selectedFormat)}</span>
                            <span class="breadcrumb-separator">→</span>
                            <span class="breadcrumb-item">Outline ✓</span>
                            <span class="breadcrumb-separator">→</span>
                            <span class="breadcrumb-item active">Content</span>
                        </div>
                        <h3>Generated Content</h3>
                        <p>Creating: <strong>${this.escapeHtml(this.currentItem.title)}</strong></p>
                    </div>

                    <div class="content-container">
                        ${hasContent ? `
                            <div class="content-preview">
                                <div class="content-title">${this.escapeHtml(this.currentAsset?.title || '')}</div>
                                <div class="content-body">
                                    ${this.currentContent}
                                </div>
                            </div>

                            <div class="feedback-section">
                                <h4>Provide Feedback</h4>
                                <p>Need changes? Tell us what to revise:</p>
                                <textarea id="content-feedback" 
                                          class="feedback-input" 
                                          placeholder="E.g., 'Make it more conversational', 'Add more examples', 'Expand the conclusion'..."
                                          rows="3"></textarea>
                                <div class="feedback-actions">
                                    <button class="btn btn-secondary btn-revise-content">
                                        Revise Content
                                    </button>
                                    <button class="btn btn-primary btn-approve-content">
                                        Approve & Finish
                                    </button>
                                </div>
                            </div>
                        ` : `
                            <div class="content-placeholder">
                                <div class="loading-indicator">
                                    <div class="spinner"></div>
                                    <p>Generating content...</p>
                                    <p class="loading-hint">This may take up to 30 seconds</p>
                                    <button class="btn btn-link btn-cancel-generation">Cancel</button>
                                </div>
                            </div>
                        `}
                    </div>
                </div>
            `;

            this.container.innerHTML = html;
        }

        /**
         * Handle generate content.
         */
        async handleGenerateContent() {
            this.abortController = new AbortController();

            try {
                const response = await this.apiRequest('/ai/generate-content', {
                    method: 'POST',
                    body: JSON.stringify({
                        asset_id: this.currentAsset.id,
                        outline: this.currentOutline,
                    }),
                    signal: this.abortController.signal,
                    timeout: 60000, // 60 second timeout for content
                });

                if (response.success) {
                    this.currentContent = response.content;
                    this.currentAsset.content = response.content;
                    this.currentAsset.status = 'draft';
                    this.render();

                    document.dispatchEvent(new CustomEvent('assetCreator:contentGenerated', {
                        detail: { asset: this.currentAsset, item: this.currentItem }
                    }));
                } else {
                    this.showError(response.message || 'Failed to generate content');
                    this.currentPhase = 'outline';
                    this.render();
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    console.log('Content generation cancelled');
                } else {
                    console.error('Generate content error:', error);
                    this.showError('Failed to generate content. Please try again.');
                }
                this.currentPhase = 'outline';
                this.render();
            }

            this.abortController = null;
        }

        /**
         * Handle revise content.
         */
        async handleReviseContent() {
            const feedback = document.getElementById('content-feedback')?.value?.trim();
            if (!feedback) {
                this.showError('Please provide feedback for the revision.');
                return;
            }

            this.feedbackHistory.push({ type: 'content', feedback, timestamp: Date.now() });

            const btn = this.container.querySelector('.btn-revise-content');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-small"></span> Revising...';
            }

            this.abortController = new AbortController();

            try {
                const response = await this.apiRequest('/ai/revise-content', {
                    method: 'POST',
                    body: JSON.stringify({
                        asset_id: this.currentAsset.id,
                        current_content: this.currentContent,
                        feedback: feedback,
                    }),
                    signal: this.abortController.signal,
                    timeout: 60000,
                });

                if (response.success) {
                    this.currentContent = response.content;
                    this.currentAsset.content = response.content;
                    this.render();
                    this.showSuccess('Content revised successfully.');
                } else {
                    this.showError(response.message || 'Failed to revise content');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Revise Content';
                    }
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Revise content error:', error);
                    this.showError('Failed to revise content. Please try again.');
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Revise Content';
                }
            }

            this.abortController = null;
        }

        /**
         * Handle approve content.
         */
        async handleApproveContent() {
            const btn = this.container.querySelector('.btn-approve-content');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-small"></span> Approving...';
            }

            try {
                const workflowState = this.getWorkflowState();
                
                const response = await this.apiRequest(
                    `/journey-circles/${workflowState.journeyCircleId}/assets/${this.currentAsset.id}/approve`,
                    { method: 'POST' }
                );

                if (response.success) {
                    this.currentAsset.status = 'approved';
                    this.currentPhase = 'download';
                    this.render();

                    // Update workflow state
                    this.updateWorkflowAssets();

                    document.dispatchEvent(new CustomEvent('assetCreator:contentApproved', {
                        detail: { asset: this.currentAsset, item: this.currentItem }
                    }));
                } else {
                    this.showError(response.message || 'Failed to approve content');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Approve & Finish';
                    }
                }
            } catch (error) {
                console.error('Approve content error:', error);
                this.showError('Failed to approve content. Please try again.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Approve & Finish';
                }
            }
        }

        /**
         * Render download phase.
         */
        renderDownloadPhase() {
            const html = `
                <div class="asset-creator-phase phase-download">
                    <div class="phase-header">
                        <button class="btn btn-link btn-back-to-items">
                            <span class="back-icon">←</span> Back to Items
                        </button>
                        <div class="phase-breadcrumb">
                            <span class="breadcrumb-item">Format: ${this.getFormatLabel(this.selectedFormat)}</span>
                            <span class="breadcrumb-separator">→</span>
                            <span class="breadcrumb-item">Outline ✓</span>
                            <span class="breadcrumb-separator">→</span>
                            <span class="breadcrumb-item">Content ✓</span>
                            <span class="breadcrumb-separator">→</span>
                            <span class="breadcrumb-item active">Complete</span>
                        </div>
                        <h3>Content Ready!</h3>
                    </div>

                    <div class="download-container">
                        <div class="success-message">
                            <div class="success-icon">✓</div>
                            <h4>Content Created Successfully</h4>
                            <p>${this.escapeHtml(this.currentAsset?.title || this.currentItem.title)}</p>
                        </div>

                        <div class="content-preview-mini">
                            <div class="preview-header">Preview</div>
                            <div class="preview-body">
                                ${this.currentContent.substring(0, 500)}...
                            </div>
                        </div>

                        <div class="download-actions">
                            <button class="btn btn-primary btn-download">
                                <span class="download-icon">↓</span> Download HTML
                            </button>
                        </div>

                        <div class="next-steps">
                            <p>Next: Publish this content and paste the URL in Step 10.</p>
                            <button class="btn btn-secondary btn-back-to-items">
                                Create Another Asset
                            </button>
                        </div>
                    </div>
                </div>
            `;

            this.container.innerHTML = html;
        }

        /**
         * Handle download.
         */
        handleDownload() {
            const title = this.currentAsset?.title || this.currentItem.title;
            const filename = this.slugify(title) + '.html';

            const fullHtml = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${this.escapeHtml(title)}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 20px; color: #333; }
        h1, h2, h3 { color: #1a1a1a; }
        h1 { font-size: 2em; margin-bottom: 0.5em; }
        h2 { font-size: 1.5em; margin-top: 1.5em; }
        h3 { font-size: 1.2em; }
        p { margin: 1em 0; }
        ul, ol { margin: 1em 0; padding-left: 2em; }
        li { margin: 0.5em 0; }
        blockquote { border-left: 4px solid #ddd; margin: 1em 0; padding-left: 1em; color: #666; }
    </style>
</head>
<body>
    <h1>${this.escapeHtml(title)}</h1>
    ${this.currentContent}
</body>
</html>`;

            const blob = new Blob([fullHtml], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            this.showSuccess('Content downloaded successfully!');
        }

        /**
         * Reset to item selection.
         */
        resetToItemSelection() {
            this.currentPhase = 'select-item';
            this.currentItem = null;
            this.currentAsset = null;
            this.selectedFormat = null;
            this.currentOutline = '';
            this.currentContent = '';
            this.feedbackHistory = [];
            this.render();
        }

        /**
         * Cancel ongoing generation.
         */
        cancelGeneration() {
            if (this.abortController) {
                this.abortController.abort();
            }
            this.currentPhase = 'format';
            this.render();
        }

        // ========================================================================
        // HELPER METHODS
        // ========================================================================

        /**
         * Make API request.
         */
        async apiRequest(endpoint, options = {}) {
            const url = this.config.apiNamespace + endpoint;
            
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.config.nonce,
                },
            };

            const timeout = options.timeout || 30000;
            delete options.timeout;

            const mergedOptions = { ...defaultOptions, ...options };

            // Create timeout promise
            const timeoutPromise = new Promise((_, reject) => {
                setTimeout(() => reject(new Error('Request timeout')), timeout);
            });

            const fetchPromise = fetch(url, mergedOptions);

            const response = await Promise.race([fetchPromise, timeoutPromise]);
            
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.message || `HTTP ${response.status}`);
            }

            return response.json();
        }

        /**
         * Get workflow state from the main workflow manager.
         */
        getWorkflowState() {
            // Try to get from global workflow instance
            if (window.journeyCircleWorkflow) {
                return window.journeyCircleWorkflow.getState();
            }
            
            // Fallback to localStorage
            const stored = localStorage.getItem('dr_journey_circle_state');
            return stored ? JSON.parse(stored) : {};
        }

        /**
         * Get problems from workflow state.
         */
        getProblems() {
            const state = this.getWorkflowState();
            return state.selectedProblems || [];
        }

        /**
         * Get solutions from workflow state.
         */
        getSolutions() {
            const state = this.getWorkflowState();
            return state.selectedSolutions || [];
        }

        /**
         * Get existing assets from workflow state or API.
         */
        getExistingAssets() {
            const state = this.getWorkflowState();
            return state.assets || [];
        }

        /**
         * Get problem for a solution.
         */
        getProblemForSolution(solutionId) {
            const solutions = this.getSolutions();
            const solution = solutions.find(s => s.id === solutionId);
            if (solution && solution.problem_id) {
                const problems = this.getProblems();
                return problems.find(p => p.id === solution.problem_id);
            }
            return null;
        }

        /**
         * Update workflow assets after approval.
         */
        updateWorkflowAssets() {
            if (window.journeyCircleWorkflow && this.currentAsset) {
                window.journeyCircleWorkflow.addAsset(this.currentAsset);
            }
        }

        /**
         * Get format label.
         */
        getFormatLabel(format) {
            const labels = {
                'article_long': 'Long Article',
                'article_short': 'Short Article',
                'infographic': 'Infographic',
            };
            return labels[format] || format;
        }

        /**
         * Format outline for display.
         */
        formatOutlineForDisplay(outline) {
            // Convert markdown-style formatting to HTML
            let html = outline
                .replace(/^# (.+)$/gm, '<h2 class="outline-title">$1</h2>')
                .replace(/^## (.+)$/gm, '<h3 class="outline-section">$1</h3>')
                .replace(/^### (\d+\. .+)$/gm, '<h4 class="outline-subsection">$1</h4>')
                .replace(/^\*\*(.+?):\*\* (.+)$/gm, '<p><strong>$1:</strong> $2</p>')
                .replace(/^- (.+)$/gm, '<li>$1</li>')
                .replace(/^📊 \*\*(.+?):\*\* (.+)$/gm, '<p class="outline-stat"><span class="stat-icon">📊</span> <strong>$1:</strong> $2</p>')
                .replace(/\*\((.+?)\)\*/g, '<span class="word-count">($1)</span>')
                .replace(/---/g, '<hr>')
                .replace(/\n\n/g, '</p><p>');

            // Wrap loose li elements in ul
            html = html.replace(/(<li>.*?<\/li>)+/g, '<ul>$&</ul>');

            return `<div class="outline-formatted">${html}</div>`;
        }

        /**
         * Show error notification.
         */
        showError(message) {
            this.showNotification(message, 'error');
        }

        /**
         * Show success notification.
         */
        showSuccess(message) {
            this.showNotification(message, 'success');
        }

        /**
         * Show notification.
         * Delegates to the shared JCNotifications utility which uses
         * Campaign Builder's notification framework (base.css classes).
         */
        showNotification(message, type = 'info') {
            if (window.JCNotifications) {
                window.JCNotifications.show(type, message);
            } else {
                console.warn('[AssetCreator]', type.toUpperCase(), message);
            }
        }

        /**
         * Escape HTML entities.
         */
        escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        /**
         * Slugify a string.
         */
        slugify(str) {
            return str
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .substring(0, 50);
        }
    }

    // Export to global scope
    window.AssetCreator = AssetCreator;

    // Auto-initialize when DOM is ready and we're on Step 9
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.querySelector('#step-9-container');
        if (container && window.drJourneyCircle) {
            window.assetCreator = new AssetCreator({
                containerSelector: '#step-9-container',
                apiNamespace: window.drJourneyCircle.apiBase || '/wp-json/directreach/v2',
                nonce: window.drJourneyCircle.nonce,
            });
            window.assetCreator.init();
        }
    });

})();