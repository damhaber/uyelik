// assets/js/webauthn.js
// WebAuthn (Biometric) Authentication Module
// Handles registration and verification of biometric credentials

class WebAuthnManager {
    constructor(options = {}) {
        this.options = {
            registerPath: '/webauthn/register',
            verifyPath: '/webauthn/verify',
            loginPath: '/webauthn/login',
            ...options
        };
        
        this.publicKey = null;
        this.abortController = null;
    }

    // Check if WebAuthn is supported
    static isSupported() {
        return window.PublicKeyCredential !== undefined && 
               typeof window.PublicKeyCredential === 'function';
    }

    // Check if platform authenticator (biometric) is available
    static async isPlatformAuthenticatorAvailable() {
        if (!this.isSupported()) return false;
        
        try {
            const available = await PublicKeyCredential
                .isUserVerifyingPlatformAuthenticatorAvailable();
            return available;
        } catch (error) {
            console.error('Error checking platform authenticator:', error);
            return false;
        }
    }

    // Check if security key (USB/NFC) is available
    static async isSecurityKeyAvailable() {
        if (!this.isSupported()) return false;
        
        try {
            const available = await PublicKeyCredential
                .isExternalCTAP2SecurityKeySupported();
            return available;
        } catch (error) {
            console.error('Error checking security key:', error);
            return false;
        }
    }

    // Start biometric registration
    async startRegistration(userId, userName, userDisplayName) {
        if (!WebAuthnManager.isSupported()) {
            throw new Error('WebAuthn is not supported in this browser');
        }

        try {
            // Abort any existing operation
            if (this.abortController) {
                this.abortController.abort();
            }
            this.abortController = new AbortController();

            // Get registration options from server
            const response = await fetch(this.options.registerPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: userId,
                    user_name: userName,
                    user_display_name: userDisplayName
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Failed to get registration options');
            }

            const options = await response.json();
            
            // Convert base64 strings to ArrayBuffer
            this.publicKey = this._parseCreationOptions(options.publicKey);

            // Create credential
            const credential = await navigator.credentials.create({
                publicKey: this.publicKey,
                signal: this.abortController.signal
            });

            // Send credential to server
            return await this._completeRegistration(credential);

        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Registration was cancelled');
            } else if (error.name === 'NotAllowedError') {
                throw new Error('Registration was denied by user');
            } else if (error.name === 'InvalidStateError') {
                throw new Error('This device is already registered');
            } else {
                console.error('Registration error:', error);
                throw new Error('Failed to register biometric: ' + error.message);
            }
        } finally {
            this.abortController = null;
        }
    }

    // Complete registration by sending credential to server
    async _completeRegistration(credential) {
        // Convert credential to server-friendly format
        const attestationObject = this._arrayBufferToBase64(
            credential.response.attestationObject
        );
        const clientDataJSON = this._arrayBufferToBase64(
            credential.response.clientDataJSON
        );

        const response = await fetch(this.options.registerPath + '/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: credential.id,
                raw_id: this._arrayBufferToBase64(credential.rawId),
                type: credential.type,
                response: {
                    attestation_object: attestationObject,
                    client_data_json: clientDataJSON,
                    authenticator_data: credential.response.authenticatorData ? 
                        this._arrayBufferToBase64(credential.response.authenticatorData) : null,
                    public_key: credential.response.publicKey ?
                        this._arrayBufferToBase64(credential.response.publicKey) : null,
                    public_key_algorithm: credential.response.publicKeyAlgorithm
                }
            })
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to complete registration');
        }

        return await response.json();
    }

    // Start biometric authentication
    async startAuthentication(userId = null) {
        if (!WebAuthnManager.isSupported()) {
            throw new Error('WebAuthn is not supported in this browser');
        }

        try {
            // Abort any existing operation
            if (this.abortController) {
                this.abortController.abort();
            }
            this.abortController = new AbortController();

            // Get authentication options from server
            const response = await fetch(this.options.verifyPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: userId
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Failed to get authentication options');
            }

            const options = await response.json();
            
            // Convert base64 strings to ArrayBuffer
            this.publicKey = this._parseRequestOptions(options.publicKey);

            // Get credential
            const credential = await navigator.credentials.get({
                publicKey: this.publicKey,
                signal: this.abortController.signal
            });

            // Send credential to server
            return await this._completeAuthentication(credential);

        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Authentication was cancelled');
            } else if (error.name === 'NotAllowedError') {
                throw new Error('Authentication was denied by user');
            } else {
                console.error('Authentication error:', error);
                throw new Error('Failed to authenticate: ' + error.message);
            }
        } finally {
            this.abortController = null;
        }
    }

    // Complete authentication by sending credential to server
    async _completeAuthentication(credential) {
        // Convert credential to server-friendly format
        const authenticatorData = this._arrayBufferToBase64(
            credential.response.authenticatorData
        );
        const clientDataJSON = this._arrayBufferToBase64(
            credential.response.clientDataJSON
        );
        const signature = this._arrayBufferToBase64(
            credential.response.signature
        );
        const userHandle = credential.response.userHandle ?
            this._arrayBufferToBase64(credential.response.userHandle) : null;

        const response = await fetch(this.options.verifyPath + '/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: credential.id,
                raw_id: this._arrayBufferToBase64(credential.rawId),
                type: credential.type,
                response: {
                    authenticator_data: authenticatorData,
                    client_data_json: clientDataJSON,
                    signature: signature,
                    user_handle: userHandle
                }
            })
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to complete authentication');
        }

        return await response.json();
    }

    // Parse WebAuthn creation options (convert base64 to ArrayBuffer)
    _parseCreationOptions(options) {
        const parsedOptions = { ...options };
        
        // Convert user id to ArrayBuffer
        if (options.user && options.user.id) {
            parsedOptions.user.id = this._base64ToArrayBuffer(options.user.id);
        }

        // Convert challenge to ArrayBuffer
        if (options.challenge) {
            parsedOptions.challenge = this._base64ToArrayBuffer(options.challenge);
        }

        // Convert excludeCredentials ids to ArrayBuffer
        if (options.excludeCredentials && Array.isArray(options.excludeCredentials)) {
            parsedOptions.excludeCredentials = options.excludeCredentials.map(cred => ({
                ...cred,
                id: this._base64ToArrayBuffer(cred.id)
            }));
        }

        return parsedOptions;
    }

    // Parse WebAuthn request options (convert base64 to ArrayBuffer)
    _parseRequestOptions(options) {
        const parsedOptions = { ...options };
        
        // Convert challenge to ArrayBuffer
        if (options.challenge) {
            parsedOptions.challenge = this._base64ToArrayBuffer(options.challenge);
        }

        // Convert allowCredentials ids to ArrayBuffer
        if (options.allowCredentials && Array.isArray(options.allowCredentials)) {
            parsedOptions.allowCredentials = options.allowCredentials.map(cred => ({
                ...cred,
                id: this._base64ToArrayBuffer(cred.id)
            }));
        }

        return parsedOptions;
    }

    // Convert base64 string to ArrayBuffer
    _base64ToArrayBuffer(base64) {
        const binaryString = atob(base64);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

    // Convert ArrayBuffer to base64 string
    _arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    // Cancel ongoing operation
    cancel() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
    }

    // Get list of registered credentials
    async getRegisteredCredentials() {
        const response = await fetch('/webauthn/credentials');
        if (!response.ok) {
            throw new Error('Failed to get registered credentials');
        }
        return await response.json();
    }

    // Remove a registered credential
    async removeCredential(credentialId) {
        const response = await fetch('/webauthn/credentials/' + credentialId, {
            method: 'DELETE'
        });
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to remove credential');
        }
        return await response.json();
    }

    // Rename a credential
    async renameCredential(credentialId, newName) {
        const response = await fetch('/webauthn/credentials/' + credentialId, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ name: newName })
        });
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to rename credential');
        }
        return await response.json();
    }
}

// Initialize WebAuthn manager
const webAuthn = new WebAuthnManager();

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WebAuthnManager;
}

// UI Helper Functions
async function setupBiometricRegistration(userId, userName, userDisplayName) {
    const statusEl = document.getElementById('webauthn-status');
    const registerBtn = document.getElementById('register-biometric');
    const cancelBtn = document.getElementById('cancel-webauthn');

    if (!WebAuthnManager.isSupported()) {
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    WebAuthn is not supported in this browser. 
                    Please use a modern browser like Chrome, Firefox, or Edge.
                </div>
            `;
        }
        if (registerBtn) registerBtn.disabled = true;
        return;
    }

    // Check if platform authenticator is available
    const platformAvailable = await WebAuthnManager.isPlatformAuthenticatorAvailable();
    
    if (!platformAvailable) {
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No biometric authenticator found. You can use a security key instead.
                </div>
            `;
        }
    }

    if (registerBtn) {
        registerBtn.addEventListener('click', async () => {
            try {
                if (statusEl) {
                    statusEl.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-spinner fa-spin"></i>
                            Please complete the biometric verification in the browser dialog...
                        </div>
                    `;
                }
                if (cancelBtn) cancelBtn.style.display = 'inline-block';

                const result = await webAuthn.startRegistration(
                    userId, 
                    userName, 
                    userDisplayName
                );

                if (statusEl) {
                    statusEl.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            Biometric registration successful!
                        </div>
                    `;
                }

                // Refresh credentials list
                await loadRegisteredCredentials();

            } catch (error) {
                if (statusEl) {
                    statusEl.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle"></i>
                            ${error.message}
                        </div>
                    `;
                }
            } finally {
                if (cancelBtn) cancelBtn.style.display = 'none';
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            webAuthn.cancel();
            if (statusEl) {
                statusEl.innerHTML = '';
            }
            cancelBtn.style.display = 'none';
        });
    }
}

async function authenticateWithBiometric(userId = null) {
    const statusEl = document.getElementById('webauthn-auth-status');
    const authBtn = document.getElementById('authenticate-biometric');
    const cancelBtn = document.getElementById('cancel-webauthn-auth');

    if (!WebAuthnManager.isSupported()) {
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    WebAuthn is not supported in this browser.
                </div>
            `;
        }
        if (authBtn) authBtn.disabled = true;
        return;
    }

    if (authBtn) {
        authBtn.addEventListener('click', async () => {
            try {
                if (statusEl) {
                    statusEl.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-spinner fa-spin"></i>
                            Please complete biometric verification...
                        </div>
                    `;
                }
                if (cancelBtn) cancelBtn.style.display = 'inline-block';

                const result = await webAuthn.startAuthentication(userId);

                if (result.success) {
                    if (statusEl) {
                        statusEl.innerHTML = `
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                Authentication successful! Redirecting...
                            </div>
                        `;
                    }
                    
                    // Redirect to dashboard
                    setTimeout(() => {
                        window.location.href = result.redirect || '/dashboard';
                    }, 1000);
                }

            } catch (error) {
                if (statusEl) {
                    statusEl.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle"></i>
                            ${error.message}
                        </div>
                    `;
                }
            } finally {
                if (cancelBtn) cancelBtn.style.display = 'none';
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            webAuthn.cancel();
            if (statusEl) {
                statusEl.innerHTML = '';
            }
            cancelBtn.style.display = 'none';
        });
    }
}

async function loadRegisteredCredentials() {
    const container = document.getElementById('credentials-list');
    if (!container) return;

    try {
        const credentials = await webAuthn.getRegisteredCredentials();
        
        if (credentials.length === 0) {
            container.innerHTML = `
                <div class="alert alert-info">
                    No biometric credentials registered yet.
                </div>
            `;
            return;
        }

        let html = '<div class="list-group">';
        credentials.forEach(cred => {
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-fingerprint me-2"></i>
                        <strong>${cred.name || 'Unnamed Device'}</strong>
                        <br>
                        <small class="text-muted">
                            Added: ${new Date(cred.created_at).toLocaleDateString()}
                            ${cred.last_used ? ' | Last used: ' + new Date(cred.last_used).toLocaleDateString() : ''}
                        </small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary rename-credential" 
                                data-id="${cred.id}" data-name="${cred.name || ''}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger remove-credential" 
                                data-id="${cred.id}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;

        // Add event listeners for rename buttons
        container.querySelectorAll('.rename-credential').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const id = btn.dataset.id;
                const currentName = btn.dataset.name;
                const newName = prompt('Enter new name for this device:', currentName);
                
                if (newName && newName !== currentName) {
                    try {
                        await webAuthn.renameCredential(id, newName);
                        await loadRegisteredCredentials();
                    } catch (error) {
                        alert('Failed to rename: ' + error.message);
                    }
                }
            });
        });

        // Add event listeners for remove buttons
        container.querySelectorAll('.remove-credential').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                if (confirm('Are you sure you want to remove this biometric device?')) {
                    const id = btn.dataset.id;
                    try {
                        await webAuthn.removeCredential(id);
                        await loadRegisteredCredentials();
                    } catch (error) {
                        alert('Failed to remove: ' + error.message);
                    }
                }
            });
        });

    } catch (error) {
        container.innerHTML = `
            <div class="alert alert-danger">
                Failed to load credentials: ${error.message}
            </div>
        `;
    }
}

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Check if we're on registration page
    const userId = document.body.dataset.userId;
    const userName = document.body.dataset.userName;
    const userDisplayName = document.body.dataset.userDisplayName;

    if (userId && userName) {
        setupBiometricRegistration(userId, userName, userDisplayName);
    }

    // Check if we're on authentication page
    if (document.getElementById('authenticate-biometric')) {
        const userId = document.body.dataset.userId || null;
        authenticateWithBiometric(userId);
    }

    // Load credentials if on management page
    if (document.getElementById('credentials-list')) {
        loadRegisteredCredentials();
    }
});