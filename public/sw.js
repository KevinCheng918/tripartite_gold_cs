// Service Worker - Web Push 背景通知
self.addEventListener('push', function (event) {
    var data = event.data ? event.data.json() : {};
    event.waitUntil(
        self.registration.showNotification(data.title || '新通知', {
            body: data.body || '',
            icon: '/img/pwa-icon-192.png',
            badge: '/img/pwa-icon-192.png',
            data: { url: data.url || '/' },
            requireInteraction: true,
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url));
});
