import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { ThemePreference } from '@/types'

let toastId = 0

export interface Toast {
  id: number
  kind: 'success' | 'error' | 'info' | 'warning'
  title: string
  message?: string
  timeout: number
}

const THEME_KEY = 'fm-theme'

function readStoredTheme(): ThemePreference {
  try {
    const value = localStorage.getItem(THEME_KEY)
    if (value === 'light' || value === 'dark' || value === 'system') return value
  } catch {
    /* Storage unavailable — fall through to the default. */
  }
  return 'system'
}

export const useUiStore = defineStore('ui', () => {
  const toasts = ref<Toast[]>([])
  const theme = ref<ThemePreference>(readStoredTheme())
  const isOnline = ref(navigator.onLine)
  const expenseSheetOpen = ref(false)
  const affordabilitySheetOpen = ref(false)
  /** Set when the expense sheet is opened to edit rather than create. */
  const editingExpenseId = ref<number | null>(null)
  const updateAvailable = ref(false)
  /** Week that needs an overspend decision, opened from anywhere. */
  const overspendWeekId = ref<number | null>(null)

  const prefersDark = computed(() => {
    if (theme.value === 'dark') return true
    if (theme.value === 'light') return false
    return window.matchMedia('(prefers-color-scheme: dark)').matches
  })

  function applyTheme(): void {
    document.documentElement.classList.toggle('dark', prefersDark.value)
  }

  function setTheme(next: ThemePreference): void {
    theme.value = next
    try {
      localStorage.setItem(THEME_KEY, next)
    } catch {
      /* Preference simply will not persist. */
    }
    applyTheme()
  }

  function toast(toast: Omit<Toast, 'id' | 'timeout'> & { timeout?: number }): number {
    const id = ++toastId
    toasts.value.push({ timeout: 4500, ...toast, id })
    return id
  }

  function success(title: string, message?: string): void {
    toast({ kind: 'success', title, message })
  }

  function error(title: string, message?: string): void {
    toast({ kind: 'error', title, message, timeout: 7000 })
  }

  function info(title: string, message?: string): void {
    toast({ kind: 'info', title, message })
  }

  function warning(title: string, message?: string): void {
    toast({ kind: 'warning', title, message, timeout: 6000 })
  }

  function dismissToast(id: number): void {
    toasts.value = toasts.value.filter((item) => item.id !== id)
  }

  function openExpenseSheet(expenseId: number | null = null): void {
    editingExpenseId.value = expenseId
    expenseSheetOpen.value = true
  }

  function closeExpenseSheet(): void {
    expenseSheetOpen.value = false
    editingExpenseId.value = null
  }

  function watchConnectivity(): void {
    window.addEventListener('online', () => {
      isOnline.value = true
    })
    window.addEventListener('offline', () => {
      isOnline.value = false
    })

    // Follow the OS when the user has chosen "System".
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (theme.value === 'system') applyTheme()
    })
  }

  return {
    toasts,
    theme,
    prefersDark,
    isOnline,
    expenseSheetOpen,
    affordabilitySheetOpen,
    editingExpenseId,
    updateAvailable,
    overspendWeekId,
    applyTheme,
    setTheme,
    toast,
    success,
    error,
    info,
    warning,
    dismissToast,
    openExpenseSheet,
    closeExpenseSheet,
    watchConnectivity,
  }
})
