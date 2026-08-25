import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/services/api'
import type { ApiCollection, Debt, DebtPayment, DebtTotals, PayoffProjection } from '@/types'

export const useDebtStore = defineStore('debts', () => {
  const items = ref<Debt[]>([])
  const totals = ref<DebtTotals | null>(null)
  const selected = ref<Debt | null>(null)
  const selectedPayoff = ref<PayoffProjection | null>(null)

  const loading = ref(false)
  const saving = ref(false)

  const totalBalance = computed(() => totals.value?.total_balance ?? '0.00')
  const creditCards = computed(() => items.value.filter((debt) => debt.type === 'credit_card'))
  const installments = computed(() => items.value.filter((debt) => debt.type === 'installment'))
  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  async function fetch(): Promise<void> {
    loading.value = true
    try {
      const response = await api.get<ApiCollection<Debt, DebtTotals>>('/debts')
      items.value = response.data
      totals.value = response.meta ?? null
    } finally {
      loading.value = false
    }
  }

  async function load(id: number): Promise<void> {
    loading.value = true
    try {
      const response = await api.get<{ data: Debt; payoff: PayoffProjection }>(`/debts/${id}`)
      selected.value = response.data
      selectedPayoff.value = response.payoff
    } finally {
      loading.value = false
    }
  }

  async function create(payload: Record<string, unknown>): Promise<Debt> {
    saving.value = true
    try {
      const response = await api.post<{ data: Debt }>('/debts', payload)
      items.value = [...items.value, response.data]
      return response.data
    } finally {
      saving.value = false
    }
  }

  async function update(id: number, payload: Record<string, unknown>): Promise<Debt> {
    saving.value = true
    try {
      const response = await api.put<{ data: Debt; payoff: PayoffProjection }>(`/debts/${id}`, payload)
      items.value = items.value.map((debt) => (debt.id === id ? response.data : debt))
      if (selected.value?.id === id) {
        selected.value = response.data
        selectedPayoff.value = response.payoff
      }
      return response.data
    } finally {
      saving.value = false
    }
  }

  async function remove(id: number): Promise<void> {
    await api.delete(`/debts/${id}`)
    items.value = items.value.filter((debt) => debt.id !== id)
  }

  /**
   * Record a payment. The response carries the recalculated payoff estimate,
   * so the projection always reflects the balance as it now stands.
   */
  async function recordPayment(
    debtId: number,
    payload: { amount: string; payment_date?: string; notes?: string; reduce_installment?: boolean },
  ): Promise<{ payment: DebtPayment; debt: Debt; payoff: PayoffProjection }> {
    saving.value = true
    try {
      const response = await api.post<{ data: DebtPayment; debt: Debt; payoff: PayoffProjection }>(
        `/debts/${debtId}/payments`,
        payload,
      )

      items.value = items.value.map((debt) => (debt.id === debtId ? response.debt : debt))
      if (selected.value?.id === debtId) {
        selected.value = response.debt
        selectedPayoff.value = response.payoff
      }

      return { payment: response.data, debt: response.debt, payoff: response.payoff }
    } finally {
      saving.value = false
    }
  }

  async function deletePayment(paymentId: number): Promise<void> {
    const response = await api.delete<{ debt: Debt; payoff: PayoffProjection }>(
      `/debt-payments/${paymentId}`,
    )
    items.value = items.value.map((debt) => (debt.id === response.debt.id ? response.debt : debt))
    if (selected.value?.id === response.debt.id) {
      selected.value = response.debt
      selectedPayoff.value = response.payoff
    }
  }

  /** Preview the payoff at a different monthly payment. */
  async function projectPayoff(debtId: number, monthlyPayment?: string): Promise<PayoffProjection> {
    const response = await api.get<{ data: PayoffProjection }>(`/debts/${debtId}/payoff`, {
      params: monthlyPayment ? { monthly_payment: monthlyPayment } : {},
    })
    return response.data
  }

  return {
    items,
    totals,
    selected,
    selectedPayoff,
    loading,
    saving,
    totalBalance,
    creditCards,
    installments,
    isEmpty,
    fetch,
    load,
    create,
    update,
    remove,
    recordPayment,
    deletePayment,
    projectPayoff,
  }
})
