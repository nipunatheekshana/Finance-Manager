import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError, ensureCsrfCookie, setUnauthenticatedHandler } from '@/services/api'
import type { FinancialProfile, OnboardingStatus, User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const initialised = ref(false)
  const loading = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const profile = computed<FinancialProfile | null>(() => user.value?.profile ?? null)
  const hasCompletedOnboarding = computed(() => profile.value?.onboarding_completed ?? false)

  /** Drop local state when the server says the session is gone. */
  setUnauthenticatedHandler(() => {
    user.value = null
  })

  /**
   * Restore the session on boot. A 401 here is the expected "not signed in"
   * answer, not an error worth surfacing.
   */
  async function restore(): Promise<void> {
    if (initialised.value) return

    try {
      const response = await api.get<{ user: User }>('/auth/user')
      user.value = response.user
    } catch (error) {
      if (!(error instanceof ApiError) || !error.isUnauthenticated) {
        // Anything else (offline, 500) still leaves the user signed out, but
        // is worth reporting for diagnosis.
        console.warn('Could not restore session', error)
      }
      user.value = null
    } finally {
      initialised.value = true
    }
  }

  async function login(email: string, password: string, remember = true): Promise<void> {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const response = await api.post<{ user: User }>('/auth/login', { email, password, remember })
      user.value = response.user
      initialised.value = true
    } finally {
      loading.value = false
    }
  }

  async function register(payload: {
    name: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const response = await api.post<{ user: User }>('/auth/register', payload)
      user.value = response.user
      initialised.value = true
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } finally {
      // Always clear locally, even if the request failed.
      user.value = null
    }
  }

  async function forgotPassword(email: string): Promise<string> {
    await ensureCsrfCookie()
    const response = await api.post<{ message: string }>('/auth/forgot-password', { email })
    return response.message
  }

  async function resetPassword(payload: {
    token: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await ensureCsrfCookie()
    await api.post('/auth/reset-password', payload)
  }

  async function refreshProfile(): Promise<void> {
    const response = await api.get<{ data: FinancialProfile }>('/profile')
    if (user.value) {
      user.value = { ...user.value, profile: response.data }
    }
  }

  async function updateProfile(payload: Partial<FinancialProfile>): Promise<FinancialProfile> {
    const response = await api.put<{ data: FinancialProfile }>('/profile', payload)
    if (user.value) {
      user.value = { ...user.value, profile: response.data }
    }
    return response.data
  }

  async function updateAccount(payload: { name?: string; email?: string }): Promise<void> {
    const response = await api.put<{ data: User }>('/profile/account', payload)
    user.value = response.data
  }

  async function updatePassword(payload: {
    current_password: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await api.put('/profile/password', payload)
  }

  async function onboardingStatus(): Promise<OnboardingStatus> {
    const response = await api.get<{ data: OnboardingStatus }>('/onboarding')
    return response.data
  }

  async function completeOnboarding(payload: Record<string, unknown>): Promise<void> {
    const response = await api.post<{ user: User }>('/onboarding', payload)
    user.value = response.user
  }

  async function skipOnboarding(): Promise<void> {
    await api.post('/onboarding/skip')
    await refreshProfile()
  }

  return {
    user,
    profile,
    initialised,
    loading,
    isAuthenticated,
    hasCompletedOnboarding,
    restore,
    login,
    register,
    logout,
    forgotPassword,
    resetPassword,
    refreshProfile,
    updateProfile,
    updateAccount,
    updatePassword,
    onboardingStatus,
    completeOnboarding,
    skipOnboarding,
  }
})
