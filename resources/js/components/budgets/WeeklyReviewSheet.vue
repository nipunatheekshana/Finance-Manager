<script setup lang="ts">
import { ref, watch } from 'vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import { useBudgetStore } from '@/stores/budget'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { formatDateRange } from '@/composables/useDates'
import type { AdjustmentType, WeeklyReview } from '@/types'

const props = defineProps<{ weekId: number | null }>()
const emit = defineEmits<{ close: []; applied: [] }>()

const budget = useBudgetStore()
const ui = useUiStore()

const review = ref<WeeklyReview | null>(null)
const loading = ref(false)
const applying = ref(false)

watch(
  () => props.weekId,
  async (id) => {
    review.value = null
    if (id === null) return

    loading.value = true
    try {
      const result = await budget.weeklyReview(id)
      review.value = result.review
    } catch (error) {
      if (error instanceof ApiError) ui.error('Could not load the review', error.message)
    } finally {
      loading.value = false
    }
  },
)

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
