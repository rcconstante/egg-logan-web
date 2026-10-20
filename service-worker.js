var CACHE_VERSION = 'egglogan-v24';
var STATIC_CACHE = CACHE_VERSION + '-static';

var urlsToCache = [
    '/Egglogan/assets/css/style.css',
    '/Egglogan/assets/js/main.js',
    '/Egglogan/assets/js/scanner.js',
    '/Egglogan/assets/images/header-logo.png',
    '/Egglogan/assets/images/favicon-icon.png',
    '/Egglogan/assets/images/nodata-yet.png',
    '/Egglogan/assets/icons/hugeicons/sprite.svg'
];

var CDN_URLS = [
    'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js'
];

function isStaticAsset(url) {
    return url.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?)$/i) !== null ||
           url.pathname.indexOf('/assets/') !== -1;
}

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function(cache) {
            return Promise.all(urlsToCache.map(function(url) {
                return cache.add(url).catch(function() {});
            }));
        }).then(function() {
            var cdnCache = caches.open(CACHE_VERSION + '-cdn');
            return Promise.all(CDN_URLS.map(function(url) {
                return cdnCache.then(function(c) { return c.add(url).catch(function() {}); });
            }));
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) { return name.indexOf(CACHE_VERSION) !== 0; })
                .map(function(name) { return caches.delete(name); })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function(event) {
    var request = event.request;
    if (request.method !== 'GET') return;

    var url = new URL(request.url);

    if (url.origin === location.origin && (
        url.pathname.indexOf('/api/') !== -1 ||
        url.pathname.indexOf('/verify/') !== -1
    )) {
        return;
    }

    // Pages (PHP): network-first so login state and content are always fresh.
    if (!isStaticAsset(url)) {
        event.respondWith(
            fetch(request).then(function(response) {
                return response;
            }).catch(function() {
                return caches.match(request).then(function(cached) {
                    if (cached) return cached;
                    return new Response('You are offline. Reconnect to use EggLogan.', {
                        status: 503,
                        headers: { 'Content-Type': 'text/plain' }
                    });
                });
            })
        );
        return;
    }

    // Static assets: cache-first, but only store valid basic 200s.
    event.respondWith(
        caches.match(request).then(function(cached) {
            if (cached) return cached;
            return fetch(request).then(function(response) {
                if (response && response.status === 200 && response.type === 'basic') {
                    var copy = response.clone();
                    caches.open(STATIC_CACHE).then(function(cache) {
                        cache.put(request, copy);
                    });
                }
                return response;
            });
        })
    );
});
