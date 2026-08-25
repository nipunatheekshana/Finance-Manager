import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/services/api'
import type {
  AdjustmentOptions,
  AdjustmentType,
  AllocationSummary,
  CycleSurplus,
  CycleSurplusOptions,
  SurplusAllocation,
  SurplusResult,
  MonthlyPlan,
  SuggestedWeek,
  WeeklyBudget,
  WeeklyReview,
  WeeklySummary,
} from '@/types'

interface PlanResponse {
  data: MonthlyPlan
  summary: AllocationSummary
  suggested_weeks: SuggestedWeek[]
}

export const useBudgetStore = defineStore('budget', () => {
  const plan = ref<MonthlyPlan | null>(null)
  const summary = ref<AllocationSummary | null>(null)
  const suggestedWeeks = ref<SuggestedWeek[]>([])
  const weekSummaries = ref<WeeklySummary[]>([])
  const history = ref<MonthlyPlan[]>([])
  const pending = ref<CycleSurplus | null>(null)

  const loading = ref(false)
  const saving = ref(false)

  const isDraft = computed(() => plan.value?.status === 'draft')
  const isActive = computed(() => plan.value?.status === 'active')
  const isOverAllocated = computed(() => summary.value?.is_over_allocated ?? false)
  const canFinalize = computed(() => summary.value?.can_finalize ?? false)

  const currentWeek = computed(() => weekSummaries.value.find((week) => week.is_current) ?? null)

  const overspentWeeks = computed(() =>
    weekSummaries.value.filter((week) => week.status === 'over'),
  )

  function absorb(response: PlanResponse): void {
    plan.value = response.data
    summary.value = response.summary
    suggestedWeeks.value = response.suggested_weeks
  }

  async function loadCurrent(): Promise<void> {
    loading.value = true
    try {
      absorb(await api.get<PlanResponse>('/monthly-plans/current'))
      await loadWeeks()
    } finally {
      loading.value = false
    }
  }

  async function load(planId: number): Promise<void> {
    loading.value = true
    try {
      absorb(await api.get<PlanResponse>(`/monthly-plans/${planId}`))
      await loadWeeks()
    } finally {
      loading.value = false
    }
  }

  async function loadHistory(): Promise<void> {
    const response = await api.get<{ data: MonthlyPlan[] }>('/monthly-plans')
    history.value = response.data
  }

  async function createFor(year: number, month: number): Promise<void> {
    loading.value = true
    try {
      absorb(await api.post<PlanResponse>('/monthly-plans', { year, month }))
      await loadWeeks()
    } finally {
      loading.value = false
    }
  }

  async function loadWeeks(): Promise<void> {
    if (!plan.value) return

    const response = await api.get<{
      data: WeeklyBudget[]
      suggested: SuggestedWeek[]
      summaries: WeeklySummary[]
    }>(`/monthly-plans/${plan.value.id}/weeks`)

    weekSummaries.value = response.summaries
    suggestedWeeks.value = response.suggested
  }

  /** Step 1 — record the salary that actually arrived. */
  async function recordIncome(actualIncome: string, applySplit = true): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      absorb(
        await api.post<PlanResponse>(`/monthly-plans/${plan.value.id}/income`, {
          actual_income: actualIncome,
          apply_extra_split: applySplit,
        }),
      )
    } finally {
      saving.value = false
    }
  }

  /** Step 2 — edit, skip or postpone one bill for this month only. */
  async function updateFixedExpense(
    fixedExpenseId: number,
    payload: Record<string, unknown>,
  ): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      const response = await api.put<{ plan: MonthlyPlan; summary: AllocationSummary }>(
        `/monthly-plans/${plan.value.id}/fixed-expenses/${fixedExpenseId}`,
        payload,
      )
      plan.value = response.plan
      summary.value = response.summary
    } finally {
      saving.value = false
    }
  }

  async function addFixedExpense(payload: Record<string, unknown>): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      await api.post(`/monthly-plans/${plan.value.id}/fixed-expenses`, payload)
      await load(plan.value.id)
    } finally {
      saving.value = false
    }
  }

  /** Steps 3 and 4 — debt and savings allocations. */
  async function updateAllocations(payload: {
    debts?: Array<{ debt_id: number; planned_amount: string }>
    savings?: Array<{ savings_goal_id: number; planned_amount: string }>
  }): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      absorb(await api.put<PlanResponse>(`/monthly-plans/${plan.value.id}/allocations`, payload))
    } finally {
      saving.value = false
    }
  }

  async function updateBuffer(buffer: string): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      absorb(await api.put<PlanResponse>(`/monthly-plans/${plan.value.id}`, { buffer }))
    } finally {
      saving.value = false
    }
  }

  async function allowDeficit(allow: boolean): Promise<void> {
    if (!plan.value) return
    absorb(await api.put<PlanResponse>(`/monthly-plans/${plan.value.id}`, { allow_deficit: allow }))
  }

  /** Step 6 — the weekly split. Never forced to be equal. */
  async function saveWeeks(weeks: Array<{ week_number: number; budget_amount: string }>): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      const response = await api.put<{ data: WeeklyBudget[]; summaries: WeeklySummary[] }>(
        `/monthly-plans/${plan.value.id}/weeks`,
        { weeks },
      )
      weekSummaries.value = response.summaries
    } finally {
      saving.value = false
    }
  }

  async function finalize(): Promise<void> {
    if (!plan.value) return
    saving.value = true
    try {
      absorb(await api.post<PlanResponse>(`/monthly-plans/${plan.value.id}/finalize`))
      await loadWeeks()
    } finally {
      saving.value = false
    }
  }

  async function complete(): Promise<void> {
    if (!plan.value) return
    absorb(await api.post<PlanResponse>(`/monthly-plans/${plan.value.id}/complete`))
  }

  async function reopen(reason?: string): Promise<void> {
    if (!plan.value) return
    absorb(await api.post<PlanResponse>(`/monthly-plans/${plan.value.id}/reopen`, { reason }))
  }

  /** What a finished cycle left over, with the effect of each choice. */
  async function surplusOptions(planId: number): Promise<CycleSurplusOptions> {
    const response = await api.get<{ data: CycleSurplusOptions }>(
      `/monthly-plans/${planId}/surplus`,
    )
    return response.data
  }

  /** Apply the user's choice. An empty list means "leave it in the bank". */
  async function resolveSurplus(
    planId: number,
    allocations: SurplusAllocation[],
  ): Promise<SurplusResult> {
    saving.value = true
    try {
      return await api.post<SurplusResult>(`/monthly-plans/${planId}/surplus`, { allocations })
    } finally {
      saving.value = false
    }
  }

  /** The most recent finished cycle still waiting on a decision. */
  async function pendingSurplus(): Promise<CycleSurplus | null> {
    const response = await api.get<{ data: CycleSurplus | null }>('/cycle-surplus')
    pending.value = response.data
    return response.data
  }

  async function adjustmentOptions(weekId: number): Promise<AdjustmentOptions> {
    const response = await api.get<{ data: AdjustmentOptions }>(
      `/weekly-budgets/${weekId}/adjustment-options`,
    )
    return response.data
  }

  /** Apply the option the user explicitly chose after an overspend. */
  async function applyAdjustment(
    weekId: number,
    type: AdjustmentType,
    payload: { amount?: string; category_id?: number; reason?: string } = {},
  ): Promise<void> {
    saving.value = true
    try {
      const response = await api.post<{ weeks: WeeklySummary[] }>(
        `/weekly-budgets/${weekId}/adjustments`,
        { type, ...payload },
      )
      weekSummaries.value = response.weeks
    } finally {
      saving.value = false
    }
  }

  async function weeklyReview(weekId: number): Promise<{ review: WeeklyReview; options: AdjustmentOptions }> {
    const response = await api.get<{ data: WeeklyReview; options: AdjustmentOptions }>(
      `/weekly-budgets/${weekId}/review`,
    )
    return { review: response.data, options: response.options }
  }

  return {
    plan,
    summary,
    suggestedWeeks,
    weekSummaries,
    history,
    loading,
    saving,
    isDraft,
    isActive,
    isOverAllocated,
    canFinalize,
    currentWeek,
    overspentWeeks,
    loadCurrent,
    load,
    loadHistory,
    createFor,
    loadWeeks,
    recordIncome,
    updateFixedExpense,
    addFixedExpense,
    updateAllocations,
    updateBuffer,
    allowDeficit,
    saveWeeks,
    finalize,
    complete,
    reopen,
    pending,
    surplusOptions,
    resolveSurplus,
    pendingSurplus,
    adjustmentOptions,
    applyAdjustment,
    weeklyReview,
  }
})
