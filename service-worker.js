// Debe coincidir con APP_VERSION de js/config.js (este SW clásico no puede importarla).
// Actualizar SIEMPRE con `php tools/bump_version.php X.Y.Z`, nunca a mano por separado.
const APP_VERSION = '6.1.3';
const CACHE_PREFIX = 'triviax-';

const CACHE_NAMES = {
    static: `${CACHE_PREFIX}static-${APP_VERSION}`,
    dynamic: `${CACHE_PREFIX}dynamic-${APP_VERSION}`,
    projects: `${CACHE_PREFIX}projects-${APP_VERSION}`,
    media: `${CACHE_PREFIX}media-${APP_VERSION}`
};

// Archivos estáticos del núcleo precacheados al instalar
const PRECACHE_ASSETS = [
    './',
    './index.html',
    './manifest.json',
    './css/styles.css',
    './fonts/LuckiestGuy-Regular.ttf',
    './images/logo.svg',
    './js/config.js',
    './js/brand.js',
    './js/utils.js',
    './js/sound.js',
    './js/dice.js',
    './js/ui.js',
    './js/main.js',
    './js/services/apiClient.js',
    './js/services/storageService.js',
    './js/services/mediaManager.js',
    './js/services/diagnosticsService.js',
    './js/validators/challengeValidators.js',
    './js/engines/gameEngine.js',
    './js/engines/scoringEngine.js',
    './js/engines/challengeEngine.js',
    './js/engines/feedbackEngine.js',
    './js/engines/boardEngine.js',
    './js/activityRenderers/activityRendererRegistry.js'
];

// Evento install: precachear recursos básicos
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAMES.static)
            .then((cache) => {
                return Promise.allSettled(
                    PRECACHE_ASSETS.map((asset) => {
                        return cache.add(asset).catch(err => {
                            console.warn(`[SW] No se pudo precachear el recurso: ${asset}`, err);
                        });
                    })
                );
            })
            .then(() => {
                console.log(`[SW] Versión ${APP_VERSION} instalada correctamente.`);
                // Forzar activación sin esperar a que se cierren otras pestañas si se solicita skipWaiting
            })
    );
});

// Evento activate: limpiar cachés obsoletas y reclamar clientes
self.addEventListener('activate', (event) => {
    const activeCacheNames = Object.values(CACHE_NAMES);
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    // Si el cache pertenece a TRIVIAX pero no es parte de la versión actual, lo eliminamos
                    if (key.startsWith(CACHE_PREFIX) && !activeCacheNames.includes(key)) {
                        console.log(`[SW] Borrando caché obsoleta: ${key}`);
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => {
            console.log('[SW] Activado y reclamando clientes.');
            return self.clients.claim();
        })
    );
});

// Evento fetch: aplicar estrategias diferenciadas
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Solo interceptar peticiones GET locales
    if (request.method !== 'GET' || !url.origin.startsWith(self.location.origin)) {
        return;
    }

    // Excluir llamadas dinámicas a php (ej. api.php, admin.php, estadisticas.php)
    if (url.pathname.endsWith('.php')) {
        return;
    }

    // 1. Estrategia Network First para index.html y manifest.json
    if (url.pathname.endsWith('/') || url.pathname.endsWith('index.html') || url.pathname.endsWith('manifest.json')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.status === 200) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAMES.static).then((cache) => {
                            cache.put(request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    return caches.match(request);
                })
        );
        return;
    }

    // 2. Los datos del proyecto (proyecto.json, preguntas.txt, stats.json) NO se
    // interceptan ni se cachean: contienen las respuestas correctas y el servidor
    // los bloquea (proyectos/.htaccess). El juego los consume solo vía
    // api.php?action=get, que sanea las respuestas. Dejar que la petición pase
    // directa a la red evita servir un solucionario cacheado por versiones previas.
    if (url.pathname.includes('/proyectos/') && (url.pathname.endsWith('.txt') || url.pathname.endsWith('.json'))) {
        return;
    }

    // 3. Estrategia Stale-While-Revalidate para imágenes de fondo y assets de proyectos
    if (url.pathname.includes('/proyectos/') && (url.pathname.endsWith('.jpg') || url.pathname.endsWith('.png') || url.pathname.endsWith('.webp') || url.pathname.endsWith('.svg'))) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                const fetchPromise = fetch(request).then((networkResponse) => {
                    if (networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAMES.projects).then((cache) => {
                            cache.put(request, responseClone);
                        });
                    }
                    return networkResponse;
                }).catch(() => null);

                return cachedResponse || fetchPromise;
            })
        );
        return;
    }

    // 4. Estrategia Cache First para recursos multimedia de desafíos (audios, videos, imágenes de alta resolución)
    if (url.pathname.includes('/media/') || url.pathname.endsWith('.mp3') || url.pathname.endsWith('.mp4') || url.pathname.endsWith('.ogg') || url.pathname.endsWith('.wav')) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                return fetch(request).then((networkResponse) => {
                    if (networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAMES.media).then((cache) => {
                            cache.put(request, responseClone);
                        });
                    }
                    return networkResponse;
                });
            })
        );
        return;
    }

    // 5. Estrategia Stale-While-Revalidate para el código de la aplicación (CSS, JS) e imágenes del logo
    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            const fetchPromise = fetch(request).then((networkResponse) => {
                if (networkResponse.status === 200) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAMES.static).then((cache) => {
                        cache.put(request, responseClone);
                    });
                }
                return networkResponse;
            }).catch(() => null);

            return cachedResponse || fetchPromise;
        })
    );
});

// Escuchar mensajes SKIP_WAITING para activar el nuevo SW de inmediato
self.addEventListener('message', (event) => {
    if (event.data && event.data.action === 'SKIP_WAITING') {
        console.log('[SW] Recibido SKIP_WAITING. Saltando espera...');
        self.skipWaiting();
    }
});
