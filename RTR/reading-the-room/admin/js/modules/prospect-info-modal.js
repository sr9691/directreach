/**
 * Prospect Info Modal Manager
 * 
 * Displays detailed information about a prospect from both
 * rtr_prospects and cpd_visitors tables
 */

export default class ProspectInfoModal {
    constructor(config) {
        this.config = config;
        this.apiUrl = config?.restUrl || config?.apiUrl || window.rtrDashboardConfig?.restUrl;
        this.nonce = config?.nonce || window.rtrDashboardConfig?.nonce;
        this.modal = null;
        this.isOpen = false;
        this.listenersAttached = false;
        this.currentProspectData = null; // Store current prospect data
        
        this.init();
    }

    init() {
        this.createModal();
        if (!this.listenersAttached) {
            this.attachEventListeners();
            this.listenersAttached = true;
        }
    }

    createModal() {
        // Check if modal already exists
        let existingModal = document.getElementById('prospect-info-modal');
        if (existingModal) {
            this.modal = existingModal;
            return;
        }
        
        // Create modal structure
        const modal = document.createElement('div');
        modal.id = 'prospect-info-modal';
        modal.className = 'rtr-modal';
        modal.innerHTML = `
            <div class="rtr-modal-overlay"></div>
            <div class="rtr-modal-content prospect-info-modal-content">
                <div class="rtr-modal-header">
                    <h3><i class="fas fa-user-circle"></i> Prospect Details</h3>
                    <button class="rtr-modal-close" aria-label="Close">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="rtr-modal-body prospect-info-body">
                    <div class="prospect-info-loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading prospect details...</p>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        this.modal = modal;
    }

    attachEventListeners() {
        // Listen for open requests
        document.addEventListener('rtr:showProspectInfo', (e) => {
            const { visitorId, room } = e.detail;
            this.open(visitorId, room);
        });

        // Close on overlay click
        if (this.modal) {
            const overlay = this.modal.querySelector('.rtr-modal-overlay');
            overlay.addEventListener('click', () => this.close());

            // Close on X button
            const closeBtn = this.modal.querySelector('.rtr-modal-close');
            closeBtn.addEventListener('click', () => this.close());
        }

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });

        // Delegate email enrichment button clicks
        document.addEventListener('click', (e) => {
            // Find Email button
            const findEmailBtn = e.target.closest('.rtr-find-email-btn');
            if (findEmailBtn && this.isOpen) {
                e.preventDefault();
                const visitorId = findEmailBtn.dataset.visitorId;
                this.handleFindEmail(visitorId);
            }

            // Verify Email button
            const verifyEmailBtn = e.target.closest('.rtr-verify-email-btn');
            if (verifyEmailBtn && this.isOpen) {
                e.preventDefault();
                const visitorId = verifyEmailBtn.dataset.visitorId;
                this.handleVerifyEmail(visitorId);
            }
        });
    }

    async open(visitorId, room) {
        if (!visitorId) {
            console.error('No visitor ID provided');
            return;
        }

        this.modal.classList.add('active');
        this.isOpen = true;
        document.body.style.overflow = 'hidden';

        // Show loading state
        const body = this.modal.querySelector('.rtr-modal-body');
        body.innerHTML = `
            <div class="prospect-info-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading prospect details...</p>
            </div>
        `;

        try {
            // Fetch prospect details
            const prospectData = await this.fetchProspectDetails(visitorId);
            this.currentProspectData = prospectData; // Store for enrichment operations
            
            // Render the data
            this.renderProspectInfo(prospectData);
        } catch (error) {
            console.error('Failed to load prospect details:', error);
            this.showError(error.message);
        }
    }

    close() {
        this.modal.classList.remove('active');
        this.isOpen = false;
        this.currentProspectData = null; // Clear stored data
        document.body.style.overflow = '';
    }

    async fetchProspectDetails(visitorId) {
        const url = `${this.apiUrl}/prospects/${visitorId}/details`;
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': this.nonce,
                'Content-Type': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error(`Failed to fetch prospect details: ${response.statusText}`);
        }

const data = await response.json();
        return data.data || data;
    }

    /**
     * Handle Find Email button click
     */
    async handleFindEmail(visitorId) {
        console.log(`Finding email for visitor ${visitorId}`);
        
        // Show loading state
        this.setEmailEnrichmentState(visitorId, 'finding', 'Finding email address...');
        
        try {
            const url = `${this.apiUrl}/prospects/${visitorId}/find-email`;
            
            // Build request body with visitor data
            const body = {};
            
            // Check if we have member_id from prior enrichment
            if (this.currentProspectData?.prospect?.aleads_member_id) {
                body.member_id = this.currentProspectData.prospect.aleads_member_id;
                body.first_name = this.currentProspectData.visitor.first_name;
                body.last_name = this.currentProspectData.visitor.last_name;
                body.company_domain = this.extractDomain(this.currentProspectData.visitor.website);
            }
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': this.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body) 
            });

            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.message || 'Email not found');
            }

            if (result.success && result.data && result.data.email) {
                // Update the displayed email inline
                this.updateEmailDisplay(result.data.email, 'found');
                this.setEmailEnrichmentState(visitorId, 'success', `Email found: ${result.data.email}`);
                
                // Update stored data
                if (this.currentProspectData) {
                    if (!this.currentProspectData.prospect) {
                        this.currentProspectData.prospect = {};
                    }
                    this.currentProspectData.prospect.contact_email = result.data.email;
                    
                    if (!this.currentProspectData.visitor) {
                        this.currentProspectData.visitor = {};
                    }
                    this.currentProspectData.visitor.email = result.data.email;
                }
                
                // Dispatch event to update prospect list
                document.dispatchEvent(new CustomEvent('rtr:emailUpdated', {
                    detail: { 
                        visitorId, 
                        email: result.data.email,
                        source: 'find'
                    }
                }));
            } else {
                throw new Error(result.message || 'Email not found');
            }
            
        } catch (error) {
            console.error('Find email failed:', error);
            this.setEmailEnrichmentState(visitorId, 'error', error.message);
        }
    }

    extractDomain(url) {
        if (!url) return '';
        try {
            const urlObj = new URL(url.startsWith('http') ? url : 'https://' + url);
            return urlObj.hostname.replace('www.', '');
        } catch (e) {
            return url.replace(/^https?:\/\/(www\.)?/, '').split('/')[0];
        }
    }    

    /**
     * Handle Verify Email button click
     */
    async handleVerifyEmail(visitorId) {
        console.log('Verifying email for visitor', visitorId);
        
        try {
            // Get the email from stored data or DOM
            let email = null;
            
            // First try to get from stored data
            if (this.currentProspectData) {
                email = this.currentProspectData.prospect?.contact_email 
                    || this.currentProspectData.visitor?.email;
            }
            
            // Fallback to DOM if not in stored data
            if (!email || email === 'N/A') {
                const emailValueEl = document.querySelector('.info-item-email .info-value');
                if (emailValueEl) {
                    email = emailValueEl.textContent.trim();
                }
            }
            
            // Final validation
            if (!email || email === 'N/A') {
                throw new Error('No email found for verification');
            }
            
            console.log('Verifying email:', email);
            
            // Show loading state
            this.setEmailEnrichmentState(visitorId, 'verifying', 'Verifying email address...');
            
            const response = await fetch(`${this.apiUrl}/prospects/${visitorId}/verify-email`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.nonce
                },
                body: JSON.stringify({
                    email: email
                })
            });

            console.log('Verify response status:', response.status);
            
            const data = await response.json();
            console.log('Verify response data:', data);

            if (!response.ok) {
                throw new Error(data.message || 'Verification failed');
            }

            if (data.success) {
                // Update UI to show verification status
                const status = data.verified ? 'valid' : 'invalid';
                this.updateEmailDisplay(email, status);
                
                // Get human readable message
                const message = this.getVerificationMessage(status, data.data);
                this.setEmailEnrichmentState(visitorId, 'success', message);
                
                if (data.verified !== undefined) {
                    // Update stored data
                    if (this.currentProspectData?.prospect) {
                        this.currentProspectData.prospect.email_verified = data.verified ? '1' : '0';
                        this.currentProspectData.prospect.email_verification_status = data.verified ? 'valid' : 'invalid';
                        this.currentProspectData.prospect.email_quality = data.data?.quality || null;
                    }
                    
                    // Re-render to show badge
                    this.renderProspectInfo(this.currentProspectData);
                }            
                


                // Dispatch event to update prospect list
                document.dispatchEvent(new CustomEvent('rtr:emailVerified', {
                    detail: { 
                        visitorId, 
                        email: email,
                        verified: data.verified,
                        data: data.data
                    }
                }));
            } else {
                throw new Error(data.message || 'Verification failed');
            }


        } catch (error) {
            console.error('Verify email failed:', error);
            this.setEmailEnrichmentState(visitorId, 'error', `Verification failed: ${error.message}`);
        }
    }

    updateVerificationStatus(isVerified, verificationData) {
        const container = document.querySelector('.prospect-email')?.parentElement;
        if (!container) return;
        
        // Remove any existing verification badge
        const existingBadge = container.querySelector('.verification-badge');
        if (existingBadge) {
            existingBadge.remove();
        }
        
        // Add new verification badge
        const badge = document.createElement('span');
        badge.className = `verification-badge ${isVerified ? 'verified' : 'unverified'}`;
        badge.textContent = isVerified ? '✓ Verified' : '✗ Unverified';
        badge.style.marginLeft = '10px';
        badge.style.padding = '2px 8px';
        badge.style.borderRadius = '3px';
        badge.style.fontSize = '12px';
        badge.style.backgroundColor = isVerified ? '#4CAF50' : '#f44336';
        badge.style.color = 'white';
        
        container.appendChild(badge);
    }    

    /**
     * Update email enrichment UI state
     */
    setEmailEnrichmentState(visitorId, state, message) {
        const container = document.querySelector('.rtr-email-enrichment-container');
        if (!container) return;
        
        const messageEl = container.querySelector('.rtr-enrichment-message');
        const buttons = container.querySelectorAll('button');
        
        // Remove all state classes
        container.classList.remove('state-finding', 'state-verifying', 'state-success', 'state-error');
        
        // Add current state class
        if (state) {
            container.classList.add(`state-${state}`);
        }
        
        // Update message
        if (messageEl && message) {
            messageEl.textContent = message;
            messageEl.style.display = 'block';
            
            // Auto-hide success/error messages after 5 seconds
            if (state === 'success' || state === 'error') {
                setTimeout(() => {
                    messageEl.style.display = 'none';
                }, 5000);
            }
        }
        
        // Disable buttons during loading
        buttons.forEach(btn => {
            btn.disabled = (state === 'finding' || state === 'verifying');
        });
    }

    /**
     * Update the displayed email address inline
     */
    updateEmailDisplay(email, status) {
        const emailValueEl = document.querySelector('.info-item-email .info-value');
        if (!emailValueEl) return;
        
        // Add verification badge if status provided
        let badge = '';
        if (status === 'valid' || status === 'found') {
            badge = '<span class="email-verified-badge" title="Verified"><i class="fas fa-check-circle"></i></span>';
        } else if (status === 'invalid') {
            badge = '<span class="email-invalid-badge" title="Invalid"><i class="fas fa-times-circle"></i></span>';
        } else if (status === 'risky' || status === 'unknown') {
            badge = '<span class="email-risky-badge" title="Risky/Unknown"><i class="fas fa-exclamation-triangle"></i></span>';
        }
        
        emailValueEl.innerHTML = `${this.escapeHtml(email)} ${badge}`;
        
        // Update the enrichment buttons visibility
        this.updateEnrichmentButtons(email);
    }

    /**
     * Update which enrichment buttons are visible
     */
    updateEnrichmentButtons(email) {
        const findBtn = document.querySelector('.rtr-find-email-btn');
        const verifyBtn = document.querySelector('.rtr-verify-email-btn');
        
        if (findBtn && verifyBtn) {
            if (email && email !== 'N/A') {
                // Email exists - show verify, hide find
                findBtn.style.display = 'none';
                verifyBtn.style.display = 'inline-flex';
            } else {
                // No email - show find, hide verify
                findBtn.style.display = 'inline-flex';
                verifyBtn.style.display = 'none';
            }
        }
    }

    /**
     * Get human-readable verification message
     */
    getVerificationMessage(status, verification) {
        const messages = {
            'valid': '✓ Email is valid and deliverable',
            'invalid': '✗ Email is invalid',
            'risky': '⚠ Email is risky (catch-all or disposable)',
            'unknown': '⚠ Email verification inconclusive',
            'accept_all': '⚠ Domain accepts all emails (catch-all)'
        };
        
        return messages[status] || `Email verification status: ${status}`;
    }

    renderProspectInfo(data) {
        const body = this.modal.querySelector('.rtr-modal-body');
        
        // Extract prospect, visitor, and intelligence data
        const prospect = (Array.isArray(data.prospect) && data.prospect.length === 0) ? {} : (data.prospect || {});
        const visitor = data.visitor || {};
        const intelligence = data.intelligence || {};
        
        // Determine current email for enrichment buttons
        const currentEmail = prospect.contact_email || visitor.email || '';
        const emailToVerify = currentEmail;
        const hasProspectRecord = prospect && Object.keys(prospect).length > 0;
        const hasEmail = currentEmail && currentEmail !== 'N/A';
        
        body.innerHTML = `
            <div class="prospect-info-container">
                <!-- Contact Information Section -->
                <div class="info-section">
                    <h4 class="info-section-title">
                        <i class="fas fa-user"></i> Contact Information
                    </h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Name:</span>
                            <span class="info-value">${this.escapeHtml(prospect.contact_name || visitor.first_name + ' ' + visitor.last_name || 'N/A')}</span>
                        </div>
                        <div class="info-item info-item-email">
                            <span class="info-label">Email:</span>
                            <div class="info-value-with-actions">
                                <span class="info-value">
                                <span class="info-value">
                                    ${this.escapeHtml(currentEmail || 'N/A')}
                                    ${hasProspectRecord && currentEmail ? this.renderVerificationBadge(prospect) : (currentEmail ? '<i class="fas fa-question-circle" style="color: #f59e0b; margin-left: 8px; font-size: 14px;" title="Email not yet verified"></i>' : '')}
                                </span>
                                <!-- Email Enrichment Buttons -->
                                <div class="rtr-email-enrichment-container">
                                    <button class="rtr-find-email-btn rtr-enrichment-btn" 
                                            data-visitor-id="${visitor.id || prospect.visitor_id}"
                                            title="Find email address"
                                            style="${hasEmail ? 'display: none;' : ''}">
                                        <i class="fas fa-envelope"></i>
                                        <span>Find Email</span>
                                    </button>
                                    <button class="rtr-verify-email-btn rtr-enrichment-btn" 
                                            data-visitor-id="${visitor.id || prospect.visitor_id}"
                                            title="Verify email address"
                                            style="${hasEmail && (!hasProspectRecord || (prospect.email_verified != '1' && prospect.email_verified != 1)) ? '' : 'display: none;'}">
                                        <i class="fas fa-check-circle"></i>
                                        <span>Verify Email</span>
                                    </button>
                                    <div class="rtr-enrichment-message" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Job Title:</span>
                            <span class="info-value">${this.escapeHtml(visitor.job_title || 'N/A')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">LinkedIn:</span>
                            <span class="info-value">
                                ${visitor.linkedin_url ? `<a href="${this.escapeHtml(visitor.linkedin_url)}" target="_blank" rel="noopener">View Profile <i class="fas fa-external-link-alt"></i></a>` : 'N/A'}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Company Information Section -->
                <div class="info-section">
                    <h4 class="info-section-title">
                        <i class="fas fa-building"></i> Company Information
                    </h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Company:</span>
                            <span class="info-value">${this.escapeHtml(prospect.company_name || visitor.company_name || 'N/A')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Website:</span>
                            <span class="info-value">
                                ${visitor.website ? `<a href="${this.escapeHtml(visitor.website)}" target="_blank" rel="noopener">${this.escapeHtml(visitor.website)} <i class="fas fa-external-link-alt"></i></a>` : 'N/A'}
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Industry:</span>
                            <span class="info-value">${this.escapeHtml(visitor.industry || 'N/A')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Company Size:</span>
                            <span class="info-value">${this.escapeHtml(visitor.estimated_employee_count || 'N/A')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Revenue:</span>
                            <span class="info-value">${this.escapeHtml(visitor.estimated_revenue || 'N/A')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Location:</span>
                            <span class="info-value">${this.formatLocation(visitor)}</span>
                        </div>
                    </div>
                </div>

                <!-- Engagement Information Section -->
                <div class="info-section">
                    <h4 class="info-section-title">
                        <i class="fas fa-chart-line"></i> Engagement Information
                    </h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Current Room:</span>
                            <span class="info-value">
                                <span class="room-badge room-badge-${this.escapeHtml(visitor.current_room || 'none')}">${this.formatRoom(visitor.current_room)}</span>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Intent Score:</span>
                            <span class="info-value">
                                <span class="lead-score-badge" style="background-color: ${this.getScoreColor(visitor.lead_score)}">${visitor.lead_score || 0}</span>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Days in Room:</span>
                            <span class="info-value">${prospect.days_in_room || 0} days</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email Position:</span>
                            <span class="info-value">${prospect.email_sequence_position || 0} / 5</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Total Page Views:</span>
                            <span class="info-value">${visitor.all_time_page_views || 0}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Recent Page Views:</span>
                            <span class="info-value">${visitor.recent_page_count || 0}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">First Seen:</span>
                            <span class="info-value">${this.formatDate(visitor.first_seen_at)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Last Seen:</span>
                            <span class="info-value">${this.formatDate(visitor.last_seen_at)}</span>
                        </div>
                    </div>
                </div>

                <!-- Campaign Information Section -->
                ${prospect.campaign_id ? `
                <div class="info-section">
                    <h4 class="info-section-title">
                        <i class="fas fa-bullhorn"></i> Campaign Information
                    </h4>
                    <div class="info-grid">
                        <div class="info-item full-width">
                            <span class="info-label">Campaign:</span>
                            <span class="info-value">${this.escapeHtml(prospect.campaign_name || 'N/A')}</span>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Recent Pages Visited Section -->
                ${this.renderRecentPages(visitor.recent_page_urls)}

                <!-- Email States Section -->
                ${this.renderEmailStates(prospect.email_states)}

                <!-- AI Intelligence Section -->
                ${this.renderIntelligence(intelligence)}

                <!-- Additional Data Section -->
                <div class="info-section">
                    <h4 class="info-section-title">
                        <i class="fas fa-info-circle"></i> Additional Information
                    </h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="info-value">${this.escapeHtml(visitor.status || 'N/A')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CRM Added:</span>
                            <span class="info-value">${visitor.is_crm_added === '1' ? 'Yes' : 'No'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Archived:</span>
                            <span class="info-value">${visitor.is_archived === '1' ? 'Yes' : 'No'}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    renderRecentPages(pagesJson) {
        if (!pagesJson) return '';
        
        let pages;
        if (typeof pagesJson === 'string') {
            try {
                pages = JSON.parse(pagesJson);
            } catch (e) {
                return '';
            }
        } else {
            pages = pagesJson;
        }
        
        if (!Array.isArray(pages) || pages.length === 0) return '';
        
        return `
            <div class="info-section recent-pages-section">
                <h4 class="info-section-title"><i class="fas fa-history"></i> Recent Pages Visited</h4>
                <div class="page-count-info">
                    <i class="fas fa-info-circle"></i>
                    <span>Showing <strong>${Math.min(pages.length, 10)}</strong> of <strong>${pages.length}</strong> pages visited</span>
                </div>
                <div class="recent-pages-list">
                    ${pages.slice(0, 10).map(url => `
                        <div class="recent-page-item">
                            <i class="fas fa-link"></i>
                            <a href="${this.escapeHtml(url)}" target="_blank" rel="noopener" title="${this.escapeHtml(url)}">
                                ${this.truncateUrl(url)}
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    renderEmailStates(emailStates) {
        if (!emailStates) return '';
        
        let states;
        try {
            states = typeof emailStates === 'string' ? JSON.parse(emailStates) : emailStates;
        } catch (e) {
            return '';
        }
        
        if (!states || Object.keys(states).length === 0) return '';
        
        return `
            <div class="info-section">
                <h4 class="info-section-title">
                    <i class="fas fa-envelope"></i> Email Sequence Status
                </h4>
                <div class="email-states-grid">
                    ${Object.keys(states).sort().map(key => {
                        const emailData = states[key] || {};
                        const emailNum = key.replace('email_', '');
                        const state = emailData.state || emailData.status || 'pending';
                        return `
                            <div class="email-state-item">
                                <span class="email-number">Email ${emailNum}</span>
                                <span class="email-status ${state}">${this.formatEmailState(state)}</span>
                                ${emailData.timestamp || emailData.sent_at ? `<span class="email-timestamp">${this.formatDate(emailData.timestamp || emailData.sent_at)}</span>` : ''}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    }

    renderIntelligence(intelligence) {
        if (!intelligence || !intelligence.response_data) return '';
        
        const responseData = intelligence.response_data;
        
        // Parse if it's a string
        let parsedData = responseData;
        if (typeof responseData === 'string') {
            try {
                parsedData = JSON.parse(responseData);
            } catch (e) {
                // If parsing fails, display as text
                return `
                    <div class="info-section visitor-modal-section intelligence-section">
                        <h4><i class="fas fa-brain"></i> AI Intelligence Insights</h4>
                        <div class="intelligence-content">
                            <p class="intelligence-text">${this.escapeHtml(responseData)}</p>
                            ${intelligence.processing_time ? `<div class="intelligence-meta"><small>Generated in ${intelligence.processing_time}ms</small></div>` : ''}
                        </div>
                    </div>
                `;
            }
        }
        
        // Format the parsed data
        const formattedData = this.formatIntelligenceData(parsedData);
        
        return `
            <div class="info-section visitor-modal-section intelligence-section">
                <h4><i class="fas fa-brain"></i> AI Intelligence Insights</h4>
                <div class="intelligence-data">
                    ${formattedData}
                </div>
                ${intelligence.processing_time ? `<div class="intelligence-meta"><small>Generated in ${intelligence.processing_time}ms</small></div>` : ''}
            </div>
        `;
    }

    formatIntelligenceData(data) {
        if (!data || typeof data !== 'object') return '';
        
        let html = '';
        
        // Handle common AI response structures
        for (const [key, value] of Object.entries(data)) {
            if (value === null || value === undefined) continue;
            
            const formattedKey = this.formatIntelligenceKey(key);
            
            // Skip email-related keys for now, can be added back if needed
            if (key.toLowerCase().includes('email for')) continue;
            
            html += `<div class="intelligence-item">`;
            html += `<h4><i class="fas fa-lightbulb"></i> ${this.escapeHtml(formattedKey)}</h4>`;
            
            if (typeof value === 'string') {
                // Clean up the string value
                const cleanValue = value.replace(/\\n\\n/g, '\n').replace(/\\n/g, '\n').trim();
                html += `<p>${this.escapeHtml(cleanValue)}</p>`;
            } else if (Array.isArray(value)) {
                html += `<ul>`;
                value.forEach(item => {
                    if (typeof item === 'object') {
                        html += `<li>${this.formatNestedObject(item)}</li>`;
                    } else {
                        html += `<li>${this.escapeHtml(String(item))}</li>`;
                    }
                });
                html += `</ul>`;
            } else if (typeof value === 'object') {
                // Handle nested objects (like decision makers)
                html += this.formatNestedObject(value);
            } else {
                html += `<p>${this.escapeHtml(String(value))}</p>`;
            }
            
            html += `</div>`;
        }
        
        return html || '<div class="no-data-message">No intelligence data available</div>';
    }

    formatNestedObject(obj) {
        if (!obj || typeof obj !== 'object') return '';
        
        let html = '<ul>';
        
        for (const [key, value] of Object.entries(obj)) {
            if (value === null || value === undefined) continue;
            
            const formattedKey = this.formatIntelligenceKey(key);
            
            if (typeof value === 'string') {
                html += `<li><strong>${this.escapeHtml(formattedKey)}:</strong> ${this.escapeHtml(value)}</li>`;
            } else if (Array.isArray(value)) {
                html += `<li><strong>${this.escapeHtml(formattedKey)}:</strong><ul>`;
                value.forEach(item => {
                    html += `<li>${this.escapeHtml(String(item))}</li>`;
                });
                html += `</ul></li>`;
            } else if (typeof value === 'object') {
                html += `<li><strong>${this.escapeHtml(formattedKey)}:</strong>`;
                html += this.formatNestedObject(value);
                html += `</li>`;
            } else {
                html += `<li><strong>${this.escapeHtml(formattedKey)}:</strong> ${this.escapeHtml(String(value))}</li>`;
            }
        }
        
        html += '</ul>';
        return html;
    }

    formatIntelligenceKey(key) {
        // Convert key to readable format
        return key
            .replace(/_/g, ' ')
            .replace(/\b\w/g, l => l.toUpperCase())
            .replace(/&/g, '&amp;')
            .trim();
    }

    renderVerificationBadge(prospect) {
        if (!prospect || !prospect.contact_email) {
            return '';
        }
        
        // Verified email - green check
        console.log('Email verified status:', prospect.email_verified); 
        if (prospect.email_verified === '1' || prospect.email_verified === 1) {
            const verifiedDate = prospect.email_verified_at 
                ? ` on ${this.formatDate(prospect.email_verified_at)}` 
                : '';
            return `<i class="fas fa-check-circle" 
                    style="color: #10b981; margin-left: 8px; font-size: 14px;" 
                    title="Email verified${verifiedDate}"></i>`;
        }
        
        // Invalid email - red X
        if (prospect.email_verification_status === 'invalid') {
            return `<i class="fas fa-times-circle" 
                    style="color: #ef4444; margin-left: 8px; font-size: 14px;" 
                    title="Email verification failed"></i>`;
        }
        
        // Not verified - yellow question mark
        if (prospect.contact_email) {
            return `<i class="fas fa-question-circle" 
                    style="color: #f59e0b; margin-left: 8px; font-size: 14px;" 
                    title="Email not yet verified"></i>`;
        }
        
        return '';
    }    

    showError(message) {
        const body = this.modal.querySelector('.rtr-modal-body');
        body.innerHTML = `
            <div class="prospect-info-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Failed to load prospect details</p>
                <small>${this.escapeHtml(message)}</small>
            </div>
        `;
    }

    // Helper methods
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    formatRoom(room) {
        const rooms = {
            'problem': 'Problem Room',
            'solution': 'Solution Room',
            'offer': 'Offer Room',
            'sales': 'Sales Room',
            'none': 'Not Assigned'
        };
        return rooms[room] || room || 'N/A';
    }

    formatLocation(visitor) {
        const parts = [];
        if (visitor.city) parts.push(visitor.city);
        if (visitor.state) parts.push(visitor.state);
        if (visitor.country) parts.push(visitor.country);
        return parts.length > 0 ? parts.join(', ') : 'N/A';
    }

    formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString();
    }

    formatEmailState(state) {
        const states = {
            'pending': 'Pending',
            'generating': 'Generating',
            'ready': 'Ready',
            'sent': 'Sent',
            'opened': 'Opened',
            'failed': 'Failed'
        };
        return states[state] || state || 'Unknown';
    }

    getScoreColor(score) {
        const s = parseInt(score) || 0;
        if (s >= 70) return '#10b981';
        if (s >= 40) return '#f59e0b';
        return '#ef4444';
    }

    truncateUrl(url, maxLength = 60) {
        if (!url) return '';
        if (url.length <= maxLength) return url;
        return url.substring(0, maxLength - 3) + '...';
    }
}