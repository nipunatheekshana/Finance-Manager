<script setup lang="ts">
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import { formatDateRange } from '@/composables/useDates'
import type { WeeklySummary } from '@/types'

defineProps<{ week: WeeklySummary }>()
</script>

<template>
  <section class="card p-4" aria-labelledby="week-heading">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 id="week-heading" class="eyebrow">Week {{ week.week_number }}</h2>
        <p class="mt-0.5 text-xs text-ink-subtle">
          {{ formatDateRange(week.start_date, week.end_date) }}
        </p>
      </div>
      <StatusBadge :status="week.status" />
    </div>

    <div class="mt-2 flex items-baseline gap-1.5">
      <MoneyText :amount="week.spent" size="2xl" class="font-bold" />
      <span class="text-sm text-ink-subtle">
        of <MoneyText :amount="week.budget" size="sm" class="font-medium" />
      </span>
    </div>

    <BudgetProgress
      class="mt-3"
      :percentage="week.percentage_used"
      :status="week.status"
      :label="`Week ${week.week_number}: ${week.percentage_used.toFixed(0)}% of budget used`"
    />

    <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
      <div>
        <dt class="text-xs text-ink-subtle">Remaining</dt>
        <dd class="mt-0.5"><MoneyText :amount="week.remaining" size="sm" class="font-semibold" colored compact /></dd>
      </div>
      <div>
        <dt class="text-xs text-ink-subtle">Days left</dt>
        <dd class="tabular mt-0.5 text-sm font-semibold text-ink">{{ week.days_remaining }}</dd>
      </div>
      <div>
        <dt class="text-xs text-ink-subtle">Per day</dt>
        <dd class="mt-0.5"><MoneyText :amount="week.recommended_daily" size="sm" class="font-semibold" compact /></dd>
      </div>
    </dl>

    <p v-if="week.was_adjusted" class="mt-2.5 text-xs text-ink-subtle">
      Adjusted from <MoneyText :amount="week.original_budget" size="xs" /> after an earlier overspend.
    </p>
  </section>
</template>
