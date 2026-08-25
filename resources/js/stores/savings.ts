import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/services/api'
import type { ApiCollection, SavingsGoal, SavingsTotals, SavingsTransaction } from '@/types'

export const useSavingsStore = defineStore('savings', () => {
  const goals = ref<SavingsGoal[]>([])
  const totals = ref<SavingsTotals | null>(null)
  const selected = ref<SavingsGoal | null>(null)

  const loading = ref(false)
  const saving = ref(false)

  const totalSaved = computed(() => totals.value?.total_saved ?? '0.00')
  const activeGoals = computed(() => goals.value.filter((goal) => goal.status !== 'archived'))
  const isEmpty = computed(() => !loading.value && activeGoals.value.length === 0)

  async function fetch(): Promise<void> {
    loading.value = true
    try {
      const response = await api.get<ApiCollection<SavingsGoal, SavingsTotals>>('/savings-goals')
      goals.value = response.data
      totals.value = response.meta ?? null
    } finally {
      loading.value = false
    }
  }

  async function load(id: number): Promise<void> {
    loading.value = true
    try {
      const response = await api.get<{ data: SavingsGoal }>(`/savings-goals/${id}`)
      selected.value = response.data
    } finally {
      loading.value = false
    }
  }

  async function create(payload: Record<string, unknown>): Promise<SavingsGoal> {
    saving.value = true
    try {
      const response = await api.post<{ data: SavingsGoal }>('/savings-goals', payload)
      goals.value = [...goals.value, response.data]
      return response.data
    } finally {
      saving.value = false
    }
  }

  async function update(id: number, payload: Record<string, unknown>): Promise<SavingsGoal> {
    saving.value = true
    try {
      const response = await api.put<{ data: SavingsGoal }>(`/savings-goals/${id}`, payload)
      goals.value = goals.value.map((goal) => (goal.id === id ? response.data : goal))
      if (selected.value?.id === id) selected.value = response.data
      return response.data
    } finally {
      saving.value = false
    }
  }

  async function remove(id: number): Promise<void> {
    await api.delete(`/savings-goals/${id}`)
    goals.value = goals.value.filter((goal) => goal.id !== id)
  }

  /** Deposit, withdraw, or transfer to another goal. */
  async function addTransaction(
    goalId: number,
    payload: {
      type: 'deposit' | 'withdrawal' | 'transfer'
      amount: string
      transaction_date?: string
      description?: string
      to_goal_id?: number
    },
  ): Promise<SavingsTransaction> {
    saving.value = true
    try {
      const response = await api.post<{ data: SavingsTransaction; goal: SavingsGoal }>(
        `/savings-goals/${goalId}/transactions`,
        payload,
      )

      goals.value = goals.value.map((goal) => (goal.id === goalId ? response.goal : goal))
      if (selected.value?.id === goalId) selected.value = response.goal

      // A transfer moves money out of one goal and into another, so the
      // destination balance is stale until we refetch.
      if (payload.type === 'transfer') await fetch()

      return response.data
    } finally {
      saving.value = false
    }
  }

  async function deleteTransaction(transactionId: number): Promise<void> {
    const response = await api.delete<{ goal: SavingsGoal }>(
      `/savings-transactions/${transactionId}`,
    )
    goals.value = goals.value.map((goal) => (goal.id === response.goal.id ? response.goal : goal))
    if (selected.value?.id === response.goal.id) {
      await load(response.goal.id)
    }
  }

  return {
    goals,
    totals,
    selected,
    loading,
    saving,
    totalSaved,
    activeGoals,
    isEmpty,
    fetch,
    load,
    create,
    update,
    remove,
    addTransaction,
    deleteTransaction,
  }
})
