/* 計測端末の Service Worker: 画面一式をキャッシュし、オフラインでも再読み込みできるようにする。
 * API (../api/) はキャッシュしない（記録データは localStorage で保持）。
 * ※ Service Worker は HTTPS（または localhost）でのみ動作します。 */
const CACHE = 'ltt-m-v1';
const SHELL = ['./', './index.html', './app.js', './app.css', './manifest.webmanifest', '../assets/icon.svg'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// ネットワーク優先 → 失敗したらキャッシュ（更新が即反映され、圏外でも起動できる）
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin || url.pathname.includes('/api/')) return;
  e.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req, { ignoreSearch: true }).then((r) => r || caches.match('./index.html')))
  );
});
