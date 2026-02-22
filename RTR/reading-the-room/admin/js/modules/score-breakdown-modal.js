/**
 * Score Breakdown Modal
 * 
 * Displays detailed scoring criteria breakdown for a prospect
 */

export default class ScoreBreakdownModal {
    constructor(config) {
        this.config = config;
        this.apiUrl = config?.restUrl || config?.apiUrl || '';
        this.nonce = config?.nonce || '';
        this.modal = null;
        this.isOpen = false;
        
        this.init();
    }

    init() {
        this.createModal();
        this.attachEventListeners();
    }

    createModal() {
        // Create modal structure
        const modalHTML = `
            <div id="score-breakdown-modal" class="rtr-modal score-breakdown-modal" style="display: none;">
                <div class="modal-overlay"></div>
                <div class="modal-content score-breakdown-content">
                    <div class="modal-header">
                        <h3 class="modal-title">
                            <i class="fas fa-calculator"></i>
                            Intent Score Breakdown
                        </h3>
                        <button class="modal-close" aria-label="Close">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body score-breakdown-body">
                        <!-- Content will be dynamically inserted -->
                    </div>
                </div>
            </div>
        `;

        // Remove existing modal if present
        const existing = document.getElementById('score-breakdown-modal');
        if (existing) {
            existing.remove();
        }

        // Insert modal into DOM
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.modal = document.getElementById('score-breakdown-modal');
    }

    attachEventListeners() {
        if (!this.modal) return;

        // Close button
        const closeBtn = this.modal.querySelector('.modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.close());
        }

        // Click outside to close
        const overlay = this.modal.querySelector('.modal-overlay');
        if (overlay) {
            overlay.addEventListener('click', () => this.close());
        }

        // Escape key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
    }

    /**
     * Open modal with prospect data
     * @param {Number} visitorId - Visitor ID
     * @param {Number} clientId - Client ID
     * @param {String} prospectName - Prospect name for display
     */
    async open(visitorId, clientId, prospectName = 'Prospect') {
        if (this.isOpen) return;

        this.modal.style.display = 'flex';
        this.isOpen = true;

        // Show loading state
        this.showLoading(prospectName);

        try {
            // Fetch score breakdown from API
            const scoreData = await this.fetchScoreBreakdown(visitorId, clientId);
            
            if (scoreData && scoreData.total_score !== undefined) {
                this.renderScoreBreakdown(scoreData, prospectName);
            } else {
                this.showError('No scoring data available for this prospect.');
            }
        } catch (error) {
            console.error('Failed to fetch score breakdown:', error);
            this.showError('Failed to load scoring data. Please try again.');
        }
    }

    /**
     * Fetch score breakdown from API
     */
    async fetchScoreBreakdown(visitorId, clientId) {
        // Use v2 endpoint since calculate-score is in Jobs Controller (directreach/v2)
        let baseUrl = this.apiUrl;
        
        // Extract site URL and construct v2 endpoint
        if (baseUrl.includes('/wp-json/')) {
            baseUrl = baseUrl.split('/wp-json/')[0];
        }
        
        const url = `${baseUrl}/wp-json/directreach/v2/calculate-score?visitor_id=${visitorId}&client_id=${clientId}`;
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.nonce
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
    }

    /**
     * Render score breakdown content
     */
    renderScoreBreakdown(scoreData, prospectName) {
        const body = this.modal.querySelector('.score-breakdown-body');
        if (!body) return;

        const { total_score, current_room, breakdown, details } = scoreData;

        const html = `
            <div class="score-header">
                <div class="score-prospect-name">
                    <i class="fas fa-user"></i>
                    ${this.escapeHtml(prospectName)}
                </div>
                <div class="score-total">
                    <div class="score-total-label">Intent Score</div>
                    <div class="score-total-value">${total_score}</div>
                </div>
                <div class="score-room">
                    <div class="score-room-label">Current Room</div>
                    <div class="score-room-badge ${current_room}">${this.formatRoom(current_room)}</div>
                </div>
            </div>

            <div class="score-breakdown-sections">
                ${this.renderRoomSection('Problem Room', 'problem', breakdown?.problem || 0, details?.problem || {})}
                ${this.renderRoomSection('Solution Room', 'solution', breakdown?.solution || 0, details?.solution || {})}
                ${this.renderRoomSection('Offer Room', 'offer', breakdown?.offer || 0, details?.offer || {})}
            </div>

            <div class="score-summary">
                <div class="score-summary-row">
                    <span class="score-summary-label">Problem Score:</span>
                    <span class="score-summary-value">${breakdown?.problem || 0} pts</span>
                </div>
                <div class="score-summary-row">
                    <span class="score-summary-label">Solution Score:</span>
                    <span class="score-summary-value">${breakdown?.solution || 0} pts</span>
                </div>
                <div class="score-summary-row">
                    <span class="score-summary-label">Offer Score:</span>
                    <span class="score-summary-value">${breakdown?.offer || 0} pts</span>
                </div>
                <div class="score-summary-row score-summary-total">
                    <span class="score-summary-label">Total Intent Score:</span>
                    <span class="score-summary-value">${total_score} pts</span>
                </div>
            </div>
        `;

        body.innerHTML = html;
    }

    /**
     * Render individual room section with scoring criteria
     */
    renderRoomSection(title, roomType, roomScore, criteria) {
        const iconMap = {
            problem: 'fa-exclamation-triangle',
            solution: 'fa-lightbulb',
            offer: 'fa-handshake'
        };

        const icon = iconMap[roomType] || 'fa-chart-line';

        // Build criteria rows
        let criteriaHTML = '';
        
        if (criteria && Object.keys(criteria).length > 0) {
            for (const [key, points] of Object.entries(criteria)) {
                const label = this.formatCriteriaLabel(key);
                const earned = points > 0;
                const statusClass = earned ? 'earned' : 'not-earned';
                const statusIcon = earned ? 'fa-check-circle' : 'fa-times-circle';
                
                criteriaHTML += `
                    <div class="criteria-row ${statusClass}">
                        <div class="criteria-label">
                            <i class="fas ${statusIcon}"></i>
                            ${label}
                        </div>
                        <div class="criteria-points">${points > 0 ? '+' : ''}${points}</div>
                    </div>
                `;
            }
        } else {
            criteriaHTML = `
                <div class="criteria-row empty">
                    <div class="criteria-label">No criteria earned</div>
                </div>
            `;
        }

        return `
            <div class="score-room-section ${roomType}">
                <div class="score-room-header">
                    <h4>
                        <i class="fas ${icon}"></i>
                        ${title}
                    </h4>
                    <div class="score-room-total">${roomScore} pts</div>
                </div>
                <div class="score-criteria">
                    ${criteriaHTML}
                </div>
            </div>
        `;
    }

    /**
     * Format criteria key into readable label
     */
    formatCriteriaLabel(key) {
        const labelMap = {
            // Problem Room
            revenue: 'Revenue Match',
            company_size: 'Company Size',
            industry_alignment: 'Industry Alignment',
            target_states: 'Target States',
            visited_target_pages: 'Visited Target Pages',
            multiple_visits: 'Multiple Visits',
            role_match: 'Role Match',
            minimum_threshold: 'Minimum Threshold',
            
            // Solution Room
            email_open: 'Email Opens',
            email_click: 'Email Clicks',
            email_multiple_click: 'Multiple Email Clicks',
            page_visit: 'Page Visits',
            key_page_visit: 'Key Page Visits',
            ad_engagement: 'Ad Engagement',
            
            // Offer Room
            demo_request: 'Demo Request',
            contact_form: 'Contact Form Submission',
            pricing_page: 'Pricing Page Visit',
            pricing_question: 'Pricing Question',
            partner_referral: 'Partner Referral',
            webinar_attendance: 'Webinar Attendance'
        };

        return labelMap[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    /**
     * Format room name for display
     */
    formatRoom(room) {
        const roomMap = {
            none: 'Not Qualified',
            problem: 'Problem',
            solution: 'Solution',
            offer: 'Offer'
        };
        return roomMap[room] || room;
    }

    /**
     * Show loading state
     */
    showLoading(prospectName) {
        const body = this.modal.querySelector('.score-breakdown-body');
        if (!body) return;

        body.innerHTML = `
            <div class="score-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading score breakdown for ${this.escapeHtml(prospectName)}...</p>
            </div>
        `;
    }

    /**
     * Show error message
     */
    showError(message) {
        const body = this.modal.querySelector('.score-breakdown-body');
        if (!body) return;

        body.innerHTML = `
            <div class="score-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>${this.escapeHtml(message)}</p>
                <button class="btn-secondary" onclick="this.closest('.rtr-modal').style.display='none'">
                    Close
                </button>
            </div>
        `;
    }

    /**
     * Close modal
     */
    close() {
        if (!this.modal || !this.isOpen) return;

        this.modal.style.display = 'none';
        this.isOpen = false;
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}