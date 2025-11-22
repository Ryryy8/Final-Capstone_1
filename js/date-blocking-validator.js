// Client-side date blocking validation
class DateBlockingValidator {
    constructor() {
        this.blockedDates = [];
        this.apiBaseUrl = './backend/blocked_dates.php';
        this.init();
    }

    async init() {
        try {
            await this.loadBlockedDates();
        } catch (error) {
            console.warn('Failed to load blocked dates:', error);
        }
    }

    // Load blocked dates from backend
    async loadBlockedDates() {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=list`);
            const result = await response.json();
            
            if (result.success) {
                this.blockedDates = result.data;
                console.log('Loaded blocked dates:', this.blockedDates);
            } else {
                console.error('Error loading blocked dates:', result.error);
            }
        } catch (error) {
            console.error('Network error loading blocked dates:', error);
            throw error;
        }
    }

    // Check if a date is blocked
    isDateBlocked(dateStr) {
        if (!dateStr) return false;
        return this.blockedDates.some(blocked => blocked.date === dateStr);
    }

    // Get blocked date information
    getBlockedDateInfo(dateStr) {
        return this.blockedDates.find(blocked => blocked.date === dateStr);
    }

    // Get all blocked dates (for calendar styling)
    getBlockedDates() {
        return this.blockedDates.map(blocked => blocked.date);
    }

    // Validate form submission
    async validateDateSelection(dateStr) {
        // Refresh blocked dates before validation
        await this.loadBlockedDates();
        
        if (this.isDateBlocked(dateStr)) {
            const blockInfo = this.getBlockedDateInfo(dateStr);
            return {
                valid: false,
                message: `The selected date (${dateStr}) is unavailable. ${blockInfo ? blockInfo.reason : 'Please select another date.'}`,
                blockInfo: blockInfo
            };
        }
        
        return {
            valid: true,
            message: 'Date is available for inspection'
        };
    }

    // Show validation message
    showValidationMessage(message, isError = false) {
        // Remove existing messages
        const existingMsg = document.querySelector('.date-validation-message');
        if (existingMsg) {
            existingMsg.remove();
        }

        // Create new message element
        const messageEl = document.createElement('div');
        messageEl.className = `date-validation-message ${isError ? 'error' : 'success'}`;
        messageEl.textContent = message;
        
        // Style the message
        messageEl.style.cssText = `
            padding: 10px 15px;
            margin: 10px 0;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 500;
            ${isError ? 
                'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 
                'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'
            }
        `;

        // Insert after the calendar container
        const calendarContainer = document.getElementById('calendarContainer');
        if (calendarContainer && calendarContainer.parentNode) {
            calendarContainer.parentNode.insertBefore(messageEl, calendarContainer.nextSibling);
        }
    }

    // Clear validation message
    clearValidationMessage() {
        const existingMsg = document.querySelector('.date-validation-message');
        if (existingMsg) {
            existingMsg.remove();
        }
    }

    // Integrate with existing calendar system
    integrateWithCalendar() {
        // Hook into form submission
        const form = document.querySelector('.getstarted-form');
        if (form) {
            form.addEventListener('submit', async (e) => {
                const dateInput = document.getElementById('requestedDate');
                
                if (dateInput && dateInput.value) {
                    const validation = await this.validateDateSelection(dateInput.value);
                    
                    if (!validation.valid) {
                        e.preventDefault();
                        this.showValidationMessage(validation.message, true);
                        
                        // Scroll to message
                        const messageEl = document.querySelector('.date-validation-message');
                        if (messageEl) {
                            messageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        return false;
                    } else {
                        this.clearValidationMessage();
                    }
                }
            });
        }

        // Hook into date selection
        const dateInput = document.getElementById('requestedDate');
        
        if (dateInput) {
            const validateCurrentSelection = async () => {
                if (dateInput.value) {
                    const validation = await this.validateDateSelection(dateInput.value);
                    
                    if (!validation.valid) {
                        this.showValidationMessage(validation.message, true);
                    } else {
                        this.clearValidationMessage();
                    }
                } else {
                    this.clearValidationMessage();
                }
            };

            // Listen for changes
            dateInput.addEventListener('change', validateCurrentSelection);
        }
    }

    // Style blocked dates in calendar
    async styleBlockedDatesInCalendar() {
        const blockedDates = this.getBlockedDates();
        
        // Find calendar day elements and style blocked dates
        const calendarDays = document.querySelectorAll('.calendar-day');
        calendarDays.forEach(dayEl => {
            const dateStr = dayEl.dataset.date;
            if (dateStr && blockedDates.includes(dateStr)) {
                dayEl.classList.add('blocked-date');
                dayEl.style.cssText += `
                    background-color: #dc3545 !important;
                    color: white !important;
                    cursor: not-allowed !important;
                    opacity: 0.7;
                `;
                dayEl.title = 'This date is not available for inspections';
                
                // Disable click on blocked dates
                dayEl.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const blockInfo = this.getBlockedDateInfo(dateStr);
                    this.showValidationMessage(
                        `Date ${dateStr} is unavailable. ${blockInfo ? blockInfo.reason : 'Please select another date.'}`, 
                        true
                    );
                });
            } else {
                dayEl.classList.remove('blocked-date');
                // Reset styles for non-blocked dates
                dayEl.style.cursor = '';
                dayEl.style.opacity = '';
            }
        });
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.dateBlockingValidator = new DateBlockingValidator();
    
    // Integrate with existing calendar after initialization
    setTimeout(() => {
        window.dateBlockingValidator.integrateWithCalendar();
        
        // Style blocked dates when calendar loads or changes
        setTimeout(() => {
            window.dateBlockingValidator.styleBlockedDatesInCalendar();
        }, 1500);
    }, 1000);
});