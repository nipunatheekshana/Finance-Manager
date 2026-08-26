import { computed, readonly, ref } from 'vue'

/**
 * Installing the app is a browser feature, exposed three different ways.
 *
 * Chrome and Edge (Android and desktop) fire `beforeinstallprompt`, which has
 * to be captured the moment it fires — it is only offered once per page load,
 * and calling preventDefault() is what lets us show it later from our own
 * button instead of whatever the browser would have done.
 *
 * Safari on iOS fires nothing at all: Add to Home Screen is only ever a manual
 * step through the Share menu, so there the app can only explain how.
 */
interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const deferred = ref<BeforeInstallPromptEvent | null>(null)
const installed = ref(false)

/** Already running from the home screen or the Start menu. */
function detectStandalone(): boolean {
  if (typeof window === 'undefined') return false

  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    window.matchMedia('(display-mode: window-controls-overlay)').matches ||
    // Safari's own, non-standard flag.
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  )
}

const standalone = ref(detectStandalone())

/** iPhone and iPad, including iPadOS reporting itself as a Mac with touch. */
function detectIos(): boolean {
  if (typeof navigator === 'undefined') return false

  return (
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  )
}

/**
 * Called once from the entry point, before Vue mounts: the event can fire
 * before the first component exists.
 */
export function initInstallPrompt(): void {
  if (typeof window === 'undefined') return

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferred.value = event as BeforeInstallPromptEvent
  })

  window.addEventListener('appinstalled', () => {
    deferred.value = null
    installed.value = true
    standalone.value = true
  })

  window.matchMedia('(display-mode: standalone)').addEventListener('change', (event) => {
    standalone.value = event.matches || detectStandalone()
  })
}

export function useInstallPrompt() {
  const isIos = detectIos()

  /** The browser will do it for us in one tap. */
  const canPromptNatively = computed(() => deferred.value !== null)

  /** Worth offering at all: not already installed. */
  const canInstall = computed(() => !standalone.value && !installed.value)

  async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
    const event = deferred.value
    if (!event) return 'unavailable'

    await event.prompt()
    const { outcome } = await event.userChoice

    // The event is single use, whatever the answer.
    deferred.value = null

    return outcome
  }

  return {
    canInstall,
    canPromptNatively,
    isIos,
    isStandalone: readonly(standalone),
    promptInstall,
  }
}
