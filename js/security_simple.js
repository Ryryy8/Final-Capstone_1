/**
 * SecurityManager - Simplified without CAPTCHA
 * Provides rate limiting, bot detection, and input validation
 */
class SecurityManager {
    constructor() {
        this.apiEndpoint = 'backend/api/secure_request.php';
        this.securityToken = this.generateSecurityToken();
        this.formStartTime = Date.now();
        this.requestAttempts = new Map();
        this.honeypotFields = ['website_url', 'company_url', 'fax_number'];
        
        this.init();
    }
    
    /**
     * Initialize security features
     */
    init() {
        this.setupHoneypotFields();
        this.setupFormValidation();
        this.setupSubmissionThrottling();
        this.setupBehaviorMonitoring();
        
        console.log('SecurityManager initialized (CAPTCHA-free version)');
    }
    
    /**
     * Generate a security token
     */
    generateSecurityToken() {
        return Math.random().toString(36).substr(2, 15) + Date.now().toString(36);
    }
    
    /**
     * Setup invisible honeypot fields to catch bots
     */
    setupHoneypotFields() {
        this.honeypotFields.forEach(fieldName => {
            const field = document.createElement('input');
            field.type = 'text';
            field.name = fieldName;
            field.style.display = 'none';
            field.style.position = 'absolute';
            field.style.left = '-9999px';
            field.style.width = '0';
            field.tabIndex = -1;
            field.setAttribute('autocomplete', 'off');
            
            // Add to forms
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.appendChild(field.cloneNode(true));
            });
        });
    }
    
    /**
     * Validate request before submission (without CAPTCHA)
     */
    async validateRequest(formData, requestType = 'assessment') {
        try {
            // Add security metadata
            const secureFormData = {
                ...formData,
                form_start_time: Math.floor(this.formStartTime / 1000),
                security_token: this.securityToken
            };
            
            // Check honeypot fields
            const honeypotViolation = this.honeypotFields.some(field =>
                secureFormData[field] && secureFormData[field].trim() !== ''
            );
            
            if (honeypotViolation) {
                return {
                    valid: false,
                    error: 'BOT_DETECTED',
                    message: 'Automated submission detected.'
                };
            }
            
            // Check local rate limiting
            const email = formData.email || formData.clientEmail || formData.applicant_email;
            if (email && !this.checkLocalRateLimit(email)) {
                return {
                    valid: false,
                    error: 'LOCAL_RATE_LIMIT',
                    message: 'Please wait before submitting another request. You have reached the limit of 3 requests per hour.'
                };
            }
            
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'validate_request',
                    request_data: secureFormData,
                    request_type: requestType
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Update local tracking
                if (email) {
                    this.updateLocalAttempts(email);
                }
                
                return {
                    valid: true,
                    message: result.data.message || 'Request validated successfully',
                    client_data: result.data.client_data
                };
            } else {
                return {
                    valid: false,
                    error: result.error.code,
                    message: result.error.message,
                    retry_after: result.error.retry_after
                };
            }
        } catch (error) {
            console.error('Request validation error:', error);
            return {
                valid: false,
                error: 'VALIDATION_ERROR',
                message: 'Unable to validate request. Please try again.'
            };
        }
    }
    
    /**
     * Check local rate limiting (3 requests per hour)
     */
    checkLocalRateLimit(email) {
        const now = Date.now();
        const attempts = this.requestAttempts.get(email) || [];
        
        // Remove attempts older than 1 hour
        const recentAttempts = attempts.filter(time => now - time < 3600000);
        
        // Check if under limit (3 requests per hour)
        return recentAttempts.length < 3;
    }
    
    /**
     * Update local attempt tracking
     */
    updateLocalAttempts(email) {
        const now = Date.now();
        const attempts = this.requestAttempts.get(email) || [];
        attempts.push(now);
        
        // Keep only recent attempts
        const recentAttempts = attempts.filter(time => now - time < 3600000);
        this.requestAttempts.set(email, recentAttempts);
    }
    
    /**
     * Setup form validation with security checks
     */
    setupFormValidation() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', async (e) => {
                // Check if form was filled too quickly (bot detection)
                const fillTime = (Date.now() - this.formStartTime) / 1000;
                if (fillTime < 10) {
                    e.preventDefault();
                    this.showSecurityMessage('Please take more time to fill out the form properly.', 'warning');
                    return false;
                }
                
                // Basic rate limiting check
                const formData = new FormData(form);
                const email = formData.get('email') || formData.get('clientEmail');
                if (email && !this.checkLocalRateLimit(email)) {
                    e.preventDefault();
                    this.showSecurityMessage('You have reached the limit of 3 requests per hour. Please wait before submitting again.', 'error');
                    return false;
                }
            });
        });
    }
    
    /**
     * Show security message to user
     */
    showSecurityMessage(message, type = 'info') {
        // Create or update security message div
        let messageDiv = document.getElementById('security-message');
        if (!messageDiv) {
            messageDiv = document.createElement('div');
            messageDiv.id = 'security-message';
            messageDiv.style.position = 'fixed';
            messageDiv.style.top = '20px';
            messageDiv.style.right = '20px';
            messageDiv.style.padding = '15px';
            messageDiv.style.borderRadius = '5px';
            messageDiv.style.color = 'white';
            messageDiv.style.fontWeight = 'bold';
            messageDiv.style.zIndex = '10000';
            messageDiv.style.maxWidth = '300px';
            document.body.appendChild(messageDiv);
        }
        
        // Set style based on type
        const colors = {
            info: '#17a2b8',
            success: '#28a745',
            warning: '#ffc107',
            error: '#dc3545'
        };
        
        messageDiv.style.backgroundColor = colors[type] || colors.info;
        messageDiv.style.color = type === 'warning' ? '#000' : '#fff';
        messageDiv.textContent = message;
        messageDiv.style.display = 'block';
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            messageDiv.style.display = 'none';
        }, 5000);
    }
    
    /**
     * Setup submission throttling (only for assessment forms, not login)
     */
    setupSubmissionThrottling() {
        let lastSubmission = 0;
        const minInterval = 5000; // 5 seconds between submissions
        
        document.addEventListener('submit', (e) => {
            // Skip throttling for login forms with multiple detection methods
            const form = e.target;
            
            // Method 1: Check for login-form class
            if (form.classList.contains('login-form')) {
                console.log('SecurityManager: Allowing login form submission (class detected)');
                return;
            }
            
            // Method 2: Check if inside login modal
            if (form.closest('#loginModal')) {
                console.log('SecurityManager: Allowing login form submission (modal detected)');
                return;
            }
            
            // Method 3: Check for username AND password fields
            const hasUsername = form.querySelector('#username, input[name="username"], input[type="text"][id*="user"]');
            const hasPassword = form.querySelector('#password, input[name="password"], input[type="password"]');
            if (hasUsername && hasPassword) {
                console.log('SecurityManager: Allowing login form submission (login fields detected)');
                return;
            }
            
            // Method 4: Check for role field (specific to login)
            const hasRole = form.querySelector('#role, select[name="role"]');
            if (hasRole && (hasUsername || hasPassword)) {
                console.log('SecurityManager: Allowing login form submission (role field detected)');
                return;
            }
            
            // Apply throttling only to other forms (assessment/contact forms)
            const now = Date.now();
            if (now - lastSubmission < minInterval) {
                console.log('SecurityManager: Blocking form submission due to throttling');
                e.preventDefault();
                this.showSecurityMessage('Please wait a moment before submitting again.', 'warning');
                return;
            }
            
            console.log('SecurityManager: Allowing form submission');
            lastSubmission = now;
        });
    }
    
    /**
     * Setup behavior monitoring
     */
    setupBehaviorMonitoring() {
        let suspiciousActivity = 0;
        
        // Monitor rapid clicking
        let clickCount = 0;
        document.addEventListener('click', () => {
            clickCount++;
            setTimeout(() => clickCount--, 1000);
            
            if (clickCount > 10) {
                suspiciousActivity++;
                console.warn('Suspicious clicking behavior detected');
            }
        });
        
        // Monitor copy-paste in important fields
        const importantFields = document.querySelectorAll('input[type="email"], input[name*="name"]');
        importantFields.forEach(field => {
            field.addEventListener('paste', () => {
                console.log('Paste detected in important field');
            });
        });
    }
    
    /**
     * Get security status
     */
    getSecurityStatus() {
        return {
            tokenGenerated: !!this.securityToken,
            honeypotFieldsActive: this.honeypotFields.length > 0,
            behaviorMonitoring: true,
            rateLimitingActive: true,
            captchaEnabled: false // CAPTCHA disabled
        };
    }
}

// Initialize security manager when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.securityManager === 'undefined') {
        window.securityManager = new SecurityManager();
        console.log('Security Manager initialized successfully (No CAPTCHA)');
    }
});

// Make SecurityManager available globally
window.SecurityManager = SecurityManager;