/// <reference lib="webworker" />
import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching'
import { clientsClaim } from 'workbox-core'
import { registerRoute, NavigationRoute } from 'workbox-routing'
import { NetworkFirst, StaleWhileRevalidate } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: Array<{ url: string; revision: string | null }> }

cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)
clientsClaim()

/**
 * App shell: try the network so the user gets the freshest markup, but fall
 * back to the cached shell when offline. The SPA then renders from cached
 * state and queues any new expense locally.
 */
registerRoute(
  new NavigationRoute(
    new NetworkFirst({
      cacheName: 'fm-shell',
      networkTimeoutSeconds: 4,
      plugins: [new ExpirationPlugin({ maxEntries: 8, maxAgeSeconds: 60 * 60 * 24 * 7 })],
    }),
    // Never intercept the API or Sanctum endpoints.
    { denylist: [/^\/api\//, /^\/sanctum\//, /^\/build\//] },
  ),
)

/**
 * Read-only reference data is safe to serve from cache while revalidating, so
 * the expense form can open instantly and still works offline.
 */
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' &&
    (url.pathname === '/api/categories' || url.pathname === '/api/payment-methods'),
  new StaleWhileRevalidate({
    cacheName: 'fm-reference',
    plugins: [new ExpirationPlugin({ maxEntries: 8, maxAgeSeconds: 60 * 60 * 24 })],
  }),
)

/**
 * The dashboard is cached so opening the app offline still shows the last
 * known figures, clearly the state as of the last successful load.
 */
registerRoute(
  ({ url, request }) => request.method === 'GET' && url.pathname === '/api/dashboard',
  new NetworkFirst({
    cacheName: 'fm-dashboard',
    networkTimeoutSeconds: 4,
    plugins: [new ExpirationPlugin({ maxEntries: 2, maxAgeSeconds: 60 * 60 * 12 })],
  }),
)

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  if ((event.data as { type?: string } | undefined)?.type === 'SKIP_WAITING') {
    void self.skipWaiting()
  }
})
