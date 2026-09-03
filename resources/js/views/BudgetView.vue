<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { AlertTriangle, CalendarRange, PiggyBank, Wallet } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import SectionHeader from '@/components/common/SectionHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import CategoryBudgetList from '@/components/dashboard/CategoryBudgetList.vue'
import AllowanceList from '@/components/budgets/AllowanceList.vue'
import TopUpAllowanceSheet from '@/components/budgets/TopUpAllowanceSheet.vue'
import OverspendSheet from '@/components/budgets/OverspendSheet.vue'
import WeeklyReviewSheet from '@/components/budgets/WeeklyReviewSheet.vue'
import { useBudgetStore } from '@/stores/budget'
import { useDashboardStore } from '@/stores/dashboard'
import { formatDateRange } from '@/composables/useDates'
import type { OverspentAllowance } from '@/types'

const budget = useBudgetStore()
const dashboard = useDashboardStore()

const overspendWeekId = ref<number | null>(null)
const toppingUp = ref<OverspentAllowance | null>(null)
const reviewWeekId = ref<number | null>(null)

const plan = computed(() => budget.plan)
const weeks = computed(() => budget.weekSummaries)
const categories = computed(() => dashboard.data?.categories ?? [])
const allowances = computed(() => dashboard.data?.allowances ?? [])
const month = computed(() => dashboard.data?.month_budget ?? null)

/**
 * Allowances spent past their pot. Left alone the excess quietly eats the
 * weekly budget, so it is offered as a decision instead.
 */
const overspentAllowances = computed<OverspentAllowance[]>(() =>
  allowances.value
    .filter((row) => row.status === 'over')
    .map((row) => ({
      category_id: row.category_id,
      name: row.name,
      icon: row.icon,
      color: row.color,
      allocated: row.allocated,
      spent: row.spent,
      over_by: row.over_by,
    })),
)

/** Finished weeks that ran over and have not been resolved yet. */
const needsAttention = computed(() => weeks.value.filter((week) => week.status === 'over'))

onMounted(async () => {
  await Promise.all([
    budget.plan ? budget.loadWeeks() : budget.loadCurrent(),
    dashboard.loaded ? dashboard.refresh() : dashboard.fetch(),
  ])
})

async function afterAdjustment(): Promise<void> {
  overspendWeekId.value = null
  reviewWeekId.value = null
  await Promise.all([budget.loadWeeks(), dashboard.refresh()])
}
</script>

<template>
  <div>
    <PageHeader
      title="Budget"
      :subtitle="plan ? `${plan.label} · ${formatDateRange(plan.cycle_start_date, plan.cycle_end_date)}` : undefined"
    />

    <LoadingState v-if="budget.loading && !plan" :rows="3" />

    <EmptyState
      v-else-if="!plan || plan.status === 'draft'"
      :icon="Wallet"
      title="No active plan"
      description="Finalise a monthly plan to set your weekly budgets and daily limits."
      action-label="Go to planning"
      @action="$router.push('/plan')"
    />

    <div v-else class="space-y-6">
      <!-- Anything overspent needs a decision from the user, never an -->
      <!-- automatic adjustment. -->
      <section v-if="needsAttention.length" class="space-y-2">
        <div
          v-for="week in needsAttention"
          :key="week.id"
          class="rounded-[var(--radius-card)] bg-over-soft p-4"
          role="alert"
        >
          <div class="flex items-start gap-3">
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-over" aria-hidden="true" />
            <div class="min-w-0 flex-1">
              <p class="text-sm font-bold text-over">Over budget in week {{ week.week_number }}</p>
              <p class="mt-1 text-sm text-ink">
                You are <MoneyText :amount="week.over_by" size="sm" class="font-bold" /> over your
                week {{ week.week_number }} spending budget.
              </p>
              <button
                type="button"
                class="btn btn-primary mt-3 !min-h-10 !text-sm"
                @click="overspendWeekId = week.id"
              >
                Choose what to do
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Month total -->
      <section v-if="month" class="card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="eyebrow">This cycle</h2>
            <p class="mt-0.5 text-xs text-ink-subtle">
              {{ formatDateRange(month.cycle_start, month.cycle_end) }}
            </p>
          </div>
          <StatusBadge :status="month.status" />
        </div>

        <div class="mt-2 flex items-baseline gap-1.5">
          <MoneyText :amount="month.spent" size="2xl" class="font-bold" />
          <span class="text-sm text-ink-subtle">
            of <MoneyText :amount="month.budget" size="sm" class="font-medium" />
          </span>
        </div>

        <BudgetProgress
          class="mt-3"
          :percentage="month.percentage_used"
          :status="month.status"
          :label="`This cycle: ${month.percentage_used.toFixed(0)}% used`"
        />

        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div>
            <dt class="text-xs text-ink-subtle">Remaining</dt>
            <dd class="mt-0.5"><MoneyText :amount="month.remaining" size="sm" class="font-semibold" colored compact /></dd>
          </div>
          <div>
            <dt class="text-xs text-ink-subtle">Buffer left</dt>
            <dd class="mt-0.5"><MoneyText :amount="month.buffer_remaining" size="sm" class="font-semibold" compact /></dd>
          </div>
          <div>
            <dt class="text-xs text-ink-subtle">Days left</dt>
            <dd class="tabular mt-0.5 text-sm font-semibold text-ink">{{ month.days_remaining }}</dd>
          </div>
        </dl>
      </section>

      <!-- Weekly budgets -->
      <section>
        <SectionHeader title="Weekly budgets" subtitle="Tap a finished week to review it." />

        <ul class="space-y-3">
          <li v-for="week in weeks" :key="week.id">
            <button
              type="button"
              class="card w-full p-4 text-left transition hover:shadow-[var(--shadow-raised)]"
              :class="week.is_current ? 'border-brand' : ''"
              @click="week.is_past || week.is_current ? (reviewWeekId = week.id) : null"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="text-sm font-semibold text-ink">
                    Week {{ week.week_number }}
                    <span v-if="week.is_current" class="badge ml-1.5 bg-brand-soft text-brand">Now</span>
                  </p>
                  <p class="mt-0.5 text-xs text-ink-subtle">
                    {{ formatDateRange(week.start_date, week.end_date) }}
                  </p>
                </div>
                <StatusBadge :status="week.status" />
              </div>

              <div class="mt-2 flex items-baseline gap-1.5">
                <MoneyText :amount="week.spent" size="lg" class="font-bold" />
                <span class="text-sm text-ink-subtle">
                  of <MoneyText :amount="week.budget" size="sm" />
                </span>
              </div>

              <BudgetProgress
                class="mt-2.5"
                :percentage="week.percentage_used"
                :status="week.status"
                :label="`Week ${week.week_number}: ${week.percentage_used.toFixed(0)}% used`"
              />

              <div class="mt-2.5 flex items-center justify-between text-xs text-ink-muted">
                <span>
                  <MoneyText :amount="week.remaining" size="xs" class="font-semibold" colored />
                  remaining
                </span>
                <span v-if="week.days_remaining > 0" class="tabular">
                  <MoneyText :amount="week.recommended_daily" size="xs" class="font-semibold" />
                  / day for {{ week.days_remaining }}
                  {{ week.days_remaining === 1 ? 'day' : 'days' }}
                </span>
              </div>

              <p v-if="week.was_adjusted" class="mt-2 text-xs text-ink-subtle">
                Adjusted from <MoneyText :amount="week.original_budget" size="xs" /> after an overspend.
              </p>
            </button>
          </li>
        </ul>
      </section>

      <!-- Allowances -->
      <section>
        <SectionHeader
          title="Allowances"
          subtitle="Reserved out of your income and drawn down as you spend"
          :action-label="allowances.length ? 'Adjust' : undefined"
          :action-to="allowances.length ? '/plan' : undefined"
        />

        <div v-if="allowances.length" class="card p-4">
          <AllowanceList :allowances="allowances" />
        </div>


        <!-- The link to set one up used to live behind "v-if allowances" —
             visible only once you already had what it was for. -->
        <EmptyState
          v-else
          :icon="PiggyBank"
          title="Nothing set aside yet"
          description="Fuel, groceries, eating out: money that adds up through the cycle. Reserve an amount and it stops competing with your daily budget."
          action-label="Set up allowances"
          @action="$router.push('/plan')"
        />
        <!-- Each pot that has run out, with a way to settle it deliberately. -->
        <div
          v-for="row in overspentAllowances"
          :key="row.category_id"
          class="mt-3 flex items-start gap-3 rounded-[var(--radius-card)] bg-over-soft p-4"
        >
          <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-over" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-over">{{ row.name }} has run out</p>
            <p class="mt-1 text-sm text-ink">
              <MoneyText :amount="row.over_by" size="sm" class="font-bold" /> past the
              <MoneyText :amount="row.allocated" size="sm" /> set aside. Until you move
              money across, it comes out of your weekly budget.
            </p>
            <button
              type="button"
              class="btn btn-primary mt-3 !min-h-10 !text-sm"
              @click="toppingUp = row"
            >
              Top it up
            </button>
          </div>
        </div>
      </section>

      <!-- Category budgets -->
      <section>
        <SectionHeader
          title="Category budgets"
          subtitle="Limits you set per category"
          action-label="Manage"
          action-to="/settings/categories"
        />

        <div v-if="categories.some((category) => category.has_budget)" class="card p-4">
          <CategoryBudgetList :categories="categories" />
        </div>

        <EmptyState
          v-else
          :icon="CalendarRange"
          title="No category budgets yet"
          description="Set a monthly limit on a category to get a warning before you overspend it."
          action-label="Set category budgets"
          @action="$router.push('/settings/categories')"
        />
      </section>
    </div>

    <TopUpAllowanceSheet
      :plan-id="plan?.id ?? null"
      :allowance="toppingUp"
      @close="toppingUp = null"
      @applied="afterAdjustment"
    />

    <OverspendSheet
      :week-id="overspendWeekId"
      @close="overspendWeekId = null"
      @applied="afterAdjustment"
    />

    <WeeklyReviewSheet
      :week-id="reviewWeekId"
      @close="reviewWeekId = null"
      @applied="afterAdjustment"
    />
  </div>
</template>
