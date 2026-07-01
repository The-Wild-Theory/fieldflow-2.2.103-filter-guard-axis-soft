<?php
namespace RoutesPro\PWA;

if (!defined('ABSPATH')) exit;

class ServiceWorkerController {
    public static function serve(): void {
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        ?>
const FIELDFLOW_CACHE_PREFIX = 'fieldflow-pwa-';
self.addEventListener('install', event => {
  self.skipWaiting();
});
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key.indexOf(FIELDFLOW_CACHE_PREFIX) === 0).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});
self.addEventListener('fetch', event => {
  const req = event.request;
  if (!req || req.method !== 'GET') return;
  event.respondWith(fetch(req));
});
<?php
        exit;
    }
}
