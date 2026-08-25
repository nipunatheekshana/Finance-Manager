import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/services/api'
import type { AffordabilityResult, Dashboard, FinancialAlert, FinancialHealth } from '@/types'

export const useDashboardStore = defineStore('dashboard', () => {
  const data = ref<Dashboard | null>(null)
  const health = ref<FinancialHealth | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const checking = ref(false)

  const alerts = computed<FinancialAlert[]>(() => data.value?.alerts ?? [])
  const hasPlan = computed(() => data.value?.has_plan ?? false)
  const needsPlanning = computed(() => data.value?.salary.needs_planning ?? false)
  const isSalaryDay = computed(() => data.value?.salary.is_salary_day ?? false)

  async function fetch(silent = false): Promise<void> {
    if (!silent) loading.value = true

    try {
      const response = await api.get<{ data: Dashboard }>('/dashboard')
      data.value = response.data
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  /** Pull fresh figures without flashing the skeletons. */
  async function refresh(): Promise<void> {
    await fetch(true)
  }

  async function fetchHealth(): Promise<void> {
    const response = await api.get<{ data: FinancialHealth }>('/financial-health')
    health.value = response.data
  }

  async function checkAffordability(amount: string): Promise<AffordabilityResult> {
    checking.value = true
    try {
      const response = await api.post<{ data: AffordabilityResult }>('/affordability-check', { amount })
      return response.data
    } finally {
      checking.value = false
    }
  }

  async function dismissAlert(id: number): Promise<void> {
    await api.delete(`/alerts/${id}`)
    if (data.value) {
      data.value = {
        ...data.value,
        alerts: data.value.alerts.filter((alert) => alert.id !== id),
      }
    }
  }

  async function markAlertRead(id: number): Promise<void> {
    await api.post(`/alerts/${id}/read`)
    if (data.value) {
      data.value = {
        ...data.value,
        alerts: data.value.alerts.map((alert) =>
          alert.id === id ? { ...alert, is_read: true } : alert,
        ),
      }
    }
  }

  return {
    data,
    health,
    loading,
    loaded,
    checking,
    alerts,
    hasPlan,
    needsPlanning,
    isSalaryDay,
    fetch,
    refresh,
    fetchHealth,
    checkAffordability,
    dismissAlert,
    markAlertRead,
  }
})
