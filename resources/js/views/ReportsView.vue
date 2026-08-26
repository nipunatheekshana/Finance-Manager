<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { BarChart3, CheckCircle2, TrendingDown, TriangleAlert } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import SegmentedControl from '@/components/common/SegmentedControl.vue'
import SectionHeader from '@/components/common/SectionHeader.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import ProgressRing from '@/components/common/ProgressRing.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import SelectField from '@/components/common/SelectField.vue'
import DonutChart from '@/components/charts/DonutChart.vue'
import LineChart from '@/components/charts/LineChart.vue'
import BarChart from '@/components/charts/BarChart.vue'
import { api } from '@/services/api'
import { useDashboardStore } from '@/stores/dashboard'
import { useBudgetStore } from '@/stores/budget'
import { amountToNumber } from '@/composables/useCurrency'
import { formatDateRange } from '@/composables/useDates'
import type {
  DebtTrend, IncomeVsExpensesPoint, MonthlyReview, SavingsTrend,
  SpendingByCategory, SpendingTrend,
} from '@/types'

const dashboard = useDashboardStore()
const budget = useBudgetStore()

const loading = ref(true)
const trendView = ref<'daily' | 'weekly' | 'monthly'>('daily')

const spending = ref<SpendingByCategory | null>(null)
const trend = ref<SpendingTrend | null>(null)
const debtTrend = ref<DebtTrend | null>(null)
const savingsTrend = ref<SavingsTrend | null>(null)
const incomeVsExpenses = ref<IncomeVsExpensesPoint[]>([])
const review = ref<MonthlyReview | null>(null)

const comparePlanA = ref<number | null>(null)
const comparePlanB = ref<number | null>(null)

const TREND_OPTIONS = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
]

const health = computed(() => dashboard.health)

const planOptions = computed(() =>
  budget.history.map((plan) => ({ value: plan.id, label: plan.label })),
)

const comparison = computed(() => {
  if (comparePlanA.value === null || comparePlanB.value === null) return null

  const a = budget.history.find((plan) => plan.id === comparePlanA.value)
  const b = budget.history.find((plan) => plan.id === comparePlanB.value)
  if (!a || !b) return null

  return [
    { label: 'Income', a: a.total_income, b: b.total_income },
    { label: 'Fixed expenses', a: a.fixed_expenses, b: b.fixed_expenses },
    { label: 'Debt payments', a: a.debt_payment, b: b.debt_payment },
    { label: 'Savings', a: a.savings, b: b.savings },
    { label: 'Spending budget', a: a.spending_budget, b: b.spending_budget },
  ].map((row) => ({ ...row, delta: amountToNumber(row.b) - amountToNumber(row.a) }))
})

async function loadTrend(): Promise<void> {
  const response = await api.get<{ data: SpendingTrend }>('/reports/trend', {
    params: { view: trendView.value },
  })
  trend.value = response.data
}

watch(trendView, () => {
  void loadTrend()
})

onMounted(async () => {
  loading.value = true

  try {
    const [spendingResponse, debtResponse, savingsResponse, incomeResponse, reviewResponse] =
      await Promise.all([
        api.get<{ data: SpendingByCategory; trend: SpendingTrend }>('/reports/spending'),
        api.get<{ data: DebtTrend }>('/reports/debt'),
        api.get<{ data: SavingsTrend }>('/reports/savings'),
        api.get<{ data: { points: IncomeVsExpensesPoint[] } }>('/reports/income-vs-expenses'),
        api.get<{ data: MonthlyReview | null }>('/reports/monthly'),
      ])

    spending.value = spendingResponse.data
    trend.value = spendingResponse.trend
    debtTrend.value = debtResponse.data
    savingsTrend.value = savingsResponse.data
    incomeVsExpenses.value = incomeResponse.data.points
    review.value = reviewResponse.data

    await Promise.all([dashboard.fetchHealth(), budget.loadHistory()])

    // Default the comparison to the two most recent plans.
    if (budget.history.length >= 2) {
      comparePlanB.value = budget.history[0]?.id ?? null
      comparePlanA.value = budget.history[1]?.id ?? null
    }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <PageHeader title="Reports" subtitle="How your money has actually moved." />

    <LoadingState v-if="loading" :rows="4" />

    <div v-else class="space-y-6">
      <!-- Financial health -->
      <section v-if="health?.has_data" class="card p-5">
        <div class="flex items-start gap-5">
          <ProgressRing
            :percentage="health.score ?? 0"
            :tone="(health.score ?? 0) >= 70 ? 'safe' : (health.score ?? 0) >= 45 ? 'warning' : 'over'"
            :size="96"
            :stroke="9"
          >
            <span class="tabular text-xl font-bold text-ink">{{ health.score }}</span>
            <span class="text-[0.625rem] text-ink-subtle">/ 100</span>
          </ProgressRing>

          <div class="min-w-0 flex-1">
            <h2 class="text-base font-semibold text-ink">Financial health</h2>

            <p
              v-if="health.change_from_last_month !== null"
              class="mt-0.5 text-sm font-medium"
              :class="health.change_from_last_month >= 0 ? 'text-safe' : 'text-over'"
            >
              {{ health.change_from_last_month >= 0 ? '↑' : '↓' }}
              {{ Math.abs(health.change_from_last_month) }} from last month
            </p>
            <p v-else class="mt-0.5 text-sm text-ink-muted">
              Your first tracked cycle — next month you will see the change.
            </p>

            <p class="mt-2 text-xs text-ink-subtle">{{ health.disclaimer }}</p>
          </div>
        </div>

        <div class="mt-5 space-y-3 border-t border-line pt-4">
          <div v-if="health.good.length">
            <p class="mb-1.5 text-xs font-bold uppercase tracking-wide text-safe">Going well</p>
            <ul class="space-y-1">
              <li
                v-for="item in health.good"
                :key="item"
                class="flex items-start gap-2 text-sm text-ink-muted"
              >
                <CheckCircle2 class="mt-0.5 h-3.5 w-3.5 shrink-0 text-safe" aria-hidden="true" />
                {{ item }}
              </li>
            </ul>
          </div>

          <div v-if="health.needs_attention.length">
            <p class="mb-1.5 text-xs font-bold uppercase tracking-wide text-warn">Needs attention</p>
            <ul class="space-y-1">
              <li
                v-for="item in health.needs_attention"
                :key="item"
                class="flex items-start gap-2 text-sm text-ink-muted"
              >
                <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warn" aria-hidden="true" />
                {{ item }}
              </li>
            </ul>
          </div>
        </div>

        <details class="mt-4">
          <summary class="cursor-pointer py-1 text-sm font-medium text-ink-muted">
            How this score is made up
          </summary>
          <ul class="mt-3 space-y-2">
            <li v-for="factor in health.factors" :key="factor.key" class="flex items-center gap-3">
              <span class="flex-1 text-sm text-ink-muted">{{ factor.label }}</span>
              <span class="tabular text-xs text-ink-subtle">{{ factor.weight }}% weight</span>
              <span class="tabular w-14 text-right text-sm font-semibold text-ink">
                {{ factor.points }} pts
              </span>
            </li>
          </ul>
        </details>
      </section>

      <!-- Spending by category -->
      <section>
        <SectionHeader
          title="Spending by category"
          :subtitle="spending ? formatDateRange(spending.start_date, spending.end_date) : undefined"
        />

        <div v-if="spending && spending.categories.length" class="card p-5">
          <DonutChart
            :labels="spending.categories.map((row) => row.name)"
            :values="spending.categories.map((row) => amountToNumber(row.amount))"
          />

          <p class="mt-3 text-center">
            <MoneyText :amount="spending.total" size="xl" class="font-bold" />
            <span class="block text-xs text-ink-subtle">total spent</span>
          </p>

          <ul class="mt-5 space-y-2.5 border-t border-line pt-4">
            <li v-for="row in spending.categories" :key="row.category_id" class="flex items-center gap-2.5">
              <CategoryIcon :icon="row.icon" :color="row.color" size="sm" />
              <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ row.name }}</span>
              <span class="tabular shrink-0 text-xs text-ink-subtle">{{ row.percentage.toFixed(1) }}%</span>
              <MoneyText :amount="row.amount" size="sm" class="w-24 shrink-0 text-right font-semibold" compact />
            </li>
          </ul>
        </div>

        <EmptyState
          v-else
          :icon="BarChart3"
          title="No spending to report"
          description="Log a few expenses and this will fill in."
        />
      </section>

      <!-- Spending trend -->
      <section v-if="trend">
        <SectionHeader title="Spending over time" />
        <div class="card p-5">
          <SegmentedControl
            v-model="trendView"
            :options="TREND_OPTIONS"
            aria-label="Trend granularity"
            class="mb-4"
          />

          <LineChart
            v-if="trend.points.length"
            :labels="trend.points.map((point) => point.label)"
            :values="trend.points.map((point) => amountToNumber(point.amount))"
            label="Spent"
          />
          <p v-else class="py-8 text-center text-sm text-ink-muted">
            No spending in this window yet.
          </p>
        </div>
      </section>

      <!-- Debt trend -->
      <section v-if="debtTrend">
        <SectionHeader title="Debt over time" subtitle="Total balance at the end of each month" />
        <div class="card p-5">
          <p class="mb-3 flex items-center gap-2">
            <TrendingDown class="h-4 w-4 text-safe" aria-hidden="true" />
            <MoneyText :amount="debtTrend.current_total" size="lg" class="font-bold" />
            <span class="text-sm text-ink-muted">owed today</span>
          </p>
          <LineChart
            :labels="debtTrend.points.map((point) => point.label)"
            :values="debtTrend.points.map((point) => amountToNumber(point.amount))"
            label="Total debt"
            tone="over"
          />
        </div>
      </section>

      <!-- Savings trend -->
      <section v-if="savingsTrend">
        <SectionHeader title="Savings over time" />
        <div class="card p-5">
          <p class="mb-3">
            <MoneyText :amount="savingsTrend.current_total" size="lg" class="font-bold" />
            <span class="ml-2 text-sm text-ink-muted">saved in total</span>
          </p>

          <LineChart
            :labels="savingsTrend.points.map((point) => point.label)"
            :values="savingsTrend.points.map((point) => amountToNumber(point.amount))"
            label="Total saved"
            tone="safe"
          />

          <ul v-if="savingsTrend.goals.length" class="mt-5 space-y-2 border-t border-line pt-4">
            <li v-for="goal in savingsTrend.goals" :key="goal.id" class="flex items-center gap-3">
              <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ goal.name }}</span>
              <span class="tabular shrink-0 text-xs text-ink-subtle">{{ goal.percentage.toFixed(0) }}%</span>
              <MoneyText :amount="goal.current_amount" size="sm" class="w-24 shrink-0 text-right font-semibold" compact />
            </li>
          </ul>
        </div>
      </section>

      <!-- Income vs expenses -->
      <section v-if="incomeVsExpenses.length">
        <SectionHeader title="Income vs outgoings" subtitle="Per salary cycle" />
        <div class="card p-5">
          <BarChart
            :labels="incomeVsExpenses.map((point) => point.label)"
            :datasets="[
              {
                label: 'Income',
                token: '--color-safe',
                values: incomeVsExpenses.map((point) => amountToNumber(point.income)),
              },
              {
                label: 'Expenses',
                token: '--color-over',
                values: incomeVsExpenses.map((point) => amountToNumber(point.expenses)),
              },
              {
                label: 'Debt',
                token: '--color-warn',
                values: incomeVsExpenses.map((point) => amountToNumber(point.debt_payments)),
              },
              {
                label: 'Savings',
                token: '--color-brand',
                values: incomeVsExpenses.map((point) => amountToNumber(point.savings)),
              },
            ]"
            :height="280"
          />
        </div>
      </section>

      <!-- Month comparison -->
      <section v-if="budget.history.length >= 2">
        <SectionHeader title="Compare two months" />
        <div class="card p-5">
          <div class="grid gap-3 sm:grid-cols-2">
            <SelectField v-model="comparePlanA" :options="planOptions" label="Month A" />
            <SelectField v-model="comparePlanB" :options="planOptions" label="Month B" />
          </div>

          <!-- Four columns of figures can outgrow a 320px screen; scroll the
               table rather than the page. -->
          <div v-if="comparison" class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-line text-left">
                  <th scope="col" class="py-2 font-semibold text-ink-muted"></th>
                  <th scope="col" class="py-2 text-right font-semibold text-ink-muted">A</th>
                  <th scope="col" class="py-2 text-right font-semibold text-ink-muted">B</th>
                  <th scope="col" class="py-2 text-right font-semibold text-ink-muted">Change</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="row in comparison" :key="row.label">
                  <th scope="row" class="py-2.5 text-left font-normal text-ink-muted">{{ row.label }}</th>
                  <td class="py-2.5 text-right"><MoneyText :amount="row.a" size="sm" compact /></td>
                  <td class="py-2.5 text-right"><MoneyText :amount="row.b" size="sm" compact /></td>
                  <td class="py-2.5 text-right">
                    <MoneyText :amount="row.delta.toFixed(2)" size="sm" class="font-semibold" colored signed compact />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- Month-end review -->
      <section v-if="review">
        <SectionHeader title="This cycle so far" :subtitle="review.plan.label" />
        <div class="card divide-y divide-line">
          <div class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-ink-muted">Income</span>
            <MoneyText :amount="review.plan.income" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-ink-muted">Expenses</span>
            <MoneyText :amount="review.plan.expenses" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-ink-muted">Debt payments</span>
            <MoneyText :amount="review.plan.debt_payments" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-ink-muted">Savings</span>
            <MoneyText :amount="review.plan.savings" size="sm" class="font-semibold" colored signed />
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-ink-muted">Credit-card reduction</span>
            <MoneyText :amount="review.plan.credit_card_reduction" size="sm" class="font-semibold" colored signed />
          </div>
          <div v-if="review.plan.budget_adherence !== null" class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-ink-muted">Budget adherence</span>
            <span class="tabular text-sm font-semibold text-ink">
              {{ review.plan.budget_adherence.toFixed(0) }}%
            </span>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
