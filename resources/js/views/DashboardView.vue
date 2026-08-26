<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Calculator, PiggyBank, PlusCircle, Receipt, Wallet } from 'lucide-vue-next'
import AvailableCard from '@/components/dashboard/AvailableCard.vue'
import TodayCard from '@/components/dashboard/TodayCard.vue'
import WeekCard from '@/components/dashboard/WeekCard.vue'
import CreditCardWidget from '@/components/dashboard/CreditCardWidget.vue'
import SavingsWidget from '@/components/dashboard/SavingsWidget.vue'
import SalaryDayBanner from '@/components/dashboard/SalaryDayBanner.vue'
import CategoryBudgetList from '@/components/dashboard/CategoryBudgetList.vue'
import RecentExpenses from '@/components/dashboard/RecentExpenses.vue'
import AllowanceList from '@/components/budgets/AllowanceList.vue'
import AlertCard from '@/components/common/AlertCard.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import SectionHeader from '@/components/common/SectionHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import SurplusSheet from '@/components/budgets/SurplusSheet.vue'
import BillPaymentSheet from '@/components/budgets/BillPaymentSheet.vue'
import { useDashboardStore } from '@/stores/dashboard'
import { useBudgetStore } from '@/stores/budget'
import { useUiStore } from '@/stores/ui'
import { useAuthStore } from '@/stores/auth'
import { relativeDay } from '@/composables/useDates'
import type { UpcomingBill } from '@/types'

const dashboard = useDashboardStore()
const budget = useBudgetStore()
const ui = useUiStore()
const auth = useAuthStore()

const surplusPlanId = ref<number | null>(null)
const payingBill = ref<UpcomingBill | null>(null)

const data = computed(() => dashboard.data)

const firstName = computed(() => auth.user?.name.split(' ')[0] ?? '')

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 17) return 'Good afternoon'
  return 'Good evening'
})

/** Only categories that need attention belong on the home screen. */
const attentionCategories = computed(
  () => data.value?.categories?.filter((category) => category.status !== 'safe') ?? [],
)

async function onSurplusResolved(): Promise<void> {
  surplusPlanId.value = null
  await budget.pendingSurplus()
}

onMounted(() => {
  if (!dashboard.loaded) void dashboard.fetch()
  else void dashboard.refresh()

  // Independent of the dashboard payload, so a slow answer never delays paint.
  void budget.pendingSurplus().catch(() => {
    /* Not critical to the first render. */
  })
})
</script>

<template>
  <div>
    <header class="mb-5 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm text-ink-muted">{{ greeting }}{{ firstName ? `, ${firstName}` : '' }}</p>
        <h1 class="text-2xl font-bold tracking-tight text-ink">
          {{ data?.period_label ?? '—' }}
        </h1>
      </div>

      <button
        type="button"
        class="btn btn-secondary shrink-0 !px-3"
        @click="ui.affordabilitySheetOpen = true"
      >
        <Calculator class="h-4 w-4" aria-hidden="true" />
        <span class="hidden sm:inline">Can I afford this?</span>
        <span class="sr-only sm:hidden">Can I afford this?</span>
      </button>
    </header>

    <LoadingState v-if="dashboard.loading && !dashboard.loaded" :rows="4" />

    <div v-else-if="data" class="space-y-4">
      <!-- Alerts first: they are the things that need a decision. -->
      <div v-if="data.alerts.length" class="space-y-2">
        <AlertCard
          v-for="alert in data.alerts"
          :key="alert.id"
          :alert="alert"
          @dismiss="dashboard.dismissAlert"
        />
      </div>

      <!-- Leftover from a finished cycle is real money; prompt for it before
           the salary-day flow so it can feed into the new plan. -->
      <button
        v-if="budget.pending?.needs_decision"
        type="button"
        class="block w-full rounded-[var(--radius-card)] bg-safe-soft p-4 text-left transition hover:shadow-[var(--shadow-card)]"
        @click="surplusPlanId = budget.pending.plan_id"
      >
        <div class="flex items-start gap-3">
          <PiggyBank class="mt-0.5 h-5 w-5 shrink-0 text-safe" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-ink">
              {{ budget.pending.plan_label }} left over
              <MoneyText :amount="budget.pending.total" size="sm" class="font-bold" />
            </p>
            <p class="mt-0.5 text-sm text-ink-muted">
              You did not spend it all. Decide where it should go.
            </p>
            <span class="mt-2 inline-block text-sm font-semibold text-safe">Decide →</span>
          </div>
        </div>
      </button>

      <SalaryDayBanner v-if="data.salary.needs_planning" :salary="data.salary" />

      <template v-if="data.has_plan">
        <AvailableCard
          :period-label="data.period_label"
          :available="data.available_to_spend"
          :salary="data.salary"
          :month="data.month_budget"
        />

        <div class="grid gap-4 sm:grid-cols-2">
          <TodayCard v-if="data.today_budget" :today="data.today_budget" />
          <WeekCard v-if="data.week_budget" :week="data.week_budget" />
        </div>

        <!-- Month total, shown after today and this week because it is the
             least actionable of the three. -->
        <section v-if="data.month_budget" class="card p-4">
          <div class="flex items-baseline justify-between gap-3">
            <h2 class="eyebrow">{{ data.month_budget.label }}</h2>
            <span class="tabular text-xs font-semibold text-ink-muted">
              {{ data.month_budget.percentage_used.toFixed(0) }}% used
            </span>
          </div>

          <div class="mt-2 flex items-baseline gap-1.5">
            <MoneyText :amount="data.month_budget.spent" size="2xl" class="font-bold" />
            <span class="text-sm text-ink-subtle">
              of <MoneyText :amount="data.month_budget.budget" size="sm" class="font-medium" />
            </span>
          </div>

          <BudgetProgress
            class="mt-3"
            :percentage="data.month_budget.percentage_used"
            :status="data.month_budget.status"
            :label="`This cycle: ${data.month_budget.percentage_used.toFixed(0)}% of budget used`"
          />

          <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div>
              <dt class="text-xs text-ink-subtle">Remaining</dt>
              <dd class="mt-0.5">
                <MoneyText :amount="data.month_budget.remaining" size="sm" class="font-semibold" colored compact />
              </dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Buffer left</dt>
              <dd class="mt-0.5">
                <MoneyText :amount="data.month_budget.buffer_remaining" size="sm" class="font-semibold" compact />
              </dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Days left</dt>
              <dd class="tabular mt-0.5 text-sm font-semibold text-ink">
                {{ data.month_budget.days_remaining }}
              </dd>
            </div>
          </dl>
        </section>
      </template>

      <EmptyState
        v-else
        :icon="Wallet"
        title="No plan for this cycle yet"
        :description="data.empty_message ?? 'Create a monthly plan to see your budgets and daily limits.'"
        action-label="Create a plan"
        @action="$router.push('/plan')"
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <CreditCardWidget :debts="data.debts" />
        <SavingsWidget :savings="data.savings" />
      </div>

      <!-- Money set aside for gradual spending, and how it is holding up. -->
      <section v-if="data.allowances?.length">
        <SectionHeader
          title="Allowances"
          subtitle="Set aside for spending that adds up through the cycle"
          action-label="Adjust"
          action-to="/plan"
        />
        <div class="card p-4">
          <AllowanceList :allowances="data.allowances" />
        </div>
      </section>

      <section v-if="attentionCategories.length">
        <SectionHeader
          title="Category budgets"
          subtitle="Close to or over their limit"
          action-label="See all"
          action-to="/budget"
        />
        <div class="card p-4">
          <CategoryBudgetList :categories="attentionCategories" :limit="4" />
        </div>
      </section>

      <section v-if="data.upcoming_bills.items.length">
        <SectionHeader title="Still to pay this cycle" action-label="Cash flow" action-to="/cash-flow" />
        <ul class="card divide-y divide-line">
          <li v-for="bill in data.upcoming_bills.items.slice(0, 4)" :key="bill.id">
            <!-- Settling a bill is a live-cycle action, so it belongs here
                 rather than back in the planner. -->
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-sunken"
              @click="payingBill = bill"
            >
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-ink">{{ bill.name }}</p>
                <p class="text-xs" :class="bill.is_overdue ? 'text-over' : 'text-ink-subtle'">
                  {{ bill.is_overdue ? 'Overdue' : relativeDay(bill.date) }}
                </p>
              </div>
              <span class="flex shrink-0 items-center gap-2">
                <MoneyText :amount="bill.amount" size="sm" class="font-semibold" />
                <span class="badge bg-brand-soft text-brand">Pay</span>
              </span>
            </button>
          </li>
        </ul>
      </section>

      <section>
        <SectionHeader title="Recent expenses" action-label="See all" action-to="/expenses" />

        <div v-if="data.recent_expenses.length" class="card px-4">
          <RecentExpenses :expenses="data.recent_expenses" />
        </div>

        <EmptyState
          v-else
          :icon="Receipt"
          title="No expenses yet"
          description="Start tracking your spending to see where your money goes."
          action-label="Add expense"
          @action="ui.openExpenseSheet()"
        />
      </section>

      <!-- Desktop gets an explicit button; mobile uses the bottom-nav +. -->
      <button
        type="button"
        class="btn btn-primary hidden w-full !text-base lg:flex"
        @click="ui.openExpenseSheet()"
      >
        <PlusCircle class="h-5 w-5" aria-hidden="true" />
        Add expense
      </button>
    </div>

    <BillPaymentSheet :bill="payingBill" @close="payingBill = null" @paid="dashboard.refresh()" />

    <SurplusSheet
      :plan-id="surplusPlanId"
      @close="surplusPlanId = null"
      @resolved="onSurplusResolved"
    />
  </div>
</template>
