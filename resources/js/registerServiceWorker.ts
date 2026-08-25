import type { useUiStore } from './stores/ui'

type UiStore = ReturnType<typeof useUiStore>

/**
 * Register the generated service worker.
 *
 * Updates are prompted rather than applied silently, so the app never reloads
 * out from under someone in the middle of entering an expense.
 */
export function registerServiceWorker(ui: UiStore): void {
  if (!('serviceWorker' in navigator) || import.meta.env.DEV) {
    return
  }

  window.addEventListener('load', () => {
    void navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .then((registration) => {
        registration.addEventListener('updatefound', () => {
          const installing = registration.installing
          if (!installing) return

          installing.addEventListener('statechange', () => {
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
              ui.updateAvailable = true
            }
          })
        })
      })
      .catch(() => {
        // Offline support is a progressive enhancement; the app still works.
      })
  })
}

/** Activate a waiting worker and reload once it takes over. */
export async function applyUpdate(): Promise<void> {
  const registration = await navigator.serviceWorker.getRegistration()
  if (!registration?.waiting) {
    window.location.reload()
    return
  }

  registration.waiting.postMessage({ type: 'SKIP_WAITING' })
  navigator.serviceWorker.addEventListener('controllerchange', () => window.location.reload(), {
    once: true,
  })
}
