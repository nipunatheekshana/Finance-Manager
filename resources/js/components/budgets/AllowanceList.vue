<script setup lang="ts">
import { AlertTriangle, TrendingUp } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import type { AllowanceSummary } from '@/types'

withDefaults(
  defineProps<{
    allowances: AllowanceSummary[]
    /** Show the per-day figure and the pace warning. */
    detailed?: boolean
  }>(),
  { detailed: true },
)
</script>

<template>
  <ul class="space-y-4">
    <li v-for="allowance in allowances" :key="allowance.category_id">
      <div class="flex items-center gap-3">
        <CategoryIcon :icon="allowance.icon" :color="allowance.color" size="sm" />

        <div class="min-w-0 flex-1">
          <div class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium text-ink">{{ allowance.name }}</span>
            <span class="tabular shrink-0 text-sm text-ink-muted">
              <MoneyText :amount="allowance.spent" size="sm" class="font-semibold text-ink" compact />
              /
              <MoneyText :amount="allowance.allocated" size="sm" compact />
            </span>
          </div>

          <BudgetProgress
            class="mt-1.5"
            height="sm"
            :percentage="allowance.percentage_used"
            :status="allowance.status"
            :label="`${allowance.name}: ${allowance.percentage_used.toFixed(0)}% of the allowance used`"
          />

          <div class="mt-1.5 flex items-baseline justify-between gap-2 text-xs">
            <span v-if="allowance.status === 'over'" class="font-medium text-over">
              Over by <MoneyText :amount="allowance.over_by" size="xs" class="font-semibold" />
            </span>
            <span v-else class="text-ink-muted">
              <MoneyText :amount="allowance.remaining" size="xs" class="font-semibold text-ink" />
              left
            </span>

            <!-- The figure that actually helps day to day. -->
            <span
              v-if="detailed && allowance.days_remaining > 0 && allowance.status !== 'over'"
              class="tabular shrink-0 text-ink-subtle"
            >
              <MoneyText :amount="allowance.daily_allowance" size="xs" class="font-semibold" />
              a day for {{ allowance.days_remaining }}
              {{ allowance.days_remaining === 1 ? 'day' : 'days' }}
            </span>
          </div>

          <!-- Percentage alone is meaningless for something spent gradually:
               60% gone is fine late in the cycle and a problem early. -->
          <p
            v-if="detailed && allowance.ahead_of_pace && allowance.status !== 'over'"
            class="mt-1 flex items-center gap-1 text-xs font-medium text-warn"
          >
            <TrendingUp class="h-3 w-3 shrink-0" aria-hidden="true" />
            <MoneyText :amount="allowance.pace_difference" size="xs" class="font-semibold" />
            ahead of an even pace
          </p>

          <p
            v-else-if="detailed && allowance.status === 'over'"
            class="mt-1 flex items-center gap-1 text-xs font-medium text-over"
          >
            <AlertTriangle class="h-3 w-3 shrink-0" aria-hidden="true" />
            Anything more comes out of your day-to-day money
          </p>
        </div>
      </div>
    </li>
  </ul>
</template>
