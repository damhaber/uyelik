// assets/js/two-factor.js
// Two-Factor Authentication (2FA) Module
// Handles TOTP calculation, QR code scanning, and verification

class TwoFactorManager {
    constructor(options = {}) {
        this.options = {
            apiUrl: '/api/2fa',
            setupEndpoint: '/2fa/setup',
            verifyEndpoint: '/2fa/verify',
            disableEndpoint: '/2fa/disable',
            backupCodesEndpoint: '/2fa/backup-codes',
            totpInterval: 30000, // 30 seconds
            totpDigits: 6,
            ...options
        };

        this.secret = null;
        this.qrCode = null;
        this.totpInterval = null;
        this.currentCode = null;
        this.timeRemaining = 0;
        this.backupCodes = [];
        this.eventListeners = new Map();
        
        // Bind methods
        this.updateTOTP = this.updateTOTP.bind(this);
    }

    // Initialize 2FA setup
    async initializeSetup(containerId, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) {
            throw new Error(`Container element ${containerId} not found`);
        }

        Object.assign(this.options, options);

        // Fetch setup data from server
        await this.fetchSetupData();

        // Render setup container
        this.renderSetupContainer(container);

        // Start TOTP timer
        this.startTOTPTimer();

        return this;
    }

    // Fetch 2FA setup data from server
    async fetchSetupData() {
        try {
            const response = await fetch(this.options.apiUrl + this.options.setupEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    timestamp: Date.now()
                })
            });

            if (!response.ok) {
                throw new Error('Failed to fetch 2FA setup data');
            }

            const data = await response.json();
            this.secret = data.secret;
            this.qrCode = data.qr_code;
            this.backupCodes = data.backup_codes || [];

            return data;
        } catch (error) {
            console.error('Failed to fetch setup data:', error);
            throw error;
        }
    }

    // Generate TOTP code
    generateTOTP(secret, time = Date.now()) {
        if (typeof jsOTP === 'undefined') {
            console.warn('jsOTP library not found, using fallback');
            return this.generateTOTPFallback(secret, time);
        }

        try {
            const totp = new jsOTP.totp();
            const code = totp.getOtp(secret, {
                time: Math.floor(time / 1000),
                digits: this.options.totpDigits,
                interval: this.options.totpInterval / 1000
            });
            
            return code;
        } catch (error) {
            console.error('TOTP generation failed:', error);
            return '000000';
        }
    }

    // Fallback TOTP generator (simplified)
    generateTOTPFallback(secret, time = Date.now()) {
        // This is a simplified fallback - in production, use a proper TOTP library
        const counter = Math.floor(time / this.options.totpInterval);
        const hash = this.simpleHash(secret + counter);
        const code = Math.abs(hash) % Math.pow(10, this.options.totpDigits);
        return code.toString().padStart(this.options.totpDigits, '0');
    }

    // Simple hash function for fallback
    simpleHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash);
    }

    // Update TOTP code and time remaining
    updateTOTP() {
        if (!this.secret) return;

        const now = Date.now();
        const interval = this.options.totpInterval;
        const timeInInterval = now % interval;
        this.timeRemaining = Math.ceil((interval - timeInInterval) / 1000);
        
        // Generate new code only at the start of each interval
        if (timeInInterval < 1000 || !this.currentCode) {
            this.currentCode = this.generateTOTP(this.secret, now);
            this.triggerEvent('codeChanged', this.currentCode);
        }

        // Update UI
        this.updateTOTPDisplay();

        this.triggerEvent('timerUpdate', this.timeRemaining);
    }

    // Start TOTP timer
    startTOTPTimer() {
        this.stopTOTPTimer();
        this.updateTOTP();
        this.totpInterval = setInterval(this.updateTOTP, 1000);
    }

    // Stop TOTP timer
    stopTOTPTimer() {
        if (this.totpInterval) {
            clearInterval(this.totpInterval);
            this.totpInterval = null;
        }
    }

    // Update TOTP display
    updateTOTPDisplay() {
        const codeDisplay = document.getElementById('totp-code');
        if (codeDisplay) {
            codeDisplay.textContent = this.currentCode || '------';
        }

        const timerBar = document.getElementById('totp-timer-bar');
        if (timerBar) {
            const percentage = (this.timeRemaining / (this.options.totpInterval / 1000)) * 100;
            timerBar.style.width = `${percentage}%`;
            
            // Change color based on time remaining
            if (percentage < 20) {
                timerBar.style.backgroundColor = '#dc3545';
            } else if (percentage < 50) {
                timerBar.style.backgroundColor = '#ffc107';
            } else {
                timerBar.style.backgroundColor = '#28a745';
            }
        }

        const timerText = document.getElementById('totp-timer-text');
        if (timerText) {
            timerText.textContent = `${this.timeRemaining}s`;
        }
    }

    // Verify TOTP code
    async verifyCode(code, method = 'totp') {
        try {
            const response = await fetch(this.options.apiUrl + this.options.verifyEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    code: code,
                    method: method,
                    secret: this.secret,
                    timestamp: Date.now()
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Verification failed');
            }

            this.triggerEvent('verified', data);

            return data;
        } catch (error) {
            console.error('Verification failed:', error);
            this.triggerEvent('verificationFailed', error);
            throw error;
        }
    }

    // Disable 2FA
    async disable(password) {
        try {
            const response = await fetch(this.options.apiUrl + this.options.disableEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    password: password,
                    timestamp: Date.now()
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to disable 2FA');
            }

            this.triggerEvent('disabled', data);

            return data;
        } catch (error) {
            console.error('Disable failed:', error);
            this.triggerEvent('disableFailed', error);
            throw error;
        }
    }

    // Generate new backup codes
    async generateBackupCodes() {
        try {
            const response = await fetch(this.options.apiUrl + this.options.backupCodesEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to generate backup codes');
            }

            this.backupCodes = data.codes;
            this.triggerEvent('backupCodesGenerated', data.codes);

            return data.codes;
        } catch (error) {
            console.error('Backup code generation failed:', error);
            throw error;
        }
    }

    // Render setup container
    renderSetupContainer(container) {
        container.innerHTML = `
            <div class="two-factor-setup">
                <div class="setup-header mb-4">
                    <h4>Set Up Two-Factor Authentication</h4>
                    <p class="text-muted">
                        Scan the QR code with your authenticator app (Google Authenticator, Authy, etc.)
                    </p>
                </div>

                <div class="row">
                    <div class="col-md-6 text-center mb-4">
                        <div class="qr-code-container p-3 border rounded">
                            ${this.qrCode ? 
                                `<img src="${this.qrCode}" alt="2FA QR Code" class="img-fluid">` :
                                `<div class="spinner-border text-primary"></div>`
                            }
                        </div>
                        <div class="manual-code mt-3">
                            <small class="text-muted">Can't scan? Use this code:</small>
                            <div class="input-group mt-2">
                                <input type="text" class="form-control form-control-sm" 
                                       value="${this.secret || ''}" readonly id="manual-secret">
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="copyToClipboard('${this.secret || ''}')">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="verification-section">
                            <h5>Verify Setup</h5>
                            <p class="text-muted small">
                                Enter the 6-digit code from your authenticator app
                            </p>

                            <div class="totp-display mb-4 text-center">
                                <div class="display-4 mb-2" id="totp-code">------</div>
                                <div class="progress" style="height: 5px;">
                                    <div id="totp-timer-bar" class="progress-bar" 
                                         style="width: 100%; background-color: #28a745;"></div>
                                </div>
                                <div id="totp-timer-text" class="small text-muted mt-1">30s</div>
                            </div>

                            <form id="verify-2fa-form">
                                <div class="form-group mb-3">
                                    <label for="verification-code">Verification Code</label>
                                    <input type="text" class="form-control form-control-lg text-center" 
                                           id="verification-code" maxlength="6" pattern="[0-9]{6}"
                                           placeholder="000000" autocomplete="off">
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary" id="verify-btn">
                                        Verify and Enable
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" 
                                            id="copy-code-btn">
                                        <i class="fas fa-copy"></i> Copy Current Code
                                    </button>
                                </div>
                            </form>

                            <div id="verification-message" class="mt-3"></div>
                        </div>
                    </div>
                </div>

                ${this.backupCodes.length > 0 ? this.renderBackupCodes() : ''}
            </div>
        `;

        // Add form submit handler
        const form = document.getElementById('verify-2fa-form');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleVerification();
            });
        }

        // Add copy code button
        const copyBtn = document.getElementById('copy-code-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => {
                if (this.currentCode) {
                    this.copyToClipboard(this.currentCode);
                    this.showMessage('Code copied to clipboard!', 'success');
                }
            });
        }

        // Auto-focus code input
        const codeInput = document.getElementById('verification-code');
        if (codeInput) {
            codeInput.focus();
            
            // Auto-submit when 6 digits entered
            codeInput.addEventListener('input', (e) => {
                if (e.target.value.length === 6) {
                    document.getElementById('verify-btn').click();
                }
            });
        }
    }

    // Render backup codes
    renderBackupCodes() {
        return `
            <div class="backup-codes-section mt-4 p-3 border rounded bg-light">
                <h5 class="mb-3">
                    <i class="fas fa-key"></i> Backup Codes
                </h5>
                <p class="small text-muted mb-3">
                    Save these backup codes in a secure place. You can use them to log in 
                    if you lose access to your authenticator app. Each code can only be used once.
                </p>
                
                <div class="row g-2 mb-3">
                    ${this.backupCodes.map(code => `
                        <div class="col-md-4">
                            <div class="backup-code-item p-2 border rounded text-center bg-white">
                                <code>${code}</code>
                            </div>
                        </div>
                    `).join('')}
                </div>

                <div class="alert alert-warning small mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    These codes will not be shown again. Download or print them now.
                </div>

                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-secondary" onclick="downloadBackupCodes()">
                        <i class="fas fa-download"></i> Download
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="printBackupCodes()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        `;
    }

    // Handle verification form submission
    async handleVerification() {
        const codeInput = document.getElementById('verification-code');
        const verifyBtn = document.getElementById('verify-btn');
        const messageEl = document.getElementById('verification-message');

        if (!codeInput.value || codeInput.value.length !== 6) {
            this.showMessage('Please enter a valid 6-digit code', 'danger');
            return;
        }

        try {
            verifyBtn.disabled = true;
            verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verifying...';

            const result = await this.verifyCode(codeInput.value);

            this.showMessage('2FA enabled successfully!', 'success');
            
            setTimeout(() => {
                window.location.href = result.redirect || '/profile';
            }, 2000);

        } catch (error) {
            this.showMessage(error.message, 'danger');
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'Verify and Enable';
            codeInput.value = '';
            codeInput.focus();
        }
    }

    // Show message in verification area
    showMessage(text, type = 'info') {
        const messageEl = document.getElementById('verification-message');
        if (messageEl) {
            messageEl.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show">
                    ${text}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
    }

    // Copy to clipboard
    copyToClipboard(text) {
        navigator.clipboard.writeText(text).catch(err => {
            console.error('Failed to copy:', err);
            // Fallback
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        });
    }

    // Event handling
    on(event, callback) {
        if (!this.eventListeners.has(event)) {
            this.eventListeners.set(event, []);
        }
        this.eventListeners.get(event).push(callback);
    }

    off(event, callback) {
        if (this.eventListeners.has(event)) {
            const listeners = this.eventListeners.get(event);
            const index = listeners.indexOf(callback);
            if (index !== -1) {
                listeners.splice(index, 1);
            }
        }
    }

    triggerEvent(event, data) {
        if (this.eventListeners.has(event)) {
            this.eventListeners.get(event).forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error(`Event handler error for ${event}:`, error);
                }
            });
        }
    }

    // Clean up resources
    destroy() {
        this.stopTOTPTimer();
        this.eventListeners.clear();
    }
}

// Login verification manager
class TwoFactorVerifyManager {
    constructor(options = {}) {
        this.options = {
            apiUrl: '/api/2fa',
            verifyEndpoint: '/2fa/verify-login',
            backupEndpoint: '/2fa/verify-backup',
            ...options
        };

        this.method = 'totp'; // totp or backup
        this.attempts = 0;
        this.maxAttempts = 3;
    }

    // Initialize verification page
    initialize(containerId, userId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        this.userId = userId;
        this.renderContainer(container);
        this.attachEventListeners();
    }

    renderContainer(container) {
        container.innerHTML = `
            <div class="two-factor-verify">
                <div class="text-center mb-4">
                    <i class="fas fa-shield-alt fa-3x text-primary mb-3"></i>
                    <h4>Two-Factor Authentication</h4>
                    <p class="text-muted">
                        Enter the verification code from your authenticator app
                    </p>
                </div>

                <div class="method-tabs mb-4">
                    <ul class="nav nav-pills justify-content-center">
                        <li class="nav-item">
                            <button class="nav-link active" data-method="totp">
                                <i class="fas fa-mobile-alt"></i> Authenticator
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-method="backup">
                                <i class="fas fa-key"></i> Backup Code
                            </button>
                        </li>
                    </ul>
                </div>

                <form id="verify-2fa-login-form">
                    <div class="form-group mb-3" id="code-input-group">
                        <label for="verification-code">Verification Code</label>
                        <input type="text" class="form-control form-control-lg text-center" 
                               id="verification-code" maxlength="6" pattern="[0-9]{6}"
                               placeholder="000000" autocomplete="off" autofocus>
                    </div>

                    <div class="form-group mb-3" id="backup-code-group" style="display: none;">
                        <label for="backup-code">Backup Code</label>
                        <input type="text" class="form-control form-control-lg" 
                               id="backup-code" placeholder="XXXXX-XXXXX" autocomplete="off">
                        <small class="text-muted">
                            Enter one of your backup codes (format: XXXXX-XXXXX)
                        </small>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg" id="verify-btn">
                            Verify
                        </button>
                    </div>
                </form>

                <div id="verify-message" class="mt-3"></div>

                <div class="text-center mt-4">
                    <a href="#" id="recovery-link" class="small">
                        <i class="fas fa-life-ring"></i> Lost access to your authenticator?
                    </a>
                </div>
            </div>
        `;
    }

    attachEventListeners() {
        // Method tabs
        document.querySelectorAll('[data-method]').forEach(tab => {
            tab.addEventListener('click', (e) => {
                this.switchMethod(e.target.dataset.method);
            });
        });

        // Form submit
        const form = document.getElementById('verify-2fa-login-form');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleVerification();
            });
        }

        // Recovery link
        const recoveryLink = document.getElementById('recovery-link');
        if (recoveryLink) {
            recoveryLink.addEventListener('click', (e) => {
                e.preventDefault();
                window.location.href = '/recovery';
            });
        }

        // Auto-submit for TOTP
        const codeInput = document.getElementById('verification-code');
        if (codeInput) {
            codeInput.addEventListener('input', (e) => {
                if (e.target.value.length === 6) {
                    document.getElementById('verify-btn').click();
                }
            });
        }
    }

    switchMethod(method) {
        this.method = method;
        
        // Update tabs
        document.querySelectorAll('[data-method]').forEach(tab => {
            if (tab.dataset.method === method) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });

        // Show/hide inputs
        const codeGroup = document.getElementById('code-input-group');
        const backupGroup = document.getElementById('backup-code-group');
        
        if (method === 'totp') {
            codeGroup.style.display = 'block';
            backupGroup.style.display = 'none';
            document.getElementById('verification-code').focus();
        } else {
            codeGroup.style.display = 'none';
            backupGroup.style.display = 'block';
            document.getElementById('backup-code').focus();
        }
    }

    async handleVerification() {
        const verifyBtn = document.getElementById('verify-btn');
        const messageEl = document.getElementById('verify-message');

        let code;
        if (this.method === 'totp') {
            code = document.getElementById('verification-code').value;
            if (!code || code.length !== 6) {
                this.showMessage('Please enter a valid 6-digit code', 'danger');
                return;
            }
        } else {
            code = document.getElementById('backup-code').value;
            if (!code || !code.match(/^[A-Z0-9]{5}-[A-Z0-9]{5}$/)) {
                this.showMessage('Please enter a valid backup code (XXXXX-XXXXX)', 'danger');
                return;
            }
        }

        try {
            verifyBtn.disabled = true;
            verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verifying...';

            const endpoint = this.method === 'totp' ? 
                this.options.verifyEndpoint : this.options.backupEndpoint;

            const response = await fetch(this.options.apiUrl + endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: this.userId,
                    code: code,
                    method: this.method
                })
            });

            const data = await response.json();

            if (!response.ok) {
                this.attempts++;
                
                if (this.attempts >= this.maxAttempts) {
                    this.showMessage('Too many failed attempts. Redirecting to login...', 'danger');
                    setTimeout(() => {
                        window.location.href = '/login';
                    }, 2000);
                    return;
                }

                throw new Error(data.message || 'Invalid code');
            }

            this.showMessage('Verification successful! Redirecting...', 'success');
            
            setTimeout(() => {
                window.location.href = data.redirect || '/dashboard';
            }, 1000);

        } catch (error) {
            this.showMessage(error.message, 'danger');
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'Verify';
            
            // Clear input
            if (this.method === 'totp') {
                document.getElementById('verification-code').value = '';
                document.getElementById('verification-code').focus();
            } else {
                document.getElementById('backup-code').value = '';
                document.getElementById('backup-code').focus();
            }
        }
    }

    showMessage(text, type = 'info') {
        const messageEl = document.getElementById('verify-message');
        if (messageEl) {
            messageEl.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show">
                    ${text}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
    }
}

// Utility functions
window.copyToClipboard = function(text) {
    navigator.clipboard.writeText(text).catch(err => {
        console.error('Failed to copy:', err);
    });
};

window.downloadBackupCodes = function() {
    const codes = document.querySelectorAll('.backup-code-item code');
    const text = Array.from(codes).map(code => code.textContent).join('\n');
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'backup-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
};

window.printBackupCodes = function() {
    const codes = document.querySelectorAll('.backup-code-item code');
    const text = Array.from(codes).map(code => code.textContent).join('\n');
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Backup Codes</title>
                <style>
                    body { font-family: monospace; padding: 20px; }
                    .code { margin: 10px 0; }
                </style>
            </head>
            <body>
                <h3>Backup Codes</h3>
                <p>Save these codes securely. Each code can only be used once.</p>
                <div class="codes">
                    ${Array.from(codes).map(code => `<div class="code">${code.textContent}</div>`).join('')}
                </div>
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
};

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Setup page
    const setupContainer = document.getElementById('two-factor-setup-container');
    if (setupContainer) {
        window.twoFactorManager = new TwoFactorManager();
        window.twoFactorManager.initializeSetup('two-factor-setup-container').catch(error => {
            console.error('Failed to initialize 2FA setup:', error);
            setupContainer.innerHTML = `
                <div class="alert alert-danger">
                    Failed to initialize 2FA setup: ${error.message}
                </div>
            `;
        });
    }

    // Verify page
    const verifyContainer = document.getElementById('two-factor-verify-container');
    if (verifyContainer) {
        const userId = verifyContainer.dataset.userId;
        window.twoFactorVerify = new TwoFactorVerifyManager();
        window.twoFactorVerify.initialize('two-factor-verify-container', userId);
    }
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        TwoFactorManager,
        TwoFactorVerifyManager
    };
}