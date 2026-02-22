/**
 * Room Manager - No Toggle Version
 * Handles room cards display and analytics
 * 
 */

export default class RoomManager {
    constructor(config) {
        this.config = config || window.rtrDashboardConfig || {};
        // Handle both string and object config
        if (typeof config === 'string') {
            this.apiUrl = config;
        } else {
            this.apiUrl = config?.restUrl || config?.apiUrl || window.rtrDashboardConfig?.restUrl || window.rtrDashboardConfig?.apiUrl || '';
        }
        this.nonce = config?.nonce || window.rtrDashboardConfig?.nonce || '';
        this.currentData = null;
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadRoomCounts();
    }

    setupEventListeners() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('.rtr-analytics-btn')) {
                e.stopPropagation();
                if (e.target.closest('.rtr-room-card')) {
                    e.preventDefault();
                    const room = e.target.closest('.rtr-room-card').dataset.room;
                    if (room) {
                        document.dispatchEvent(new CustomEvent('rtr:openAnalytics', {
                            detail: { room }
                        }));
                    }
                }
            }
        });

        document.addEventListener('rtr:filterChanged', () => {
            this.loadRoomCounts();
        });

        // Listen for client/date filter changes
        const clientSelect = document.getElementById('client-select');
        const dateFilter = document.getElementById('date-filter');
        
        if (clientSelect) {
            clientSelect.addEventListener('change', () => {
                document.dispatchEvent(new CustomEvent('rtr:filterChanged'));
            });
        }

        if (dateFilter) {
            dateFilter.addEventListener('change', () => {
                document.dispatchEvent(new CustomEvent('rtr:filterChanged'));
            });
        }

    }

    async loadRoomCounts() {
        try {
            const clientFilter = document.getElementById('client-select');
            
            const url = new URL(`${this.apiUrl}/analytics/room-counts`, window.location.origin);
            
            if (clientFilter && clientFilter.value) {
                url.searchParams.append('client_id', clientFilter.value);
            }

            const dateFilter = document.getElementById('date-filter');
            if (dateFilter && dateFilter.value) {
                url.searchParams.append('days', dateFilter.value);
            }

            const response = await fetch(url, {
                headers: {
                    'X-WP-Nonce': this.nonce
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const apiResponse = await response.json();
            
            // API now returns: { success: true, data: {...counts}, analytics: {...stats}, total: 0 }
            if (!apiResponse.success || !apiResponse.data) {
                throw new Error('Invalid API response format');
            }
            
            this.currentData = apiResponse.data;
            this.currentAnalytics = apiResponse.analytics || {}; // Store analytics data
            
            this.renderRoomCards(apiResponse.data);
        } catch (error) {
            console.error('Failed to load room counts:', error);
            document.dispatchEvent(new CustomEvent('rtr:showError', {
                detail: { message: 'Failed to load room statistics' }
            }));
            
            // Render empty cards on error
            this.currentAnalytics = {};
            this.renderRoomCards({
                problem: 0,
                solution: 0,
                offer: 0,
                sales: 0
            });
        }
    }

    renderRoomCards(data) {
        const container = document.getElementById('rtr-room-cards-container');
        if (!container) {
            console.error('Room cards container not found');
            return;
        }

        // Extract counts and analytics from API response
        const counts = data;
        const analytics = this.currentAnalytics || {}; // Will be set by loadRoomCounts

        const rooms = ['problem', 'solution', 'offer', 'sales'];
        const roomConfig = {
            problem: {
                icon: 'alert-circle',
                color: 'red',
                title: 'Problem Room',
                subtitle: 'Attract Phase',
                label: 'Active Prospects'
            },
            solution: {
                icon: 'lightbulb',
                color: 'yellow',
                title: 'Solution Room',
                subtitle: 'Identify & Nurture',
                label: 'Engaged Visitors'
            },
            offer: {
                icon: 'handshake',
                color: 'green',
                title: 'Offer Room',
                subtitle: 'Invite & Close',
                label: 'Sales Ready'
            },
            sales: {
                icon: 'trophy',
                color: 'purple',
                title: 'Sales Room',
                subtitle: 'Negotiate & Convert',
                label: 'Sales Handoffs'
            }
        };

        const html = rooms.map(room => {
            const config = roomConfig[room];
            const count = counts[room] || 0;
            const roomAnalytics = analytics[room] || {};

            // Get stat values based on room type
            let stat1Value, stat1Label, stat2Value, stat2Label;

            switch(room) {
                case 'problem':
                    stat1Value = roomAnalytics.new_today || 0;
                    stat1Label = 'New Today';
                    stat2Value = (roomAnalytics.progress_rate || 0) + '%';
                    stat2Label = 'Progress Rate';
                    break;
                case 'solution':
                    stat1Value = roomAnalytics.high_scores || 0;
                    stat1Label = 'High Scores';
                    stat2Value = (roomAnalytics.open_rate || 0) + '%';
                    stat2Label = 'Open Rate';
                    break;
                case 'offer':
                    stat1Value = roomAnalytics.high_scores || 0;
                    stat1Label = 'High Scores';
                    stat2Value = (roomAnalytics.click_rate || 0) + '%';
                    stat2Label = 'Click Rate';
                    break;
                case 'sales':
                    stat1Value = roomAnalytics.this_week || 0;
                    stat1Label = 'This Week';
                    stat2Value = (roomAnalytics.avg_days || 0);
                    stat2Label = 'Avg Days';
                    break;
            }

            return `
                <div class="rtr-room-card rtr-room-${config.color}" 
                    data-room="${room}"
                    role="article"
                    aria-label="${config.title}">
                    <div class="rtr-room-card-inner">
                        <div class="rtr-room-header">
                            <div class="rtr-room-icon">
                                ${this.getIcon(config.icon)}
                            </div>
                            <div class="rtr-room-title-section">
                                <h3 class="rtr-room-title">${config.title}</h3>
                                <p class="rtr-room-subtitle">${config.subtitle}</p>
                            </div>
                            <button class="rtr-analytics-btn" 
                                    data-room="${room}"
                                    aria-label="View ${config.title} analytics"
                                    title="View analytics">
                                <svg class="rtr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="20" x2="18" y2="10"/>
                                    <line x1="12" y1="20" x2="12" y2="4"/>
                                    <line x1="6" y1="20" x2="6" y2="14"/>
                                </svg>
                            </button>
                        </div>
                        
                        <div class="rtr-room-count">
                            <span class="rtr-count-value">${count}</span>
                            <span class="rtr-count-label">${config.label}</span>
                        </div>

                        <div class="rtr-room-stats">
                            <div class="rtr-stats-grid">
                                <div class="rtr-stat-item">
                                    <span class="rtr-stat-value">${stat1Value}</span>
                                    <span class="rtr-stat-label">${stat1Label}</span>
                                </div>
                                <div class="rtr-stat-item">
                                    <span class="rtr-stat-value">${stat2Value}</span>
                                    <span class="rtr-stat-label">${stat2Label}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = html;
    }

    getIcon(iconName) {
        const icons = {
            'alert-circle': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            'lightbulb': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.45-1.95.45-3a6 6 0 0 0-12 0c0 1.05.27 2.02.45 3"/></svg>',
            'handshake': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0-4.4-3.6-8-8-8s-8 3.6-8 8 3.6 8 8 8 8-3.6 8-8Z"/><path d="m9 9 5 5"/><path d="m9 14 5-5"/></svg>',
            'trophy': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>'
        };
        return icons[iconName] || '';
    }
}