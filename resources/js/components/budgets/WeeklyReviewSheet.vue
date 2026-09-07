<script setup lang="ts">
import { ref, watch } from 'vue'
import { ArrowRight, PiggyBank } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import SelectField from '@/components/common/SelectField.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import { useBudgetStore } from '@/stores/budget'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { formatDateRange } from '@/composables/useDates'
import { formatLKR } from '@/composables/useCurrency'
import type { AdjustmentType, LeftoverOptions, WeeklyReview } from '@/types'

const props = defineProps<{ weekId: number | null }>()
const emit = defineEmits<{ close: []; applied: [] }>()

const budget = useBudgetStore()
const ui = useUiStore()

const review = ref<WeeklyReview | null>(null)
const leftover = ref<LeftoverOptions | null>(null)
const goalId = ref<number | null>(null)
const loading = ref(false)
const applying = ref(false)

watch(
  () => props.weekId,
  async (id) => {
    review.value = null
    leftover.value = null
    goalId.value = null
    if (id === null) return

    loading.value = true
    try {
      const result = await budget.weeklyReview(id)
      review.value = result.review
      leftover.value = result.leftover
      goalId.value = result.leftover.goals[0]?.id ?? null
    } catch (error) {
      if (error instanceof ApiError) ui.error('Could not load the review', error.message)
    } finally {
      loading.value = false
    }
  },
)

/**
 * The week is over and money is left. Hand it to next week, or put it into a
 * goal now — either way it is a real move, and the cycle still adds up.
 */
async function settleLeftover(type: 'carry_forward' | 'savings'): Promise<void> {
  if (props.weekId === null || leftover.value === null) return

  applying.value = true
  try {
    await budget.applyAdjustment(props.weekId, type, {
      amount: leftover.value.remaining,
      ...(type === 'savings' && goalId.value !== null ? { savings_goal_id: goalId.value } : {}),
    })

    ui.success(
      type === 'carry_forward'
        ? `Week ${leftover.value.next_week_number} gets the extra`
        : 'Leftover saved',
      type === 'carry_forward'
        ? `${formatLKR(leftover.value.remaining)} carried forward.`
        : `${formatLKR(leftover.value.remaining)} put into your goal.`,
    )
    emit('applied')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not move the leftover', error.message)
  } finally {
    applying.value = false
  }
}

async function choose(type: AdjustmentType): Promise<void> {
  if (props.weekId === null) return

  applying.value = true
  try {
    await budget.applyAdjustment(props.weekId, type)
    ui.success(type === 'ignore' ? 'Plan kept as it is' : 'Plan updated')
    emit('applied')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not apply that change', error.message)
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <BottomSheet
    :open="weekId !== null"
    title="Weekly review"
    :busy="applying"
    @close="emit('close')"
  >
    <div v-if="loading" class="py-8 text-center text-sm text-ink-muted">Loading…</div>

    <div v-else-if="review" class="space-y-4 pb-2">
      <div class="text-center">
        <p class="text-sm text-ink-muted">
          Week {{ review.week.week_number }} ·
          {{ formatDateRange(review.week.start_date, review.week.end_date) }}
        </p>
        <MoneyText :amount="review.week.spent" size="3xl" class="mt-1 block font-bold" />
        <p class="mt-0.5 text-sm text-ink-muted">
          of <MoneyText :amount="review.week.budget" size="sm" class="font-semibold" /> budgeted
        </p>
        <div class="mt-2 flex justify-center">
          <StatusBadge :status="review.week.status" />
        </div>
      </div>

      <BudgetProgress
        :percentage="review.week.percentage_used"
        :status="review.week.status"
        height="lg"
        :label="`Week ${review.week.week_number}: ${review.week.percentage_used.toFixed(0)}% used`"
      />

      <div class="card divide-y divide-line">
        <div v-if="review.is_over_budget" class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-ink-muted">Over by</span>
          <MoneyText :amount="review.over_by" size="sm" class="font-bold text-over" />
        </div>
        <div v-else class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-ink-muted">Left over</span>
          <MoneyText :amount="review.week.remaining" size="sm" class="font-bold text-safe" />
        </div>

        <div v-if="review.top_category" class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-ink-muted">Top category</span>
          <span class="flex items-center gap-2">
            <CategoryIcon
              :icon="review.top_category.icon"
              :color="review.top_category.color"
              size="sm"
              :chip="false"
            />
            <span class="text-sm font-semibold text-ink">{{ review.top_category.name }}</span>
            <MoneyText :amount="review.top_category.amount" size="sm" class="text-ink-muted" compact />
          </span>
        </div>

        <div class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-ink-muted">Saved</span>
          <MoneyText :amount="review.savings" size="sm" class="font-semibold" colored signed />
        </div>

        <div class="flex items-center justify-between px-4 py-3">
          <span class="text-sm text-ink-muted">Debt payments</span>
          <MoneyText :amount="review.debt_payments" size="sm" class="font-semibold" />
        </div>
      </div>

      <!-- Left over, and the week is finished: it is real money with no home.
           Left alone it sits in the month until the end; here it can go
           somewhere useful now. -->
      <div
        v-if="leftover && (leftover.can_carry || leftover.can_save)"
        class="rounded-[var(--radius-card)] border border-brand/40 bg-brand-soft p-4"
      >
        <p class="text-sm font-semibold text-ink">
          <MoneyText :amount="leftover.remaining" size="sm" class="font-bold" /> left over.
          What should happen to it?
        </p>

        <div class="mt-3 space-y-2">
          <button
            v-if="leftover.can_carry"
            type="button"
            class="btn btn-primary w-full !min-h-11 !justify-start !text-sm"
            :disabled="applying"
            @click="settleLeftover('carry_forward')"
          >
            <ArrowRight class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span class="flex-1 text-left">
              Add it to week {{ leftover.next_week_number }}
              <span class="block text-xs font-normal opacity-80">
                Week {{ leftover.next_week_number }} becomes
                <MoneyText :amount="leftover.resulting_next_week ?? '0'" size="xs" class="font-semibold" />
              </span>
            </span>
          </button>

          <div v-if="leftover.can_save" class="rounded-[var(--radius-field)] bg-raised p-3">
            <SelectField
              v-if="leftover.goals.length > 1"
              v-model="goalId"
              label="Goal"
              :options="leftover.goals.map((goal) => ({ value: goal.id, label: goal.name }))"
            />
            <button
              type="button"
              class="btn btn-secondary mt-2 w-full !min-h-11 !justify-start !text-sm"
              :disabled="applying || goalId === null"
              @click="settleLeftover('savings')"
            >
              <PiggyBank class="h-4 w-4 shrink-0" aria-hidden="true" />
              <span class="flex-1 text-left">
                Put it into
                {{ leftover.goals.find((goal) => goal.id === goalId)?.name ?? 'savings' }} now
                <span class="block text-xs font-normal text-ink-muted">A real deposit, recorded on the goal.</span>
              </span>
            </button>
          </div>
        </div>

        <p class="mt-3 text-xs text-ink-subtle">
          Or leave it: it stays in this cycle's total and is settled at month end.
        </p>
      </div>

      <div v-if="review.categories.length" class="card p-4">
        <p class="eyebrow mb-3">Where it went</p>
        <ul class="space-y-2.5">
          <li
            v-for="category in review.categories.slice(0, 5)"
            :key="category.category_id"
            class="flex items-center gap-2.5"
          >
            <CategoryIcon :icon="category.icon" :color="category.color" size="sm" />
            <span class="flex-1 truncate text-sm text-ink">{{ category.name }}</span>
            <span class="tabular shrink-0 text-xs text-ink-subtle">
              {{ category.percentage.toFixed(0) }}%
            </span>
            <MoneyText :amount="category.amount" size="sm" class="shrink-0 font-semibold" compact />
          </li>
        </ul>
      </div>
    </div>

    <template #footer>
      <div v-if="review?.is_over_budget" class="space-y-2">
        <p class="text-center text-sm text-ink-muted">What would you like to do?</p>
        <div class="grid grid-cols-3 gap-2">
          <button type="button" class="btn btn-secondary !px-2 !text-xs" :disabled="applying" @click="choose('next_week')">
            Adjust next week
          </button>
          <button type="button" class="btn btn-secondary !px-2 !text-xs" :disabled="applying" @click="choose('buffer')">
            Use buffer
          </button>
          <button type="button" class="btn btn-secondary !px-2 !text-xs" :disabled="applying" @click="choose('ignore')">
            Keep plan
          </button>
        </div>
      </div>

      <button v-else type="button" class="btn btn-primary w-full" @click="emit('close')">Done</button>
    </template>
  </BottomSheet>
</template>
