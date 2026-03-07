/**
 * Email Modal Manager
 *
 * Handles AI-powered email generation modal UI and workflow
 *
 * @package DirectReach
 * @subpackage ReadingTheRoom
 * @since 2.0.0
 */

export default class EmailModalManager {
    constructor(api, config) {
        this.api = api;
        this.config = config;
        this.modal = null;
        this.currentProspect = null;
        this.currentEmail = null;
        this._isListening = false;
        this.init();
    }

    /**
     * Initialize modal and event listeners
     */
    init() {
        if (this._isListening) return;
        this._createModal();
        this._attachEventListeners();
        this._isListening = true;
    }

    /**
     * Create modal HTML structure
     */
    _createModal() {
        if (document.getElementById('email-generation-modal')) {
            this.modal = document.getElementById('email-generation-modal');
            return;
        }

        const html = `
            <div class="email-generation-modal" id="email-generation-modal" role="dialog" aria-modal="true">
                <div class="email-modal-overlay" data-rtr="overlay"></div>
                <div class="email-modal-content">
                    <div class="email-modal-header">
                        <h3>
                            <i class="fas fa-robot" aria-hidden="true"></i>
                            Generate Email - <span class="prospect-name-header"></span>
                        </h3>
                        <button class="modal-close" aria-label="Close modal">&times;</button>
                    </div>

                    <div class="email-modal-body">
                        <!-- Loading State -->
                        <div class="modal-body-section loading-state" aria-live="polite">
                            <div class="loading-spinner">
                                <i class="fas fa-robot fa-spin"></i>
                            </div>
                            <p class="loading-text">AI is crafting your email...</p>
                            <div class="loading-progress">
                                <div class="progress-bar"></div>
                            </div>
                            <p class="loading-hint">This usually takes 5-10 seconds</p>
                        </div>

                        <!-- Email Preview State -->
                        <div class="modal-body-section email-preview">
                            <div class="email-metadata">
                                <div class="meta-item">
                                    <i class="fas fa-user"></i>
                                    <span class="meta-label">To:</span>
                                    <span class="meta-value prospect-email"></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-file-alt"></i>
                                    <span class="meta-label">Template:</span>
                                    <span class="meta-value template-name"></span>
                                    <span class="badge global-badge" style="display:none;">Global</span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-link"></i>
                                    <span class="meta-label">Content Link:</span>
                                    <a href="#" class="meta-value content-link" target="_blank" rel="noopener"></a>
                                </div>
                            </div>

                            <div class="email-subject-section">
                                <label class="email-label" for="email-subject-input">Subject Line:</label>
                                <input type="text" 
                                       id="email-subject-input" 
                                       class="email-subject" 
                                       placeholder="Email subject..."
                                       spellcheck="true" />
                            </div>

                            <div class="email-body-section">
                                <label class="email-label" for="email-body-input">Email Body:</label>
                                <div id="email-body-input"
                                     class="email-body" 
                                     contenteditable="true"
                                     data-placeholder="Email body will appear here..."
                                     spellcheck="true"></div>
                                <div class="email-tracking-info">
                                    <i class="fas fa-eye"></i>
                                    <span>Tracking pixel included for open detection</span>
                                </div>
                            </div>
                        </div>

                        <!-- Error State -->
                        <div class="modal-body-section error-state" role="alert">
                            <div class="error-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h4>Email Generation Failed</h4>
                            <p class="error-message"></p>
                            <p class="fallback-message">Please try again.</p>
                        </div>
                    </div>

                    <div class="email-modal-footer">
                        <button class="btn btn-secondary cancel-btn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button class="btn btn-primary copy-btn">
                            <i class="fas fa-copy"></i> Copy to Clipboard
                        </button>
                    </div>

                    <div class="copy-success">
                        <i class="fas fa-check-circle"></i>
                        <span>Email copied to clipboard!</span>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
        this.modal = document.getElementById('email-generation-modal');
    }

    /**
     * Attach event listeners
     */
    _attachEventListeners() {
        // Close modal handlers
        const overlay = this.modal.querySelector('[data-rtr="overlay"]');
        const closeBtn = this.modal.querySelector('.modal-close');
        const cancelBtn = this.modal.querySelector('.cancel-btn');

        [overlay, closeBtn, cancelBtn].forEach(el => {
            if (el) el.addEventListener('click', () => this.hideModal());
        });

        // Copy button
        const copyBtn = this.modal.querySelector('.copy-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => this.copyEmailToClipboard());
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (!this.modal.classList.contains('active')) return;

            if (e.key === 'Escape') {
                this.hideModal();
            } else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                this.copyEmailToClipboard();
            }
        });

        // Listen for email generation requests
        document.addEventListener('rtr:generate-email', (e) => {
            this.showEmailModal(e.detail);
        });

        // Listen for ready email viewing
        document.addEventListener('rtr:view-ready-email', (e) => {
            this.showReadyEmail(e.detail);
        });
    }

    /**
     * Show email generation modal
     */
    async showEmailModal({ prospectId, emailNumber, prospectName, room }) {

        if (!prospectId || !emailNumber) {
            console.error('Missing prospectId or emailNumber');
            return;
        }

        this.currentProspect = { id: prospectId, name: prospectName, room: room };

        this.currentEmail = null;

        // Update modal header
        this.modal.querySelector('.prospect-name-header').textContent = prospectName || `Prospect ${prospectId}`;

        // Show modal and loading state
        this.modal.classList.add('active');
        this._showSection('loading');

        try {
            // Generate email via API
            const email = await this._generateEmail(prospectId, room);
            this.currentEmail = email;

            // Display email preview
            this._displayEmailPreview(email);
            this._showSection('email-preview');

        } catch (error) {
            console.error('Email generation failed:', error);
            this._showError(error.message || 'Failed to generate email. Please try again.');
            this._showSection('error');
        }
    }

    /**
     * Generate email via API
     */
    async _generateEmail(prospectId, room) {
        const response = await this.api.post('/emails/generate', {
            prospect_id: prospectId,
            room_type: room
        });

        if (!response.success) {
            throw new Error(response.message || 'Email generation failed');
        }

        return response.data;
    }

    /**
     * Display generated email in modal
     */
    _displayEmailPreview(email) {
        // Update metadata
        this.modal.querySelector('.prospect-email').textContent = email.prospect_email || 'No email';
        this.modal.querySelector('.template-name').textContent = email.template_used?.name || 'No template';
        
        const globalBadge = this.modal.querySelector('.global-badge');
        if (globalBadge) {
            globalBadge.style.display = email.template_used?.is_global ? 'inline-block' : 'none';
        }

        const contentLink = this.modal.querySelector('.content-link');
        if (email.selected_url?.url) {
            contentLink.href = email.selected_url.url;
            contentLink.textContent = email.selected_url.title || email.selected_url.url;
            contentLink.parentElement.style.display = 'flex';
        } else {
            contentLink.parentElement.style.display = 'none';
        }

        // Update subject
        const subjectInput = this.modal.querySelector('.email-subject');
        subjectInput.value = email.subject || '';

        // Update body
        const bodyDiv = this.modal.querySelector('.email-body');
        bodyDiv.innerHTML = this._renderEmailBody(email.body_html) || '<p>No content generated</p>';

        // Enable editing
        subjectInput.removeAttribute('disabled');
        bodyDiv.setAttribute('contenteditable', 'true');
    }

    /**
     * Copy email to clipboard
     */
    async copyEmailToClipboard() {
        try {
            // Get current values (may have been edited)
            const subject = this.modal.querySelector('.email-subject').value;
            const bodyDiv = this.modal.querySelector('.email-body');
            const bodyHtml = bodyDiv.innerHTML;
            const bodyText = bodyDiv.textContent;

            // Prepare email with tracking pixel
            const trackingToken = this._generateTrackingToken();
            const trackingPixel = `<img src="${this.config.siteUrl}/wp-json/directreach/v2/track-open/${trackingToken}" width="1" height="1" style="display:none" alt="" />`;
            const emailHtmlWithTracking = bodyHtml + trackingPixel;

            // Copy to clipboard (both HTML and plain text)
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/html': new Blob([emailHtmlWithTracking], { type: 'text/html' }),
                    'text/plain': new Blob([bodyText], { type: 'text/plain' })
                })
            ]);

            // Track the copy action
            await this._trackEmailCopy(subject, emailHtmlWithTracking, bodyText, trackingToken);

            // Show success message
            this._showCopySuccess();

            // Dispatch success event
            document.dispatchEvent(new CustomEvent('rtr:email:generated', {
                detail: { 
                    prospectId: this.currentProspect.id,
                    subject,
                    success: true
                }
            }));

            // Close modal after brief delay
            setTimeout(() => this.hideModal(), 1500);

        } catch (error) {
            console.error('Failed to copy email:', error);
            document.dispatchEvent(new CustomEvent('rtr:notification', {
                detail: { 
                    type: 'error', 
                    message: 'Failed to copy email to clipboard. Please try again.' 
                }
            }));
        }
    }

    /**
     * Track email copy in database
     */
    async _trackEmailCopy(subject, bodyHtml, bodyText, trackingToken) {
        try {
            const response = await this.api.post('/emails/track-copy', {
                prospect_id: this.currentProspect.id,
                email_number: (this.currentEmail?.email_number || 1),
                room_type: this.currentProspect.room,
                subject,
                body_html: bodyHtml,
                body_text: bodyText,
                tracking_token: trackingToken,
                template_used: this.currentEmail?.template_used?.id || null,
                url_included: this.currentEmail?.selected_url?.url || null,
                ai_prompt_tokens: this.currentEmail?.usage?.prompt_tokens || 0,
                ai_completion_tokens: this.currentEmail?.usage?.completion_tokens || 0
            });

            if (!response.success) {
                console.error('Failed to track email copy:', response.message);
            }
        } catch (error) {
            console.error('Error tracking email copy:', error);
        }
    }

    /**
     * Generate tracking token
     */
    _generateTrackingToken() {
        const data = `${this.currentProspect.id}-${Date.now()}-${Math.random()}`;
        return btoa(data).replace(/[^a-zA-Z0-9]/g, '').substring(0, 32);
    }

    /**
     * Show copy success animation
     */
    _showCopySuccess() {
        const successEl = this.modal.querySelector('.copy-success');
        if (successEl) {
            successEl.classList.add('show');
            setTimeout(() => successEl.classList.remove('show'), 3000);
        }
    }

    /**
     * Show ready email (email already generated, non-editable view)
     * 
     * @param {Object} details - { prospectId, emailNumber, prospectName }
     */
    async showReadyEmail({ prospectId, emailNumber, prospectName }) {
        if (!prospectId || !emailNumber) {
            console.error('Missing prospectId or emailNumber');
            return;
        }

        this.currentProspect = { 
            id: prospectId, 
            name: prospectName,
            room: null // Will try to get from email tracking
        };
        this.currentEmail = null;

        // Update modal header
        this.modal.querySelector('.prospect-name-header').textContent = 
            prospectName || `Prospect ${prospectId}`;

        // Show modal and loading state
        this.modal.classList.add('active');
        this._showSection('loading');

        try {
            // Use config from instance or fallback to global
            const config = window.rtrDashboardConfig || this.config || {};
            
            // Fetch email from tracking system
            // Extract base URL properly
            let baseUrl = this.api?.url || config.restUrl || config.siteUrl || '';
            if (baseUrl.includes('/wp-json')) {
                baseUrl = baseUrl.split('/wp-json')[0];
            }
            const apiEndpoint = `${baseUrl}/wp-json/directreach/v2/emails/tracking/prospect/${prospectId}/email/${emailNumber}`;
            
            const response = await fetch(
                apiEndpoint,
                {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce
                    }
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to fetch email: ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to load email');
            }

            this.currentEmail = {
                id: data.data.id,
                email_tracking_id: data.data.id, 
                email_number: data.data.email_number || emailNumber, 
                room_type: data.data.room_type, 
                subject: data.data.subject,
                body_html: data.data.body_html,
                body_text: data.data.body_text,
                url_included: data.data.url_included,
                tracking_token: data.data.tracking_token,
                template_used: data.data.template_used,
                generated_by_ai: data.data.generated_by_ai,
                copied_at: data.data.copied_at 
            };

            // Update prospect room from email if available
            if (data.data.room_type && !this.currentProspect.room) {
                this.currentProspect.room = data.data.room_type;
            }

            // Display email in READ-ONLY mode
            this._displayReadyEmail(this.currentEmail);
            this._showSection('email-preview');

        } catch (error) {
            console.error('Failed to load ready email:', error);
            this._showError(error.message || 'Failed to load email. Please try again.');
            this._showSection('error');
        }
    }

    /**
     * Display ready email in read-only mode
     * 
     * @param {Object} email - Email data
     */
    _displayReadyEmail(email) {
        // Populate metadata
        const prospectEmail = this.modal.querySelector('.prospect-email');
        if (prospectEmail) {
            prospectEmail.textContent = this.currentProspect.email || 'N/A';
        }

        const templateName = this.modal.querySelector('.template-name');
        const globalBadge = this.modal.querySelector('.global-badge');
        if (email.template_used && templateName) {
            templateName.textContent = email.template_used.name || 'Custom';
            if (globalBadge) {
                globalBadge.style.display = email.template_used.is_global ? 'inline-block' : 'none';
            }
        }

        const contentLink = this.modal.querySelector('.content-link');
        if (email.url_included && contentLink) {
            contentLink.href = email.url_included;
            contentLink.textContent = email.url_included;
            contentLink.closest('.meta-item').style.display = 'flex';
        } else if (contentLink) {
            contentLink.closest('.meta-item').style.display = 'none';
        }

        const errorSection = this.modal.querySelector('.error-state');
        if (errorSection) {
            errorSection.style.display = 'none';
        }

        // Set subject (READ-ONLY)
        const subjectInput = this.modal.querySelector('.email-subject');
        if (subjectInput) {
            subjectInput.value = email.subject || '';
            subjectInput.setAttribute('readonly', 'readonly');
            subjectInput.classList.add('readonly');
        }

        // Set body (READ-ONLY)
        const bodyDiv = this.modal.querySelector('.email-body');
        if (bodyDiv) {
            bodyDiv.innerHTML = this._renderEmailBody(email.body_html) || '';
            bodyDiv.setAttribute('contenteditable', 'false');
            bodyDiv.classList.add('readonly');
        }

        // Update copy button to show both options
        this._updateCopyButtons();
    }

    /**
     * Update footer to show both copy options and regenerate
     */
    _updateCopyButtons() {
        const footer = this.modal.querySelector('.email-modal-footer');
        if (!footer) return;

        // Check if email has been copied (sent state)
        const wasCopied = this.currentEmail?.copied_at || false;

        // Build footer HTML with conditional Regenerate button
        footer.innerHTML = `
            <button class="btn btn-primary copy-html-btn">
                <i class="fas fa-copy"></i> Copy HTML
            </button>
            <button class="btn btn-secondary copy-raw-btn">
                <i class="fas fa-code"></i> Copy Raw HTML
            </button>
            ${!wasCopied ? `
            <button class="btn btn-warning regenerate-btn">
                <i class="fas fa-sync-alt"></i> Regenerate
            </button>
            ` : ''}
            <button class="btn btn-tertiary cancel-btn">
                <i class="fas fa-times"></i> Cancel
            </button>
            <div class="copy-success"><i class="fas fa-check"></i> Copied!</div>
        `;

        // Attach event listeners
        footer.querySelector('.copy-html-btn')?.addEventListener('click', () => {
            this.copyFormattedHTML();
        });

        footer.querySelector('.copy-raw-btn')?.addEventListener('click', () => {
            this.copyRawHTML();
        });

        footer.querySelector('.regenerate-btn')?.addEventListener('click', () => {
            this.regenerateEmail();
        });

        footer.querySelector('.cancel-btn')?.addEventListener('click', () => {
            this.hideModal();
        });
    }

    /**
     * Copy formatted HTML (for pasting into Gmail)
     * Includes tracking pixel
     */
    async copyFormattedHTML() {
        if (!this.currentEmail) {
            console.error('No email to copy');
            return;
        }

        try {
            const htmlWithTracking = this._addTrackingPixel(this.currentEmail.body_html);

            // Try to copy as rich HTML for email clients
            const htmlBlob = new Blob([htmlWithTracking], { type: 'text/html' });
            const clipboardItem = new ClipboardItem({
                'text/html': htmlBlob
            });

            await navigator.clipboard.write([clipboardItem]);

            this._showCopySuccess();
            this._trackCopy();

            document.dispatchEvent(new CustomEvent('rtr:showNotification', {
                detail: {
                    message: 'Email copied! Paste directly into Gmail/Outlook.',
                    type: 'success'
                }
            }));

            document.dispatchEvent(new CustomEvent('rtr:email-state-update', {
                detail: {
                    visitorId: this.currentProspect.id,
                    emailNumber: this.currentEmail.email_number || 1,
                    newState: 'sent'
                }
            }));

            setTimeout(() => this.hideModal(), 800);

        } catch (error) {
            console.error('Failed to copy formatted HTML:', error);
            alert('Your browser doesn\'t support rich HTML copy. Use "Copy Raw HTML" instead and paste as HTML in your email client.');
        }
    }


    /**
     * Copy raw HTML source code
     * Includes tracking pixel
     */
    async copyRawHTML() {
        if (!this.currentEmail) {
            console.error('No email to copy');
            return;
        }

        try {
            const htmlWithTracking = this._addTrackingPixel(this.currentEmail.body_html);

            await navigator.clipboard.writeText(htmlWithTracking);

            this._showCopySuccess();
            this._trackCopy();

            // Notify user
            document.dispatchEvent(new CustomEvent('rtr:showNotification', {
                detail: {
                    message: 'Raw HTML copied to clipboard!',
                    type: 'success'
                }
            }));

            // Update button state to "sent"
            document.dispatchEvent(new CustomEvent('rtr:email-state-update', {
                detail: {
                    visitorId: this.currentProspect.id,
                    emailNumber: this.currentEmail.email_number || 1,
                    newState: 'sent'
                }
            }));

            // Close modal after brief delay
            setTimeout(() => this.hideModal(), 800);

        } catch (error) {
            console.error('Failed to copy raw HTML:', error);
            alert('Failed to copy. Please try again.');
        }
    }

    /**
     * Regenerate email with new AI generation
     */
    async regenerateEmail() {
        if (!this.currentProspect || !this.currentEmail) {
            console.error('Cannot regenerate: missing prospect or email data');
            return;
        }

        if (!confirm('This will generate a new email and replace the current one. Continue?')) {
            return;
        }

        this._showSection('loading');

        try {
            
            // Use API client instead of raw fetch
            const data = await this.api.post('/emails/generate', {
                prospect_id: parseInt(this.currentProspect.id, 10),
                room_type: this.currentProspect.room || this.currentEmail.room_type,
                force_regenerate: true,
                email_number: parseInt(this.currentEmail.email_number || 1, 10)
            });

            if (!data.success) {
                throw new Error(data.message || 'Email regeneration failed');
            }

            this.currentEmail = {
                id: data.data.id,
                email_tracking_id: data.data.id,
                subject: data.data.subject,
                body_html: data.data.body_html,
                body_text: data.data.body_text,
                url_included: data.data.url_included,
                tracking_token: data.data.tracking_token,
                template_used: data.data.template_used,
                email_number: data.data.email_number,
                force_regenerate: true,
                room_type: data.data.room_type || this.currentEmail.room_type,
                copied_at: null
            };

            this._displayReadyEmail(this.currentEmail);
            this._showSection('email-preview');

            document.dispatchEvent(new CustomEvent('rtr:showNotification', {
                detail: {
                    message: 'Email regenerated successfully!',
                    type: 'success'
                }
            }));

            if (this.currentProspect?.id) {
                document.dispatchEvent(new CustomEvent('rtr:emailGenerated', {
                    detail: {
                        prospectId: this.currentProspect.id,
                        emailNumber: this.currentEmail.email_number,
                        trackingId: this.currentEmail.id
                    }
                }));
            }

        } catch (error) {
            console.error('Failed to regenerate email:', error);
            this._showError(error.message || 'Failed to regenerate email. Please try again.');
            this._showSection('error');
        }
    }

    /**
     * Add tracking pixel to HTML
     * 
     * @param {string} html - Original HTML
     * @returns {string} HTML with tracking pixel
     */
    _addTrackingPixel(html) {
        if (!this.currentEmail || !this.currentEmail.tracking_token) {
            return html;
        }

        const trackingUrl = `${window.location.origin}/wp-json/directreach/v2/emails/track-open/${this.currentEmail.tracking_token}`;
        const trackingPixel = `<img src="${trackingUrl}" width="1" height="1" style="display:none;" alt="" />`;

        // Insert tracking pixel at end of body
        if (html.includes('</body>')) {
            return html.replace('</body>', `${trackingPixel}</body>`);
        } else {
            return html + trackingPixel;
        }
    }

    _stripHtml(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || div.innerText || '';
    }


    /**
     * Track that email was copied
     */
    async _trackCopy() {
        if (!this.currentEmail || (!this.currentEmail.id && !this.currentEmail.tracking_id)) {
            return;
        }

        try {
            // Extract base URL properly
            let baseUrl = this.config?.siteUrl || window.DirectReachConfig?.siteUrl || '';
            if (baseUrl.includes('/wp-json')) {
                baseUrl = baseUrl.split('/wp-json')[0];
            }
            const apiEndpoint = `${baseUrl}/wp-json/directreach/v2/emails/track-copy`;
            
            await fetch(apiEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.rtrDashboardConfig?.nonce || this.config?.nonce || ''
                },
                body: JSON.stringify({
                    email_tracking_id: this.currentEmail?.id || this.currentEmail?.tracking_id,
                    prospect_id: this.currentProspect?.id,
                    url_included: this.currentEmail?.url_included || ''
                })
            });

            // Emit event for UI updates
            document.dispatchEvent(new CustomEvent('rtr:email-copied', {
                detail: {
                    visitorId: this.currentProspect?.id,
                    emailNumber: this.currentEmail?.email_number
                }
            }));

        } catch (error) {
            console.error('Failed to track copy:', error);
            // Don't block the copy operation
        }
    }

    /**
     * Hide modal
     */
    hideModal() {
        if (!this.modal) return;
        this.modal.classList.remove('active');
        this.currentProspect = null;
        this.currentEmail = null;

        // Reset modal content after animation
        setTimeout(() => {
            this._showSection(null);
            this.modal.querySelector('.email-subject').value = '';
            this.modal.querySelector('.email-body').innerHTML = '';
        }, 300);
    }

    /**
     * Show specific section (loading, email-preview, error)
     */
    _showSection(section) {
        this.modal.querySelectorAll('.modal-body-section').forEach(el => {
            el.classList.remove('active');
        });

        if (section) {
            const target = this.modal.querySelector(`.${section}`);
            if (target) target.classList.add('active');
        }
    }

    /**
     * Show error message
     */
    _showError(message) {
        const errorMsg = this.modal.querySelector('.error-message');
        if (errorMsg) {
            errorMsg.textContent = message || 'An unexpected error occurred.';
        }
    }

    /**
     * Render email body for display.
     * If content is plain text (no HTML tags), converts newlines to <br> tags
     * so Field Note emails render with correct line breaks in the modal.
     */
    _renderEmailBody(html) {
        if (!html) return '';
        // If already contains HTML tags, use as-is
        if (/<[a-z][\s\S]*>/i.test(html)) return html;
        // Plain text: escape then convert newlines to <br>
        const escaped = this._escapeHtml(html);
        return escaped.replace(/\n/g, '<br>');
    }

    /**
     * Escape HTML
     */
    _escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
}