<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { CalendarCheck, Info, LayoutDashboard } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import ProgressRing from '@/components/common/ProgressRing.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import SelectField from '@/components/common/SelectField.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import AllowanceList from '@/components/budgets/AllowanceList.vue'
import ProgressSection from '@/components/budgets/ProgressSection.vue'
import ProgressStatusChip from '@/components/budgets/ProgressStatusChip.vue'
import { api } from '@/services/api'
import { formatDate, formatDateRange, relativeDay } from '@/composables/useDates'
import type { CycleProgress } from '@/types'

const progress = ref<CycleProgress | null>(null)
const plans = ref<Array<{ id: number; label: string }>>([])
const selectedPlan = ref<number | null>(null)
const message = ref('')
const loading = ref(true)

async function load(): Promise<void> {
  loading.value = true
  try {
    const response = await api.get<{
      data: CycleProgress | null
      message?: string
      plans?: Array<{ id: number; label: string }>
    }>(
      '/cycle-progress',
      selectedPlan.value === null ? undefined : { params: { plan: selectedPlan.value } },
    )

    progress.value = response.data
    plans.value = response.plans ?? []
    message.value = response.message ?? ''
    selectedPlan.value = response.data?.plan.id ?? null
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Switching cycle reloads the board; the first load sets the value itself.
watch(selectedPlan, (id, previous) => {
  if (previous !== null && id !== previous) void load()
})

/** How far through the cycle we are, against how much is settled. */
const pace = computed(() => {
  if (!progress.value) return null

  const { overall, plan } = progress.value
  const difference = Math.round(overall.percentage - plan.elapsed_percentage)

  return {
    difference,
    label:
      overall.on_track
        ? difference >= 5
          ? `${difference}% ahead of the cycle`
          : 'In step with the cycle'
        : `${Math.abs(difference)}% behind the cycle`,
  }
})

const billStatusLabel: Record<string, string> = {
  paid: 'Paid',
  planned: 'Not yet',
  skipped: 'Skipped',
  postponed: 'Postponed',
}
</script>

<template>
  <div>
    <PageHeader
      title="Cycle progress"
      :subtitle="
        progress ? formatDateRange(progress.plan.cycle_start, progress.plan.cycle_end) : undefined
      "
    />

    <LoadingState v-if="loading && !progress" :rows="4" />

    <EmptyState
      v-else-if="!progress"
      :icon="LayoutDashboard"
      title="No active plan"
      :description="message || 'Finalise a monthly plan to track its progress.'"
      action-label="Go to planning"
      @action="$router.push('/plan')"
    />

    <div v-else class="space-y-4">
      <SelectField
        v-if="plans.length > 1"
        v-model="selectedPlan"
        :options="plans.map((row) => ({ value: row.id, label: row.label }))"
        label="Cycle"
      />

      <!-- The headline: committed money settled, against time elapsed. -->
      <section class="card p-4">
        <div class="flex items-center gap-4">
          <ProgressRing
            :percentage="progress.overall.percentage"
            :tone="progress.overall.on_track ? 'safe' : 'warning'"
            :size="84"
          >
            <span class="tabular text-base font-bold text-ink">
              {{ progress.overall.percentage.toFixed(0) }}%
            </span>
          </ProgressRing>

          <div class="min-w-0 flex-1">
            <p class="text-sm text-ink-muted">Commitments settled</p>
            <p class="mt-0.5">
              <MoneyText :amount="progress.overall.settled" size="xl" class="font-bold" />
              <span class="text-sm text-ink-subtle">
                of <MoneyText :amount="progress.overall.committed" size="sm" />
              </span>
            </p>
            <p
              v-if="pace"
              class="mt-1 text-xs font-medium"
              :class="progress.overall.on_track ? 'text-safe' : 'text-warn'"
            >
              {{ pace.label }}
            </p>
          </div>
        </div>

        <!-- Time is the other half of the story: 40% settled reads very
             differently on day 3 and on day 27. -->
        <div class="mt-4 border-t border-line pt-3">
          <div class="flex items-baseline justify-between text-xs">
            <span class="text-ink-muted">
              Day {{ progress.plan.days_elapsed }} of {{ progress.plan.days_total }}
            </span>
            <span class="tabular text-ink-subtle">
              {{ progress.plan.days_remaining }}
              {{ progress.plan.days_remaining === 1 ? 'day' : 'days' }} left
            </span>
          </div>
          <BudgetProgress
            class="mt-1.5"
            height="sm"
            :percentage="progress.plan.elapsed_percentage"
            status="safe"
            :label="`Cycle: ${progress.plan.elapsed_percentage.toFixed(0)}% elapsed`"
          />
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-line pt-3">
          <div>
            <dt class="text-xs text-ink-subtle">Still to settle</dt>
            <dd class="mt-0.5">
              <MoneyText :amount="progress.overall.outstanding" size="sm" class="font-semibold" />
            </dd>
          </div>
          <div>
            <dt class="text-xs text-ink-subtle">Left to spend</dt>
            <dd class="mt-0.5">
              <MoneyText :amount="progress.spending.remaining" size="sm" class="font-semibold" colored />
            </dd>
          </div>
        </dl>
      </section>

      <!-- Income -->
      <ProgressSection
        title="Income"
        :settled="progress.income.received"
        :planned="progress.income.expected"
        :percentage="progress.income.percentage"
        :status="progress.income.status"
        :chip-label="progress.income.is_recorded ? undefined : 'Not recorded'"
        :outstanding="progress.income.shortfall"
        outstanding-label="less than expected"
        :tone="progress.income.status === 'done' ? 'safe' : 'warning'"
      >
        <div
          v-if="Number.parseFloat(progress.income.extra) > 0"
          class="flex items-center justify-between py-2.5"
        >
          <span class="text-sm text-ink-muted">Extra above expected</span>
          <MoneyText :amount="progress.income.extra" size="sm" class="font-semibold" />
        </div>
        <div
          v-if="Number.parseFloat(progress.income.opening_balance) > 0"
          class="flex items-center justify-between py-2.5"
        >
          <span class="text-sm text-ink-muted">Carried in from last cycle</span>
          <MoneyText :amount="progress.income.opening_balance" size="sm" class="font-semibold" />
        </div>
      </ProgressSection>

      <!-- Bills -->
      <ProgressSection
        v-if="progress.bills.items.length"
        title="Bills"
        :subtitle="`${progress.bills.settled_count} of ${progress.bills.count} paid`"
        :settled="progress.bills.settled"
        :planned="progress.bills.planned"
        :percentage="progress.bills.percentage"
        :status="progress.bills.status"
        :outstanding="progress.bills.outstanding"
      >
        <div
          v-for="bill in progress.bills.items"
          :key="bill.id"
          class="flex items-center justify-between gap-3 py-2.5"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium" :class="bill.counts ? 'text-ink' : 'text-ink-subtle line-through'">
              {{ bill.name }}
            </p>
            <p class="text-xs text-ink-subtle">
              <template v-if="bill.paid_at">Paid {{ formatDate(bill.paid_at) }}</template>
              <template v-else-if="bill.due_date">{{ relativeDay(bill.due_date) }}</template>
              <template v-else>No due date</template>
            </p>
          </div>
          <span class="flex shrink-0 items-center gap-2">
            <MoneyText :amount="bill.amount" size="sm" class="font-semibold" />
            <ProgressStatusChip
              :status="bill.status === 'paid' ? 'done' : bill.counts ? 'pending' : 'partial'"
              :label="billStatusLabel[bill.status]"
            />
          </span>
        </div>
      </ProgressSection>

      <!-- Debt -->
      <ProgressSection
        v-if="progress.debts.items.length"
        title="Debt payments"
        :subtitle="`${progress.debts.settled_count} of ${progress.debts.count} settled`"
        :settled="progress.debts.settled"
        :planned="progress.debts.planned"
        :percentage="progress.debts.percentage"
        :status="progress.debts.status"
        :outstanding="progress.debts.outstanding"
      >
        <RouterLink
          v-for="row in progress.debts.items"
          :key="row.id"
          :to="`/debts/${row.debt_id}`"
          class="flex items-center justify-between gap-3 py-2.5 transition hover:opacity-80"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-ink">{{ row.name }}</p>
            <p class="text-xs text-ink-subtle">
              <MoneyText :amount="row.paid" size="xs" /> of
              <MoneyText :amount="row.planned" size="xs" /> paid
              <template v-if="row.due_day"> · due day {{ row.due_day }}</template>
            </p>
          </div>
          <ProgressStatusChip :status="row.status" />
        </RouterLink>
      </ProgressSection>

      <!-- Savings -->
      <ProgressSection
        v-if="progress.savings.items.length"
        title="Savings"
        :subtitle="`${progress.savings.settled_count} of ${progress.savings.count} funded`"
        :settled="progress.savings.settled"
        :planned="progress.savings.planned"
        :percentage="progress.savings.percentage"
        :status="progress.savings.status"
        :outstanding="progress.savings.outstanding"
      >
        <RouterLink
          v-for="row in progress.savings.items"
          :key="row.id"
          :to="`/savings/${row.savings_goal_id}`"
          class="flex items-center justify-between gap-3 py-2.5 transition hover:opacity-80"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-ink">{{ row.name }}</p>
            <p class="text-xs text-ink-subtle">
              <MoneyText :amount="row.saved" size="xs" /> of
              <MoneyText :amount="row.planned" size="xs" /> put aside
            </p>
          </div>
          <ProgressStatusChip :status="row.status" />
        </RouterLink>
      </ProgressSection>

      <!-- Allowances: a pot to draw down, not a task to finish, so no chip. -->
      <section v-if="progress.allowances.items.length" class="card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-ink">Allowances</h2>
            <p class="mt-0.5 text-xs text-ink-subtle">
              <template v-if="progress.allowances.over_count">
                {{ progress.allowances.over_count }} over the amount set aside
              </template>
              <template v-else-if="progress.allowances.ahead_of_pace_count">
                {{ progress.allowances.ahead_of_pace_count }} running ahead of pace
              </template>
              <template v-else>All within pace</template>
            </p>
          </div>
          <div class="shrink-0 text-right">
            <MoneyText :amount="progress.allowances.remaining" size="lg" class="font-bold" />
            <p class="text-xs text-ink-subtle">left</p>
          </div>
        </div>

        <div class="mt-4">
          <AllowanceList :allowances="progress.allowances.items" />
        </div>
      </section>

      <!-- Day-to-day spending -->
      <section class="card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-ink">Day-to-day spending</h2>
            <p class="mt-0.5 text-xs text-ink-subtle">
              {{ progress.spending.weeks_over }} of {{ progress.spending.weeks.length }} weeks over
            </p>
          </div>
          <StatusBadge :status="progress.spending.status" />
        </div>

        <div class="mt-2 flex items-baseline gap-1.5">
          <MoneyText :amount="progress.spending.spent" size="xl" class="font-bold" />
          <span class="text-sm text-ink-subtle">
            of <MoneyText :amount="progress.spending.budget" size="sm" class="font-medium" />
          </span>
        </div>

        <BudgetProgress
          class="mt-2.5"
          :percentage="progress.spending.percentage"
          :status="progress.spending.status"
          :label="`Spending: ${progress.spending.percentage.toFixed(0)}% used`"
        />

        <ul class="mt-3 space-y-2 border-t border-line pt-3">
          <li
            v-for="week in progress.spending.weeks"
            :key="week.id"
            class="flex items-center gap-3"
          >
            <span class="w-14 shrink-0 text-xs font-medium text-ink-muted">
              Week {{ week.week_number }}
            </span>
            <BudgetProgress
              class="flex-1"
              height="sm"
              :percentage="week.percentage_used"
              :status="week.status"
              :label="`Week ${week.week_number}: ${week.percentage_used.toFixed(0)}% used`"
            />
            <MoneyText :amount="week.remaining" size="xs" class="w-16 shrink-0 text-right font-semibold" colored compact />
          </li>
        </ul>
      </section>

      <!-- Buffer -->
      <section class="card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-ink">Buffer</h2>
            <p class="mt-0.5 text-xs text-ink-subtle">
              {{ progress.buffer.is_intact ? 'Untouched' : 'Partly used' }}
            </p>
          </div>
          <div class="shrink-0 text-right">
            <MoneyText :amount="progress.buffer.remaining" size="lg" class="font-bold" />
            <p class="text-xs text-ink-subtle">
              of <MoneyText :amount="progress.buffer.total" size="xs" /> left
            </p>
          </div>
        </div>

        <BudgetProgress
          v-if="Number.parseFloat(progress.buffer.total) > 0"
          class="mt-3"
          height="sm"
          :percentage="progress.buffer.percentage"
          :status="progress.buffer.percentage > 50 ? 'warning' : 'safe'"
          :label="`Buffer: ${progress.buffer.percentage.toFixed(0)}% used`"
        />
      </section>

      <p class="flex items-start gap-1.5 px-1 text-xs text-ink-subtle">
        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        Progress counts commitments only — bills, debt and savings. Day-to-day
        spending is money to use, not a task to finish, so it is tracked
        separately.
      </p>

      <p
        v-if="!progress.plan.is_current"
        class="flex items-start gap-1.5 rounded-[var(--radius-card)] bg-sunken p-3 text-xs text-ink-muted"
      >
        <CalendarCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        This cycle has ended. The figures are final.
      </p>
    </div>
  </div>
</template>
