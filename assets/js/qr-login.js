// assets/js/qr-login.js
// QR Code Login Module
// Handles QR code generation, WebSocket connection, and mobile pairing

class QRLoginManager {
    constructor(options = {}) {
        this.options = {
            wsUrl: null, // WebSocket URL will be set dynamically
            apiUrl: '/api/qr',
            qrEndpoint: '/qr/generate',
            statusEndpoint: '/qr/status',
            verifyEndpoint: '/qr/verify',
            pingInterval: 30000, // 30 seconds
            reconnectDelay: 3000, // 3 seconds
            maxReconnectAttempts: 5,
            ...options
        };

        this.ws = null;
        this.sessionId = null;
        this.qrCode = null;
        this.reconnectAttempts = 0;
        this.pingInterval = null;
        this.messageHandlers = new Map();
        this.connectionStatus = 'disconnected';
        this.eventListeners = new Map();
        
        // Bind methods
        this.handleWebSocketMessage = this.handleWebSocketMessage.bind(this);
        this.handleWebSocketOpen = this.handleWebSocketOpen.bind(this);
        this.handleWebSocketClose = this.handleWebSocketClose.bind(this);
        this.handleWebSocketError = this.handleWebSocketError.bind(this);
    }

    // Initialize QR login
    async initialize(containerId, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) {
            throw new Error(`Container element ${containerId} not found`);
        }

        // Set options
        Object.assign(this.options, options);

        // Generate new session
        await this.createSession();

        // Render QR container
        this.renderContainer(container);

        // Generate and display QR code
        await this.generateQRCode();

        // Initialize WebSocket connection
        this.connectWebSocket();

        // Start status polling as fallback
        this.startStatusPolling();

        return this;
    }

    // Create new QR session
    async createSession() {
        try {
            const response = await fetch(this.options.apiUrl + this.options.qrEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: 'login',
                    user_agent: navigator.userAgent,
                    timestamp: Date.now()
                })
            });

            if (!response.ok) {
                throw new Error('Failed to create session');
            }

            const data = await response.json();
            this.sessionId = data.session_id;
            this.options.wsUrl = data.ws_url || this.options.wsUrl;

            return data;
        } catch (error) {
            console.error('Session creation failed:', error);
            throw error;
        }
    }

    // Generate QR code
    async generateQRCode() {
        if (!this.sessionId) {
            throw new Error('No active session');
        }

        // Create QR code data URL
        const qrData = JSON.stringify({
            type: 'qr_login',
            session_id: this.sessionId,
            server: window.location.origin,
            timestamp: Date.now()
        });

        // Generate QR code using QRCode library
        if (typeof QRCode === 'undefined') {
            console.warn('QRCode library not found, using fallback');
            await this.generateFallbackQR(qrData);
            return;
        }

        // Clear previous QR code
        const qrContainer = document.getElementById('qr-code-display');
        if (qrContainer) {
            qrContainer.innerHTML = '';
            
            this.qrCode = new QRCode(qrContainer, {
                text: qrData,
                width: 256,
                height: 256,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }
    }

    // Fallback QR generation using API
    async generateFallbackQR(data) {
        try {
            const response = await fetch('/api/qr/generate-image', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ data: data })
            });

            if (!response.ok) {
                throw new Error('Failed to generate QR image');
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            
            const qrContainer = document.getElementById('qr-code-display');
            if (qrContainer) {
                qrContainer.innerHTML = `<img src="${url}" alt="QR Code" class="img-fluid">`;
            }
        } catch (error) {
            console.error('Fallback QR generation failed:', error);
            this.showError('Failed to generate QR code');
        }
    }

    // WebSocket connection management
    connectWebSocket() {
        if (!this.options.wsUrl) {
            console.warn('No WebSocket URL available, using polling only');
            return;
        }

        try {
            // Add session ID to WebSocket URL
            const wsUrl = `${this.options.wsUrl}?session=${this.sessionId}`;
            this.ws = new WebSocket(wsUrl);

            this.ws.onopen = this.handleWebSocketOpen;
            this.ws.onmessage = this.handleWebSocketMessage;
            this.ws.onclose = this.handleWebSocketClose;
            this.ws.onerror = this.handleWebSocketError;

            this.connectionStatus = 'connecting';
            this.triggerEvent('connectionChange', 'connecting');
        } catch (error) {
            console.error('WebSocket connection failed:', error);
            this.handleWebSocketError(error);
        }
    }

    handleWebSocketOpen(event) {
        console.log('WebSocket connected');
        this.connectionStatus = 'connected';
        this.reconnectAttempts = 0;
        this.triggerEvent('connectionChange', 'connected');

        // Start ping interval
        this.startPingInterval();

        // Send initial handshake
        this.sendWebSocketMessage({
            type: 'handshake',
            session_id: this.sessionId,
            client: 'web'
        });
    }

    handleWebSocketMessage(event) {
        try {
            const message = JSON.parse(event.data);
            console.log('WebSocket message received:', message.type);

            switch (message.type) {
                case 'handshake_ack':
                    this.handleHandshakeAck(message);
                    break;
                case 'ping':
                    this.handlePing(message);
                    break;
                case 'pong':
                    this.handlePong(message);
                    break;
                case 'device_connected':
                    this.handleDeviceConnected(message);
                    break;
                case 'login_approved':
                    this.handleLoginApproved(message);
                    break;
                case 'login_denied':
                    this.handleLoginDenied(message);
                    break;
                case 'login_completed':
                    this.handleLoginCompleted(message);
                    break;
                case 'error':
                    this.handleErrorMessage(message);
                    break;
                default:
                    // Custom message handlers
                    if (this.messageHandlers.has(message.type)) {
                        this.messageHandlers.get(message.type)(message);
                    }
            }

            this.triggerEvent('message', message);
        } catch (error) {
            console.error('Failed to parse WebSocket message:', error);
        }
    }

    handleWebSocketClose(event) {
        console.log('WebSocket closed:', event.code, event.reason);
        this.connectionStatus = 'disconnected';
        this.triggerEvent('connectionChange', 'disconnected');
        
        this.stopPingInterval();

        // Attempt reconnect
        if (this.reconnectAttempts < this.options.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`Reconnecting... Attempt ${this.reconnectAttempts}`);
            
            setTimeout(() => {
                this.connectWebSocket();
            }, this.options.reconnectDelay);
        } else {
            this.triggerEvent('reconnectFailed');
            this.showError('Connection lost. Please refresh the page.');
        }
    }

    handleWebSocketError(error) {
        console.error('WebSocket error:', error);
        this.triggerEvent('error', error);
    }

    // WebSocket message handlers
    handleHandshakeAck(message) {
        console.log('Handshake acknowledged');
        this.triggerEvent('handshakeComplete');
    }

    handlePing(message) {
        // Respond with pong
        this.sendWebSocketMessage({
            type: 'pong',
            timestamp: Date.now(),
            session_id: this.sessionId
        });
    }

    handlePong(message) {
        // Update last pong time
        this.lastPongTime = Date.now();
        this.triggerEvent('pong', message);
    }

    handleDeviceConnected(message) {
        console.log('Device connected:', message.device_info);
        
        // Update UI
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-mobile-alt"></i>
                    Phone connected: ${message.device_info.model || 'Unknown device'}
                    <br>
                    <small>Waiting for approval...</small>
                </div>
            `;
        }

        this.triggerEvent('deviceConnected', message.device_info);
    }

    handleLoginApproved(message) {
        console.log('Login approved');
        
        // Update UI
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Login approved! Redirecting...
                </div>
            `;
        }

        this.triggerEvent('loginApproved', message);

        // Redirect if token provided
        if (message.redirect_url) {
            setTimeout(() => {
                window.location.href = message.redirect_url;
            }, 1000);
        }
    }

    handleLoginDenied(message) {
        console.log('Login denied');
        
        // Update UI
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i>
                    Login denied on mobile device
                </div>
            `;
        }

        this.triggerEvent('loginDenied', message);

        // Generate new QR after delay
        setTimeout(() => {
            this.refreshQR();
        }, 3000);
    }

    handleLoginCompleted(message) {
        console.log('Login completed');
        
        // Update UI
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Login successful! Redirecting...
                </div>
            `;
        }

        this.triggerEvent('loginCompleted', message);

        // Redirect to dashboard
        if (message.redirect) {
            setTimeout(() => {
                window.location.href = message.redirect;
            }, 1500);
        }
    }

    handleErrorMessage(message) {
        console.error('Server error:', message.error);
        
        this.showError(message.error || 'An error occurred');
        this.triggerEvent('error', message);
    }

    // Send WebSocket message
    sendWebSocketMessage(message) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(message));
        } else {
            console.warn('WebSocket not connected, message not sent:', message);
        }
    }

    // Ping interval management
    startPingInterval() {
        this.stopPingInterval();
        this.pingInterval = setInterval(() => {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                this.sendWebSocketMessage({
                    type: 'ping',
                    timestamp: Date.now(),
                    session_id: this.sessionId
                });
            }
        }, this.options.pingInterval);
    }

    stopPingInterval() {
        if (this.pingInterval) {
            clearInterval(this.pingInterval);
            this.pingInterval = null;
        }
    }

    // Status polling (fallback for WebSocket)
    startStatusPolling() {
        // Poll every 2 seconds
        this.pollInterval = setInterval(async () => {
            if (this.connectionStatus === 'connected') {
                // Don't poll if WebSocket is active
                return;
            }

            try {
                const response = await fetch(
                    `${this.options.apiUrl}${this.options.statusEndpoint}?session=${this.sessionId}`
                );

                if (!response.ok) {
                    return;
                }

                const data = await response.json();

                switch (data.status) {
                    case 'approved':
                        this.handleLoginApproved(data);
                        break;
                    case 'denied':
                        this.handleLoginDenied(data);
                        break;
                    case 'completed':
                        this.handleLoginCompleted(data);
                        break;
                    case 'device_connected':
                        this.handleDeviceConnected(data);
                        break;
                }
            } catch (error) {
                console.error('Status polling failed:', error);
            }
        }, 2000);
    }

    stopStatusPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }

    // Refresh QR code
    async refreshQR() {
        // Clear existing
        if (this.ws) {
            this.ws.close();
        }
        this.stopPingInterval();
        this.stopStatusPolling();

        // Create new session
        await this.createSession();

        // Generate new QR
        await this.generateQRCode();

        // Reconnect WebSocket
        this.connectWebSocket();

        // Update UI
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Scan the QR code with your mobile app
                </div>
            `;
        }

        this.triggerEvent('qrRefreshed');
    }

    // Cancel login
    cancel() {
        if (this.ws) {
            this.ws.close();
        }
        this.stopPingInterval();
        this.stopStatusPolling();
        this.triggerEvent('cancelled');
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

    // Register custom message handler
    registerMessageHandler(type, handler) {
        this.messageHandlers.set(type, handler);
    }

    // UI rendering
    renderContainer(container) {
        container.innerHTML = `
            <div class="qr-login-container">
                <div class="qr-header text-center mb-4">
                    <h4>Scan with Mobile App</h4>
                    <p class="text-muted">Use your phone to scan this QR code</p>
                </div>
                
                <div id="qr-code-display" class="qr-code-display text-center mb-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                
                <div id="qr-status" class="qr-status text-center mb-3">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Initializing...
                    </div>
                </div>
                
                <div class="qr-footer text-center">
                    <button id="refresh-qr" class="btn btn-outline-primary me-2">
                        <i class="fas fa-sync-alt"></i> Refresh QR
                    </button>
                    <button id="cancel-qr" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
                
                <div class="qr-info mt-4 text-center">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt"></i>
                        Secure connection • Auto-refreshes in 5 minutes
                    </small>
                </div>
            </div>
        `;

        // Add event listeners
        const refreshBtn = document.getElementById('refresh-qr');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.refreshQR());
        }

        const cancelBtn = document.getElementById('cancel-qr');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                this.cancel();
                window.location.href = '/login';
            });
        }
    }

    showError(message) {
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    ${message}
                </div>
            `;
        }
    }

    // Clean up resources
    destroy() {
        this.cancel();
        this.eventListeners.clear();
        this.messageHandlers.clear();
    }
}

// Mobile app pairing helper
class MobilePairingManager extends QRLoginManager {
    constructor(options = {}) {
        super({
            ...options,
            qrEndpoint: '/qr/pair',
            verifyEndpoint: '/qr/pair/verify'
        });
    }

    async startPairing(containerId, deviceName) {
        await this.initialize(containerId, {
            device_name: deviceName
        });

        // Add device-specific handlers
        this.registerMessageHandler('pairing_complete', (message) => {
            this.handlePairingComplete(message);
        });
    }

    handlePairingComplete(message) {
        console.log('Device paired successfully:', message.device);
        
        const statusEl = document.getElementById('qr-status');
        if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Device paired successfully!
                </div>
            `;
        }

        this.triggerEvent('paired', message.device);
    }
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        QRLoginManager,
        MobilePairingManager
    };
}

// Auto-initialize if data attribute exists
document.addEventListener('DOMContentLoaded', () => {
    const qrContainer = document.getElementById('qr-login-container');
    if (qrContainer) {
        const manager = new QRLoginManager();
        
        // Get options from data attributes
        const options = {
            wsUrl: qrContainer.dataset.wsUrl || null,
            apiUrl: qrContainer.dataset.apiUrl || '/api/qr'
        };

        manager.initialize('qr-login-container', options).catch(error => {
            console.error('Failed to initialize QR login:', error);
            qrContainer.innerHTML = `
                <div class="alert alert-danger">
                    Failed to initialize QR login: ${error.message}
                </div>
            `;
        });

        // Store manager globally for debugging
        window.qrLoginManager = manager;
    }

    // Check for mobile pairing container
    const pairingContainer = document.getElementById('mobile-pairing-container');
    if (pairingContainer) {
        const manager = new MobilePairingManager();
        const deviceName = pairingContainer.dataset.deviceName || 'Mobile Device';
        
        manager.startPairing('mobile-pairing-container', deviceName).catch(error => {
            console.error('Failed to start pairing:', error);
        });

        window.mobilePairingManager = manager;
    }
});