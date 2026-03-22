const CACHE_NAME = 'pcims-v1.0.0';
const RUNTIME_CACHE = 'pcims-runtime-v1.0.0';

// Static assets to cache on install
const STATIC_CACHE_URLS = [
  '/pcims/',
  '/pcims/login.php',
  '/pcims/dashboard.php',
  '/pcims/assets/css/bootstrap.min.css',
  '/pcims/assets/css/style.css',
  '/pcims/assets/js/bootstrap.bundle.min.js',
  '/pcims/assets/js/jquery.min.js',
  '/pcims/assets/js/chart.js',
  '/pcims/images/pc-logo-2.png',
  '/pcims/manifest.json',
];

// API endpoints that should be cached with network-first strategy
const API_CACHE_URLS = [
  '/pcims/api/products',
  '/pcims/api/inventory',
  '/pcims/api/dashboard',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  console.log('Service Worker: Installing...');
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => {
        console.log('Service Worker: Caching static assets');
        return cache.addAll(STATIC_CACHE_URLS);
      })
      .then(() => self.skipWaiting()),
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  console.log('Service Worker: Activating...');
  event.waitUntil(
    caches
      .keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE) {
              console.log('Service Worker: Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            }
          }),
        );
      })
      .then(() => self.clients.claim()),
  );
});

// Fetch event - implement caching strategies
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Handle different types of requests
  if (isAPIRequest(url)) {
    // Network-first strategy for API requests
    event.respondWith(networkFirst(request));
  } else if (isStaticAsset(url)) {
    // Cache-first strategy for static assets
    event.respondWith(cacheFirst(request));
  } else {
    // Network-first strategy for HTML pages
    event.respondWith(networkFirst(request));
  }
});

// Check if request is for API
function isAPIRequest(url) {
  return url.pathname.startsWith('/pcims/api/');
}

// Check if request is for static asset
function isStaticAsset(url) {
  return /\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/.test(
    url.pathname,
  );
}

// Cache-first strategy
async function cacheFirst(request) {
  const cachedResponse = await caches.match(request);
  if (cachedResponse) {
    return cachedResponse;
  }

  try {
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(RUNTIME_CACHE);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    console.log('Network request failed, returning offline page');
    return getOfflineResponse(request);
  }
}

// Network-first strategy
async function networkFirst(request) {
  try {
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(RUNTIME_CACHE);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    console.log('Network request failed, trying cache');
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    return getOfflineResponse(request);
  }
}

// Get offline response
async function getOfflineResponse(request) {
  const url = new URL(request.url);

  // Return offline page for HTML requests
  if (request.headers.get('accept')?.includes('text/html')) {
    const offlineResponse = await caches.match('/pcims/offline.html');
    if (offlineResponse) {
      return offlineResponse;
    }
  }

  // Return basic offline response
  return new Response(
    JSON.stringify({
      error: 'Offline',
      message: 'No internet connection available',
    }),
    {
      status: 503,
      statusText: 'Service Unavailable',
      headers: {
        'Content-Type': 'application/json',
      },
    },
  );
}

// Background sync for offline actions
self.addEventListener('sync', (event) => {
  if (event.tag === 'background-sync') {
    event.waitUntil(syncOfflineData());
  }
});

// Sync offline data when connection is restored
async function syncOfflineData() {
  try {
    // Get all offline data from IndexedDB
    const offlineData = await getOfflineData();

    // Sync each item
    for (const item of offlineData) {
      try {
        await fetch(item.url, {
          method: item.method,
          headers: item.headers,
          body: item.body,
        });

        // Remove synced item from IndexedDB
        await removeOfflineData(item.id);
      } catch (error) {
        console.error('Failed to sync item:', item, error);
      }
    }
  } catch (error) {
    console.error('Background sync failed:', error);
  }
}

// Push notification handler
self.addEventListener('push', (event) => {
  if (event.data) {
    const data = event.data.json();
    const options = {
      body: data.body,
      icon: '/images/icons/icon-192x192.png',
      badge: '/images/icons/badge-72x72.png',
      vibrate: [100, 50, 100],
      data: {
        dateOfArrival: Date.now(),
        primaryKey: data.primaryKey,
      },
      actions: [
        {
          action: 'explore',
          title: 'Explore',
          icon: '/images/icons/checkmark.png',
        },
        {
          action: 'close',
          title: 'Close',
          icon: '/images/icons/xmark.png',
        },
      ],
    };

    event.waitUntil(self.registration.showNotification(data.title, options));
  }
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'explore') {
    event.waitUntil(clients.openWindow('/dashboard.php'));
  }
});

// IndexedDB helpers for offline storage
async function getOfflineData() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('pcims-offline', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction(['offline-queue'], 'readonly');
      const store = transaction.objectStore('offline-queue');
      const getAllRequest = store.getAll();

      getAllRequest.onsuccess = () => resolve(getAllRequest.result);
      getAllRequest.onerror = () => reject(getAllRequest.error);
    };

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('offline-queue')) {
        db.createObjectStore('offline-queue', { keyPath: 'id' });
      }
    };
  });
}

async function removeOfflineData(id) {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('pcims-offline', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction(['offline-queue'], 'readwrite');
      const store = transaction.objectStore('offline-queue');
      const deleteRequest = store.delete(id);

      deleteRequest.onsuccess = () => resolve();
      deleteRequest.onerror = () => reject(deleteRequest.error);
    };
  });
}
