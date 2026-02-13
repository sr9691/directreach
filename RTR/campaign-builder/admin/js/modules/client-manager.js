/**
 * Client Manager Module
 * 
 * Handles client listing, search, selection, and creation
 * 
 * @package DirectReach_Campaign_Builder
 * @since 2.0.0
 */

import EventEmitter from '../utils/event-emitter.js';
import APIClient from '../utils/api-client.js';

export default class ClientManager extends EventEmitter {
    constructor(config, settingsManager = null) {
        super();
        
        this.config = config;
        this.api = new APIClient(config.apiUrl, config.nonce);
        this.settingsManager = settingsManager; // NEW: Settings panel manager
        
        this.clients = [];
        this.filteredClients = [];
        this.selectedClient = null;
        this.isLoading = false;
        this.searchTerm = '';
        
        this.renderDebounced = this.debounce(this.renderClients.bind(this), 100);

        this.elements = {
            container: document.querySelector('.client-step-container'),
            searchInput: document.getElementById('client-search'),
            toggleCreateBtn: document.getElementById('toggle-create-client'),
            createForm: document.getElementById('create-client-form'),
            newClientForm: document.getElementById('new-client-form'),
            cancelCreateBtns: [
                document.getElementById('cancel-create-client'),
                document.getElementById('cancel-create-client-bottom')
            ],
            loadingState: document.getElementById('clients-loading'),
            emptyState: document.getElementById('clients-empty'),
            errorState: document.getElementById('clients-error'),
            errorMessage: document.getElementById('error-message'),
            retryBtn: document.getElementById('retry-load-clients'),
            clientsList: document.getElementById('clients-list'),
        };
        
        this.init();
    }
    
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Initialize the client manager
     */
    init() {
        this.attachEventListeners();
        this.loadClients();
    }
    
    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Search input
        if (this.elements.searchInput) {
            this.elements.searchInput.addEventListener('input', (e) => {
                this.handleSearch(e.target.value);
            });
        }
        
        // Toggle create form
        if (this.elements.toggleCreateBtn) {
            this.elements.toggleCreateBtn.addEventListener('click', () => {
                this.showCreateForm();
            });
        }
        
        // Cancel create form
        this.elements.cancelCreateBtns.forEach(btn => {
            if (btn) {
                btn.addEventListener('click', () => {
                    this.hideCreateForm();
                });
            }
        });
        
        // Submit new client form
        if (this.elements.newClientForm) {
            this.elements.newClientForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleCreateClient(e);
            });
        }
        
        // Retry button
        if (this.elements.retryBtn) {
            this.elements.retryBtn.addEventListener('click', () => {
                this.loadClients();
            });
        }

        // Event delegation for client cards - UPDATED
        if (this.elements.clientsList) {
            this.elements.clientsList.addEventListener('click', (e) => {
                // Check if run nightly job button was clicked
                const runJobBtn = e.target.closest('.run-nightly-job-btn');
                if (runJobBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    const card = runJobBtn.closest('.client-card');
                    if (card) {
                        const clientId = parseInt(card.dataset.clientId);
                        this.handleRunNightlyJob(clientId, runJobBtn);
                    }
                    return;
                }

                // Check if configure button was clicked
                const configureBtn = e.target.closest('.configure-client-btn');
                if (configureBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    const card = configureBtn.closest('.client-card');
                    if (card) {
                        const clientId = parseInt(card.dataset.clientId);
                        this.handleConfigureClick(clientId);
                    }
                    return;
                }

                // Check if journey circle button was clicked
                const journeyCircleBtn = e.target.closest('.journey-circle-btn');
                if (journeyCircleBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    const card = journeyCircleBtn.closest('.client-card');
                    if (card) {
                        const clientId = parseInt(card.dataset.clientId);
                        this.handleJourneyCircleClick(clientId);
                    }
                    return;
                }
                
                // Otherwise handle card selection
                const card = e.target.closest('.client-card');
                if (card) {
                    const clientId = parseInt(card.dataset.clientId);
                    this.selectClient(clientId);
                }
            });
        }
    }
    
    /**
     * Load all clients from API
     */
    async loadClients() {
        console.log('In Loading clients...', this.clients.length);
        if (this.clients.length === 0) {
            this.showLoadingState();
        }
        
        this.isLoading = true;
        
        try {
            const response = await this.api.get('/clients');
            
            if (response.success) {
                console.log('After get client...', this.clients.length);
                this.clients = response.data || [];
                this.filteredClients = [...this.clients];
                this.renderClients();
                this.emit('clients:loaded', this.clients);
            } else {
                throw new Error(response.message || 'Failed to load clients');
            }
        } catch (error) {
            console.error('Error loading clients:', error);
            this.showErrorState(error.message);
            this.emit('clients:error', error);
        } finally {
            this.isLoading = false;
        }
    }
    
    /**
     * Handle search input
     */
    handleSearch(term) {
        this.searchTerm = term.toLowerCase().trim();
        
        if (this.searchTerm === '') {
            this.filteredClients = [...this.clients];
        } else {
            this.filteredClients = this.clients.filter(client => {
                return client.name.toLowerCase().includes(this.searchTerm) ||
                       client.accountId.toLowerCase().includes(this.searchTerm);
            });
        }
        
        this.renderClients();
    }
    
    /**
     * Render client list
     */
    renderClients() {
        if (this.filteredClients.length === 0) {
            this.showEmptyState();
            return;
        }
        
        this.showClientsList();
        
        const html = this.filteredClients.map(client => this.renderClientCard(client)).join('');
        this.elements.clientsList.innerHTML = html;
    }
    
    /**
     * Render single client card
     */
    renderClientCard(client) {
        const isSelected = this.selectedClient && this.selectedClient.id === client.id;
        const logoHtml = client.logoUrl 
            ? `<img src="${this.escapeHtml(client.logoUrl)}" alt="${this.escapeHtml(client.name)}" class="client-logo" />`
            : `<div class="client-logo-placeholder"><i class="fas fa-building"></i></div>`;
        
        return `
            <div class="client-card ${isSelected ? 'selected' : ''}" data-client-id="${client.id}">
                ${logoHtml}
                <div class="client-card-header">
                    <div class="client-info">
                        <h4 class="client-name">${this.escapeHtml(client.name)}</h4>
                        <p class="client-account-id">ID: ${this.escapeHtml(client.accountId)}</p>
                    </div>
                    ${isSelected ? '<i class="fas fa-check-circle selected-icon"></i>' : ''}
                </div>
                <div class="client-card-body">
                    <div class="client-meta">
                        <button type="button" 
                                class="badge run-nightly-job-btn" 
                                title="Run Nightly Job for this Client" 
                                aria-label="Run nightly job for this client">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <button type="button" 
                                class="badge badge-premium configure-client-btn" 
                                title="Configure Settings" 
                                aria-label="Configure client settings">
                            <i class="fas fa-cog"></i>
                        </button>
                        // Journey Circle Creator button
                        <button type="button" 
                                class="badge badge-info journey-circle-btn" 
                                title="Journey Circle Creator" 
                                aria-label="Open Journey Circle Creator">
                            <i class="fas fa-circle-notch"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Handle configure button click - NEW
     */
    handleConfigureClick(clientId) {
        const client = this.clients.find(c => c.id === clientId);
        
        if (!client) {
            console.error('Client not found:', clientId);
            return;
        }

        // Open side panel with client data
        if (this.settingsManager) {
            this.settingsManager.openPanel(client);
        } else {
            console.warn('Settings manager not initialized');
            this.emit('notification', {
                type: 'warning',
                message: 'Settings panel not available'
            });
        }

        // Emit event
        this.emit('client:configure', { clientId, client });
    }

    /**
     * Handle run nightly job button click - NEW
     */
    async handleRunNightlyJob(clientId, button) {
        const client = this.clients.find(c => c.id === clientId);
        
        if (!client) {
            console.error('Client not found:', clientId);
            return;
        }

        // Confirm action
        if (!confirm(`Run nightly job for "${client.name}"?\n\nThis will process all visitors, calculate scores, and update prospects for this client.`)) {
            return;
        }

        // Disable button and show loading state
        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        button.classList.add('running');

        try {
            const response = await this.api.post('/jobs/run-nightly', {
                mode: 'client',
                client_id: clientId
            });

            if (response.success) {
                const stats = response.stats || {};
                this.emit('notification', {
                    type: 'success',
                    message: `Nightly job completed for ${client.name} in ${response.duration}s. ` +
                             `Created: ${stats.prospects_created || 0}, ` +
                             `Updated: ${stats.prospects_updated || 0}, ` +
                             `Scores: ${stats.scores_calculated || 0}`
                });

                this.emit('client:job_completed', { clientId, client, stats: response.stats });
            } else {
                throw new Error(response.message || 'Job failed');
            }
        } catch (error) {
            console.error('Error running nightly job:', error);
            this.emit('notification', {
                type: 'error',
                message: `Failed to run nightly job: ${error.message}`
            });
        } finally {
            // Restore button state
            button.disabled = false;
            button.innerHTML = originalHtml;
            button.classList.remove('running');
        }
    }    

    handleJourneyCircleClick(clientId) {
        const client = this.clients.find(c => c.id === clientId);
        
        if (!client) {
            console.error('Client not found:', clientId);
            return;
        }

        // Store client info in sessionStorage for Journey Circle to use
        sessionStorage.setItem('dr_journey_client', JSON.stringify({
            clientId: clientId,
            clientName: client.name,
            accountId: client.accountId
        }));

        // Emit event before navigation
        this.emit('client:journey_circle', { clientId, client });

        // Navigate to Journey Circle Creator page
        window.location.href = `admin.php?page=dr-journey-circle&client_id=${clientId}`;
    }

    
    /**
     * Select a client
     */
    selectClient(clientId) {
        const client = this.clients.find(c => c.id === clientId);
        
        if (!client) {
            console.error('Client not found:', clientId);
            return;
        }
        
        this.selectedClient = client;
        this.renderClients(); // Re-render to show selection
        this.emit('client:selected', client);
    }
    
    /**
     * Show create form
     */
    showCreateForm() {
        if (this.elements.createForm) {
            this.elements.createForm.style.display = 'block';
            this.elements.createForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            
            // Focus first input
            setTimeout(() => {
                document.getElementById('new-client-name')?.focus();
            }, 100);
        }
    }
    
    /**
     * Hide create form
     */
    hideCreateForm() {
        if (this.elements.createForm) {
            this.elements.createForm.style.display = 'none';
        }
        
        // Reset form
        if (this.elements.newClientForm) {
            this.elements.newClientForm.reset();
        }
    }
    
    /**
     * Handle create client form submission
     */
    async handleCreateClient(event) {
        const formData = new FormData(event.target);
        const clientData = {
            clientName: formData.get('clientName'),
            accountId: formData.get('accountId'),
            logoUrl: formData.get('logoUrl'),
            webpageUrl: formData.get('webpageUrl'),
            crmEmail: formData.get('crmEmail'),
        };
        
        // Validate required fields
        if (!clientData.clientName || !clientData.accountId) {
            this.emit('notification', {
                type: 'error',
                message: 'Client name and Account ID are required'
            });
            return;
        }
        
        // Disable form during submission
        const submitBtn = event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        
        try {
            const response = await this.api.post('/clients', clientData);
            
            if (response.success) {
                this.emit('notification', {
                    type: 'success',
                    message: 'Client created successfully'
                });
                
                // Add new client to list
                this.clients.push(response.data);
                this.filteredClients = [...this.clients];
                
                // Select the new client
                this.selectClient(response.data.id);
                
                // Hide form and re-render
                this.hideCreateForm();
                this.renderClients();
                
                this.emit('client:created', response.data);
            } else {
                throw new Error(response.message || 'Failed to create client');
            }
        } catch (error) {
            console.error('Error creating client:', error);
            this.emit('notification', {
                type: 'error',
                message: error.message || 'Failed to create client'
            });
        } finally {
            // Re-enable form
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }
    
    /**
     * Get selected client
     */
    getSelectedClient() {
        return this.selectedClient;
    }
    
    /**
     * Set selected client (for state restoration)
     */
    setSelectedClient(clientId) {
        if (clientId) {
            this.selectClient(clientId);
        }
    }

    /**
     * Refresh client data after settings update - NEW
     */
    async refreshClient(clientId) {
        try {
            const response = await this.api.get(`/clients/${clientId}`);
            
            if (response.success) {
                // Update in clients array
                const index = this.clients.findIndex(c => c.id === clientId);
                if (index !== -1) {
                    this.clients[index] = response.data;
                }

                // Update in filtered list
                const filteredIndex = this.filteredClients.findIndex(c => c.id === clientId);
                if (filteredIndex !== -1) {
                    this.filteredClients[filteredIndex] = response.data;
                }

                // Re-render to show updated data
                this.renderClients();

                this.emit('client:refreshed', response.data);
            }
        } catch (error) {
            console.error('Error refreshing client:', error);
        }
    }
    
    /**
     * Show loading state
     */
    showLoadingState() {
        this.elements.loadingState.style.display = 'flex';
        this.elements.emptyState.style.display = 'none';
        this.elements.errorState.style.display = 'none';
        this.elements.clientsList.style.display = 'none';
    }
    
    /**
     * Show empty state
     */
    showEmptyState() {
        this.elements.loadingState.style.display = 'none';
        this.elements.emptyState.style.display = 'flex';
        this.elements.errorState.style.display = 'none';
        this.elements.clientsList.style.display = 'none';
    }
    
    /**
     * Show error state
     */
    showErrorState(message) {
        this.elements.loadingState.style.display = 'none';
        this.elements.emptyState.style.display = 'none';
        this.elements.errorState.style.display = 'flex';
        this.elements.clientsList.style.display = 'none';
        
        if (this.elements.errorMessage) {
            this.elements.errorMessage.textContent = message;
        }
    }
    
    /**
     * Show clients list
     */
    showClientsList() {
        this.elements.loadingState.style.display = 'none';
        this.elements.emptyState.style.display = 'none';
        this.elements.errorState.style.display = 'none';
        this.elements.clientsList.style.display = 'grid';
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