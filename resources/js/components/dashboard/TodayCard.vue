<script setup lang="ts">
import { computed } from 'vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import { amountToNumber } from '@/composables/useCurrency'
import type { DailySummary } from '@/types'

const props = defineProps<{ today: DailySummary }>()

const isOver = computed(() => props.today.status === 'over')
</script>

<template>
  <section class="card p-4" aria-labelledby="today-heading">
    <div class="flex items-start justify-between gap-3">
      <h2 id="today-heading" class="eyebrow">Today</h2>
      <StatusBadge :status="today.status" />
    </div>

    <div class="mt-2 flex items-baseline gap-1.5">
      <MoneyText :amount="today.spent" size="2xl" class="font-bold" />
      <span class="text-sm text-ink-subtle">
        of <MoneyText :amount="today.recommended" size="sm" class="font-medium" />
      </span>
    </div>

    <BudgetProgress
      class="mt-3"
      :percentage="today.percentage_used"
      :status="today.status"
      :label="`Today: ${today.percentage_used.toFixed(0)}% of the suggested limit used`"
    />

    <p class="mt-2.5 text-sm" :class="isOver ? 'text-over' : 'text-ink-muted'">
      <template v-if="isOver">
        <MoneyText :amount="today.over_by" size="sm" class="font-semibold" /> over today's suggestion.
      </template>
      <template v-else>
        <MoneyText :amount="today.remaining" size="sm" class="font-semibold text-ink" /> left for today.
      </template>
    </p>

    <!-- The pace for the rest of the week, recalculated after every expense. -->
    <p
      v-if="today.days_remaining_in_week > 1 && amountToNumber(today.next_day_recommended) >= 0"
      class="mt-1 text-xs text-ink-subtle"
    >
      From tomorrow: <MoneyText :amount="today.next_day_recommended" size="xs" class="font-semibold" /> a day
      for the last {{ today.days_remaining_in_week - 1 }}
      {{ today.days_remaining_in_week - 1 === 1 ? 'day' : 'days' }} of the week.
    </p>
  </section>
</template>
