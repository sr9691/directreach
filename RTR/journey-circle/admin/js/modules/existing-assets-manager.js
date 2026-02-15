/**
 * Existing Assets Manager
 * 
 * Manages Step 3: Upload Existing Assets
 * - File upload via click and drag-and-drop
 * - Asset list display
 * - Asset deletion
 * 
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0
 */

(function($) {
    'use strict';

    class ExistingAssetsManager {
        constructor(workflow) {
            this.workflow = workflow;
            this.assets = [];
            this.allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/html', 'image/jpeg', 'image/png', 'image/gif'];
            this.maxFileSize = 10 * 1024 * 1024; // 10MB

            this.init();
        }

        init() {
            this.bindEvents();
            this.loadFromWorkflow();
            console.log('Existing Assets Manager initialized');
        }

        bindEvents() {
            // File input change
            $('#jc-asset-input').on('change', (e) => this.handleFileSelect(e));

            // Click on upload area should trigger file input
            $('#jc-asset-upload-area').on('click', (e) => {
                // Don't trigger if clicking on the input itself
                if (e.target.id !== 'jc-asset-input') {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#jc-asset-input').trigger('click');
                }
            });

            // Drag and drop
            this.initDragDrop('#jc-asset-upload-area');

            // Delete asset (delegated)
            $(document).on('click', '.jc-asset-delete', (e) => {
                e.preventDefault();
                const index = $(e.currentTarget).data('index');
                this.deleteAsset(index);
            });

            // Restore state
            $(document).on('jc:restoreState', (e, state) => {
                if (state.existingAssets && state.existingAssets.length > 0) {
                    this.assets = state.existingAssets;
                    this.renderAssetList();
                    this.updateAssetCount();
                }
            });
        }

        /**
         * Handle file selection from input
         */
        handleFileSelect(event) {
            const files = event.target.files;

            if (!files || files.length === 0) {
                return;
            }

            Array.from(files).forEach(file => {
                this.uploadFile(file);
            });

            // Reset input so same file can be selected again
            event.target.value = '';
        }

        /**
         * Upload a file - uses REST media for uploadable types, reads HTML/text inline
         */
        async uploadFile(file) {
            // Validate file
            const validation = this.validateFile(file);
            if (!validation.valid) {
                this.workflow.showNotification(validation.message, 'error');
                return;
            }

            // HTML files can't be uploaded to WordPress media library (security restriction)
            // Read them as text and store the content inline
            const ext = file.name.split('.').pop().toLowerCase();
            if (['html', 'htm', 'txt'].includes(ext)) {
                return this.handleTextFile(file);
            }

            // Show loading state
            const loadingId = this.addLoadingAsset(file.name);

            try {
                // Use WordPress REST API media endpoint
                const formData = new FormData();
                formData.append('file', file);
                formData.append('title', file.name);

                // Get the base REST URL - strip custom namespace to get wp-json base
                let restBase = this.workflow.config.restUrl;
                const wpJsonIdx = restBase.indexOf('/wp-json/');
                if (wpJsonIdx !== -1) {
                    restBase = restBase.substring(0, wpJsonIdx + '/wp-json'.length);
                }
                const mediaUrl = restBase + '/wp/v2/media';

                console.log('Uploading asset to:', mediaUrl);

                const response = await fetch(mediaUrl, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': this.workflow.config.restNonce
                    },
                    body: formData
                });

                // Remove loading state
                this.removeLoadingAsset(loadingId);

                if (response.ok) {
                    const data = await response.json();

                    // Add uploaded file to assets
                    this.assets.push({
                        type: 'file',
                        value: data.source_url || data.guid?.rendered || '',
                        name: file.name,
                        fileId: data.id,
                        size: file.size,
                        mimeType: file.type,
                        addedAt: new Date().toISOString()
                    });

                    this.renderAssetList();
                    this.updateAssetCount();
                    this.saveToWorkflow();

                    this.workflow.showNotification('Asset "' + file.name + '" uploaded successfully', 'success');
                } else {
                    let errorMsg = 'Upload failed (HTTP ' + response.status + ')';
                    try {
                        const errData = await response.json();
                        errorMsg = (errData.message || errorMsg);
                    } catch(e) {}
                    throw new Error(errorMsg);
                }

            } catch (error) {
                this.removeLoadingAsset(loadingId);
                this.workflow.showNotification('Error uploading "' + file.name + '": ' + error.message, 'error');
                console.error('Asset upload error:', error);
            }
        }

        /**
         * Handle HTML/TXT files by reading content inline
         * WordPress blocks HTML uploads to the media library for security (XSS prevention)
         */
        async handleTextFile(file) {
            const loadingId = this.addLoadingAsset(file.name);

            try {
                const content = await file.text();

                this.removeLoadingAsset(loadingId);

                this.assets.push({
                    type: 'html_content',
                    value: '', // No media URL for inline content
                    name: file.name,
                    fileId: null,
                    size: file.size,
                    mimeType: file.type || 'text/html',
                    content: content, // Store the actual file content
                    addedAt: new Date().toISOString()
                });

                this.renderAssetList();
                this.updateAssetCount();
                this.saveToWorkflow();

                this.workflow.showNotification('Asset "' + file.name + '" added successfully (stored as content)', 'success');
            } catch (error) {
                this.removeLoadingAsset(loadingId);
                this.workflow.showNotification('Error reading "' + file.name + '": ' + error.message, 'error');
                console.error('Text file read error:', error);
            }
        }

        /**
         * Validate file before upload
         */
        validateFile(file) {
            // Check file size
            if (file.size > this.maxFileSize) {
                return {
                    valid: false,
                    message: `File "${file.name}" exceeds maximum size of 10MB`
                };
            }

            // Check file type
            if (this.allowedTypes.length > 0 && !this.allowedTypes.includes(file.type)) {
                return {
                    valid: false,
                    message: `File type "${file.type || 'unknown'}" is not supported. Allowed: PDF, DOC, HTML, Images`
                };
            }

            return { valid: true };
        }

        /**
         * Add loading placeholder
         */
        addLoadingAsset(fileName) {
            const loadingId = 'loading-' + Date.now();
            const $list = $('#jc-asset-list');

            // Remove empty state
            $list.find('.jc-empty-state').hide();

            $list.append(`
                <div class="jc-asset-item jc-loading" id="${loadingId}">
                    <div class="jc-asset-icon">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <div class="jc-asset-info">
                        <span class="jc-asset-name">${this.escapeHtml(fileName)}</span>
                        <span class="jc-asset-status">Uploading...</span>
                    </div>
                </div>
            `);

            return loadingId;
        }

        /**
         * Remove loading placeholder
         */
        removeLoadingAsset(loadingId) {
            $(`#${loadingId}`).remove();
        }

        /**
         * Render the asset list
         */
        renderAssetList() {
            const $list = $('#jc-asset-list');
            $list.empty();

            if (this.assets.length === 0) {
                $list.html(`
                    <div class="jc-empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No assets uploaded yet</p>
                    </div>
                `);
                return;
            }

            this.assets.forEach((asset, index) => {
                const icon = this.getFileIcon(asset);
                const size = this.formatFileSize(asset.size);

                $list.append(`
                    <div class="jc-asset-item" data-index="${index}">
                        <div class="jc-asset-icon">
                            <i class="fas ${icon}"></i>
                        </div>
                        <div class="jc-asset-info">
                            <span class="jc-asset-name">${this.escapeHtml(asset.name)}</span>
                            <span class="jc-asset-meta">${size}</span>
                        </div>
                        <div class="jc-asset-actions">
                            <button class="jc-asset-delete" data-index="${index}" title="Remove asset">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `);
            });
        }

        /**
         * Delete an asset
         */
        deleteAsset(index) {
            if (index < 0 || index >= this.assets.length) return;

            const asset = this.assets[index];
            if (!confirm(`Remove "${asset.name}"?`)) return;

            this.assets.splice(index, 1);
            this.renderAssetList();
            this.updateAssetCount();
            this.saveToWorkflow();

            this.workflow.showNotification('Asset removed', 'info');
        }

        /**
         * Update asset count badge
         */
        updateAssetCount() {
            $('.jc-asset-count').text(this.assets.length);
        }

        /**
         * Save assets to workflow state
         */
        saveToWorkflow() {
            this.workflow.updateState('existingAssets', this.assets);
        }

        /**
         * Load assets from workflow state
         */
        loadFromWorkflow() {
            const existingAssets = this.workflow.getState('existingAssets');
            if (existingAssets && existingAssets.length > 0) {
                this.assets = existingAssets;
                this.renderAssetList();
                this.updateAssetCount();
            }
        }

        /**
         * Initialize drag and drop
         */
        initDragDrop(dropZoneSelector) {
            const $dropZone = $(dropZoneSelector);

            // Prevent default browser behavior for drag events
            $dropZone.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('jc-drag-over');
            });

            $dropZone.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('jc-drag-over');
            });

            $dropZone.on('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                $dropZone.removeClass('jc-drag-over');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    Array.from(files).forEach(file => {
                        this.uploadFile(file);
                    });
                }
            });
        }

        /**
         * Get appropriate icon for file type
         */
        getFileIcon(asset) {
            if (!asset.mimeType && !asset.name) return 'fa-file';

            const ext = asset.name ? asset.name.split('.').pop().toLowerCase() : '';

            if (asset.mimeType?.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                return 'fa-file-image';
            }
            if (asset.mimeType === 'application/pdf' || ext === 'pdf') {
                return 'fa-file-pdf';
            }
            if (asset.mimeType?.includes('word') || ['doc', 'docx'].includes(ext)) {
                return 'fa-file-word';
            }
            if (asset.mimeType === 'text/html' || ext === 'html') {
                return 'fa-file-code';
            }
            return 'fa-file';
        }

        /**
         * Format file size
         */
        formatFileSize(bytes) {
            if (!bytes) return '';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
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
            window.drExistingAssetsManager = new ExistingAssetsManager(window.drJourneyCircle);
        }
    });

})(jQuery);