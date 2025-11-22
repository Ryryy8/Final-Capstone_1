// Admin Date Blocking Management
class AdminDateBlockingManager {
    constructor() {
        this.apiBaseUrl = '../backend/blocked_dates.php';
        this.blockedDates = [];
        this.init();
    }

    async init() {
        await this.loadBlockedDates();
        this.setupEventListeners();
        this.renderBlockedDatesList();
    }

    // Load blocked dates from backend
    async loadBlockedDates() {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=list`);
            const result = await response.json();
            
            if (result.success) {
                this.blockedDates = result.data;
                console.log('Admin loaded blocked dates:', this.blockedDates);
            } else {
                console.error('Error loading blocked dates:', result.error);
                this.showToast('Error loading blocked dates', 'error');
            }
        } catch (error) {
            console.error('Network error loading blocked dates:', error);
            this.showToast('Network error loading blocked dates', 'error');
        }
    }

    // Add new blocked date
    async addBlockedDate(dateData) {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=add`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(dateData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                await this.loadBlockedDates(); // Reload to get updated list
                this.renderBlockedDatesList();
                this.showToast('Date blocked successfully', 'success');
                return result;
            } else {
                throw new Error(result.error || 'Failed to block date');
            }
        } catch (error) {
            console.error('Error blocking date:', error);
            this.showToast('Error: ' + error.message, 'error');
            throw error;
        }
    }

    // Remove blocked date
    async removeBlockedDate(blockedId) {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=remove&id=${blockedId}`, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            
            if (result.success) {
                await this.loadBlockedDates(); // Reload to get updated list
                this.renderBlockedDatesList();
                this.showToast('Blocked date removed successfully', 'success');
                return result;
            } else {
                throw new Error(result.error || 'Failed to remove blocked date');
            }
        } catch (error) {
            console.error('Error removing blocked date:', error);
            this.showToast('Error: ' + error.message, 'error');
            throw error;
        }
    }

    // Render blocked dates list
    renderBlockedDatesList() {
        const container = document.getElementById('blockedDatesList');
        if (!container) return;

        if (this.blockedDates.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Blocked Dates</h3>
                    <p>No dates are currently blocked for inspections.</p>
                </div>
            `;
            return;
        }

        // Sort by date
        const sortedDates = [...this.blockedDates].sort((a, b) => new Date(a.date) - new Date(b.date));

        container.innerHTML = `
            <div class="blocked-dates-grid">
                ${sortedDates.map(blocked => this.renderBlockedDateCard(blocked)).join('')}
            </div>
        `;
    }

    // Render individual blocked date card
    renderBlockedDateCard(blocked) {
        const date = new Date(blocked.date);
        const formattedDate = date.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        const priorityClass = blocked.priority === 'high' ? 'high' : 
                             blocked.priority === 'medium' ? 'medium' : 'low';

        const categoryIcon = this.getCategoryIcon(blocked.category);

        return `
            <div class="blocked-date-card" data-id="${blocked.id}">
                <div class="card-header">
                    <div class="date-info">
                        <i class="fas fa-calendar-alt"></i>
                        <div>
                            <h4>${formattedDate}</h4>
                            <span class="date-value">${blocked.date}</span>
                        </div>
                    </div>
                    <div class="priority-badge priority-${priorityClass}">
                        ${blocked.priority.toUpperCase()}
                    </div>
                </div>
                
                <div class="card-body">
                    <div class="category-info">
                        <i class="${categoryIcon}"></i>
                        <span class="category-name">${this.formatCategory(blocked.category)}</span>
                    </div>
                    
                    <div class="reason-info">
                        <i class="fas fa-info-circle"></i>
                        <p class="reason-text">${blocked.reason}</p>
                    </div>
                    
                    <div class="meta-info">
                        <small class="created-by">
                            <i class="fas fa-user"></i> Created by: ${blocked.created_by}
                        </small>
                        <small class="created-date">
                            <i class="fas fa-clock"></i> ${new Date(blocked.created_at).toLocaleDateString()}
                        </small>
                    </div>
                </div>
                
                <div class="card-actions">
                    <button class="btn-remove" onclick="adminDateManager.confirmRemoveBlockedDate(${blocked.id})">
                        <i class="fas fa-trash"></i> Remove Block
                    </button>
                </div>
            </div>
        `;
    }

    // Get category icon
    getCategoryIcon(category) {
        const icons = {
            'building': 'fas fa-building',
            'machinery': 'fas fa-cogs',
            'electrical': 'fas fa-bolt',
            'all': 'fas fa-ban'
        };
        return icons[category.toLowerCase()] || 'fas fa-calendar-times';
    }

    // Format category name
    formatCategory(category) {
        if (category === 'all') return 'All Categories';
        return category.charAt(0).toUpperCase() + category.slice(1);
    }

    // Confirm removal
    confirmRemoveBlockedDate(blockedId) {
        const blocked = this.blockedDates.find(b => b.id == blockedId);
        if (!blocked) return;

        if (confirm(`Are you sure you want to remove the block for ${blocked.date}?\n\nThis will make the date available for ${this.formatCategory(blocked.category)} inspections again.`)) {
            this.removeBlockedDate(blockedId);
        }
    }

    // Setup event listeners
    setupEventListeners() {
        // Add blocked date form
        const addForm = document.getElementById('addBlockedDateForm');
        if (addForm) {
            addForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleAddBlockedDate(addForm);
            });
        }

        // Refresh button
        const refreshBtn = document.getElementById('refreshBlockedDates');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', async () => {
                await this.loadBlockedDates();
                this.renderBlockedDatesList();
                this.showToast('Blocked dates refreshed', 'info');
            });
        }
    }

    // Handle add blocked date form submission
    async handleAddBlockedDate(form) {
        const formData = new FormData(form);
        const dateData = {
            date: formData.get('blockDate'),
            category: formData.get('blockCategory'),
            reason: formData.get('blockReason'),
            priority: formData.get('blockPriority'),
            created_by: 'Admin' // In real app, get from session
        };

        try {
            await this.addBlockedDate(dateData);
            form.reset();
        } catch (error) {
            // Error already handled in addBlockedDate method
        }
    }

    // Show toast notification
    showToast(message, type = 'info') {
        // Remove existing toast
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }

        // Create new toast
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);

        // Show toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);

        // Hide toast after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }

    // Get statistics
    getStatistics() {
        const total = this.blockedDates.length;
        const byCategory = this.blockedDates.reduce((acc, blocked) => {
            acc[blocked.category] = (acc[blocked.category] || 0) + 1;
            return acc;
        }, {});
        const byPriority = this.blockedDates.reduce((acc, blocked) => {
            acc[blocked.priority] = (acc[blocked.priority] || 0) + 1;
            return acc;
        }, {});

        return { total, byCategory, byPriority };
    }

    // Render statistics
    renderStatistics() {
        const statsContainer = document.getElementById('blockedDatesStats');
        if (!statsContainer) return;

        const stats = this.getStatistics();
        
        statsContainer.innerHTML = `
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.total}</h3>
                        <p>Total Blocked Dates</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.byPriority.high || 0}</h3>
                        <p>High Priority Blocks</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.byCategory.all || 0}</h3>
                        <p>All-Category Blocks</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.byCategory.building || 0}</h3>
                        <p>Building Blocks</p>
                    </div>
                </div>
            </div>
        `;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.adminDateManager = new AdminDateBlockingManager();
});