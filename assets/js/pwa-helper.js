/**
 * PWA Helper Functions
 * Provides utilities for Progressive Web App functionality
 */

class PWAHelper {
    constructor() {
        this.isInstalled = false;
        this.deferredPrompt = null;
        this.isOnline = navigator.onLine;
        this.init();
    }

    init() {
        // Listen for installation events
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            this.onInstallPromptAvailable();
        });

        window.addEventListener('appinstalled', () => {
            this.isInstalled = true;
            this.onAppInstalled();
        });

        // Listen for online/offline events
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.onOnline();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.onOffline();
        });

        // Check if app is already installed
        this.checkInstallationStatus();
    }

    /**
     * Show install prompt to user
     */
    async showInstallPrompt() {
        if (!this.deferredPrompt) {
            console.log('Install prompt not available');
            return false;
        }

        try {
            this.deferredPrompt.prompt();
            const { outcome } = await this.deferredPrompt.userChoice;
            console.log(`User response to install prompt: ${outcome}`);
            
            this.deferredPrompt = null;
            return outcome === 'accepted';
        } catch (error) {
            console.error('Error showing install prompt:', error);
            return false;
        }
    }

    /**
     * Check if install prompt is available
     */
    isInstallPromptAvailable() {
        return this.deferredPrompt !== null;
    }

    /**
     * Check if app is installed
     */
    checkInstallationStatus() {
        // Check if running in standalone mode
        if (window.matchMedia('(display-mode: standalone)').matches) {
            this.isInstalled = true;
        }

        // Check for iOS standalone mode
        if (window.navigator.standalone === true) {
            this.isInstalled = true;
        }

        return this.isInstalled;
    }

    /**
     * Get current connection status
     */
    getConnectionStatus() {
        return this.isOnline;
    }

    /**
     * Cache data for offline use
     */
    async cacheData(key, data) {
        try {
            if ('indexedDB' in window) {
                const db = await this.openDB();
                const transaction = db.transaction(['offline-cache'], 'readwrite');
                const store = transaction.objectStore('offline-cache');
                await store.put({ id: key, data: data, timestamp: Date.now() });
                return true;
            }
        } catch (error) {
            console.error('Error caching data:', error);
        }
        return false;
    }

    /**
     * Get cached data
     */
    async getCachedData(key) {
        try {
            if ('indexedDB' in window) {
                const db = await this.openDB();
                const transaction = db.transaction(['offline-cache'], 'readonly');
                const store = transaction.objectStore('offline-cache');
                const result = await store.get(key);
                return result ? result.data : null;
            }
        } catch (error) {
            console.error('Error getting cached data:', error);
        }
        return null;
    }

    /**
     * Open IndexedDB
     */
    openDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('pcims-offline', 1);
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
            
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                
                if (!db.objectStoreNames.contains('offline-cache')) {
                    db.createObjectStore('offline-cache', { keyPath: 'id' });
                }
                
                if (!db.objectStoreNames.contains('offline-queue')) {
                    db.createObjectStore('offline-queue', { keyPath: 'id' });
                }
            };
        });
    }

    /**
     * Queue action for when back online
     */
    async queueOfflineAction(action) {
        try {
            const db = await this.openDB();
            const transaction = db.transaction(['offline-queue'], 'readwrite');
            const store = transaction.objectStore('offline-queue');
            
            const offlineAction = {
                id: Date.now().toString(),
                action: action,
                timestamp: Date.now()
            };
            
            await store.add(offlineAction);
            return true;
        } catch (error) {
            console.error('Error queuing offline action:', error);
        }
        return false;
    }

    /**
     * Process queued offline actions
     */
    async processOfflineQueue() {
        try {
            const db = await this.openDB();
            const transaction = db.transaction(['offline-queue'], 'readwrite');
            const store = transaction.objectStore('offline-queue');
            const actions = await store.getAll();
            
            for (const action of actions) {
                try {
                    await this.executeAction(action.action);
                    await store.delete(action.id);
                } catch (error) {
                    console.error('Error processing queued action:', error);
                }
            }
            
            return true;
        } catch (error) {
            console.error('Error processing offline queue:', error);
        }
        return false;
    }

    /**
     * Execute queued action
     */
    async executeAction(action) {
        const { method, url, headers, body } = action;
        
        const options = {
            method: method,
            headers: headers || {}
        };
        
        if (body) {
            options.body = typeof body === 'string' ? body : JSON.stringify(body);
        }
        
        const response = await fetch(url, options);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response.json();
    }

    /**
     * Show notification
     */
    async showNotification(title, options = {}) {
        if ('Notification' in window) {
            if (Notification.permission === 'granted') {
                return new Notification(title, {
                    icon: '/images/icons/icon-192x192.png',
                    badge: '/images/icons/badge-72x72.png',
                    ...options
                });
            } else if (Notification.permission !== 'denied') {
                const permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    return this.showNotification(title, options);
                }
            }
        }
        return null;
    }

    /**
     * Request notification permission
     */
    async requestNotificationPermission() {
        if ('Notification' in window) {
            return await Notification.requestPermission();
        }
        return 'denied';
    }

    /**
     * Get device info
     */
    getDeviceInfo() {
        return {
            userAgent: navigator.userAgent,
            platform: navigator.platform,
            language: navigator.language,
            cookieEnabled: navigator.cookieEnabled,
            onLine: navigator.onLine,
            isInstalled: this.isInstalled,
            isStandalone: window.matchMedia('(display-mode: standalone)').matches,
            isIOSStandalone: window.navigator.standalone === true
        };
    }

    /**
     * Event callbacks (can be overridden)
     */
    onInstallPromptAvailable() {
        console.log('Install prompt available');
        // Show install button or custom UI
        this.showInstallButton();
    }

    onAppInstalled() {
        console.log('App installed successfully');
        this.hideInstallButton();
        this.showNotification('PCIMS Installed', {
            body: 'PCIMS has been successfully installed on your device!'
        });
    }

    onOnline() {
        console.log('App is online');
        this.hideOfflineAlert();
        this.processOfflineQueue();
    }

    onOffline() {
        console.log('App is offline');
        this.showOfflineAlert();
    }

    /**
     * UI Helper methods
     */
    showInstallButton() {
        let installBtn = document.getElementById('pwa-install-btn');
        
        if (!installBtn) {
            installBtn = document.createElement('button');
            installBtn.id = 'pwa-install-btn';
            installBtn.className = 'btn btn-primary position-fixed';
            installBtn.style.cssText = 'bottom: 20px; right: 20px; z-index: 1000;';
            installBtn.innerHTML = '<i class="fas fa-download me-2"></i>Install App';
            installBtn.addEventListener('click', () => this.showInstallPrompt());
            document.body.appendChild(installBtn);
        }
        
        installBtn.style.display = 'block';
    }

    hideInstallButton() {
        const installBtn = document.getElementById('pwa-install-btn');
        if (installBtn) {
            installBtn.style.display = 'none';
        }
    }

    showOfflineAlert() {
        let alert = document.getElementById('pwa-offline-alert');
        
        if (!alert) {
            alert = document.createElement('div');
            alert.id = 'pwa-offline-alert';
            alert.className = 'alert alert-warning position-fixed top-0 start-50 translate-middle-x mt-3';
            alert.style.zIndex = '9999';
            alert.style.minWidth = '300px';
            alert.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-wifi-slash me-2"></i>
                    <div>
                        <strong>Offline Mode</strong>
                        <div class="small">Some features may be limited</div>
                    </div>
                    <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            document.body.appendChild(alert);
        }
        
        alert.style.display = 'block';
    }

    hideOfflineAlert() {
        const alert = document.getElementById('pwa-offline-alert');
        if (alert) {
            alert.style.display = 'none';
        }
    }
}

// Initialize PWA Helper
window.PWAHelper = new PWAHelper();

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PWAHelper;
}
