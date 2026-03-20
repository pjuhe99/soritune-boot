/* ══════════════════════════════════════════════════════════════
   Service Worker — 소리튠 부트캠프 PWA
   전략: 정적 에셋은 Cache First, API는 Network Only
   ══════════════════════════════════════════════════════════════ */
const CACHE_NAME = 'boot-v20260320';

const STATIC_ASSETS = [
    '/',
    '/css/common.css',
    '/css/calendar.css',
    '/css/study.css',
    '/css/lecture.css',
    '/css/member.css',
    '/css/member-tabs.css',
    '/css/member-calendar.css',
    '/css/member-assignments.css',
    '/css/member-progress.css',
    '/css/member-bootees.css',
    '/js/toast.js',
    '/js/common.js',
    '/js/calendar.js',
    '/js/member-utils.js',
    '/js/member-tabs.js',
    '/js/member-home.js',
    '/js/member-calendar.js',
    '/js/member-calendar-detail.js',
    '/js/member-assignments.js',
    '/js/member-progress.js',
    '/js/member-bootees.js',
    '/js/member.js',
    '/manifest.json',
];

// Install — 정적 에셋 프리캐시
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate — 이전 캐시 정리
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Fetch — 정적은 Cache First, API/외부는 Network Only
self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);

    // API 요청은 항상 네트워크
    if (url.pathname.startsWith('/api/')) return;

    // 외부 리소스는 패스
    if (url.origin !== location.origin) return;

    // HTML 페이지(navigate): Network First — 항상 최신 HTML을 가져옴
    if (e.request.mode === 'navigate') {
        e.respondWith(
            fetch(e.request).then(response => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
                }
                return response;
            }).catch(() => caches.match(e.request).then(c => c || caches.match('/')))
        );
        return;
    }

    // 정적 에셋: Network First (버전 쿼리스트링으로 캐시 버스팅)
    e.respondWith(
        fetch(e.request).then(response => {
            if (response.ok && e.request.method === 'GET') {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
            }
            return response;
        }).catch(() => caches.match(e.request))
    );
});
