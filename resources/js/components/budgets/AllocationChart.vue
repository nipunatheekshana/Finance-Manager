<script setup lang="ts">
import { computed } from 'vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { amountToNumber } from '@/composables/useCurrency'
import type { AllocationBreakdownRow } from '@/types'

const props = defineProps<{ breakdown: AllocationBreakdownRow[] }>()

const TONES: Record<string, string> = {
  fixed_expenses: 'bg-info',
  allowances: 'bg-violet-500',
  debt_payment: 'bg-over',
  savings: 'bg-safe',
  buffer: 'bg-warn',
  spending: 'bg-brand',
}

const rows = computed(() => props.breakdown.filter((row) => amountToNumber(row.amount) > 0))

const total = computed(() =>
  rows.value.reduce((sum, row) => sum + amountToNumber(row.amount), 0),
)

/** Share of the whole, so the bar always fills exactly 100%. */
function share(row: AllocationBreakdownRow): number {
  if (total.value <= 0) return 0
  return (amountToNumber(row.amount) / total.value) * 100
}
</script>

<template>
  <div>
    <!-- A single stacked bar reads faster on a phone than a pie chart. -->
    <div
      class="flex h-4 w-full overflow-hidden rounded-full bg-sunken"
      role="img"
      aria-label="How your income is allocated"
    >
      <div
        v-for="row in rows"
        :key="row.key"
        class="h-full first:rounded-l-full last:rounded-r-full"
        :class="TONES[row.key] ?? 'bg-sunken'"
        :style="{ width: `${share(row)}%` }"
        :title="`${row.label}: ${row.percentage.toFixed(1)}%`"
      />
    </div>

    <ul class="mt-4 space-y-2.5">
      <li v-for="row in rows" :key="row.key" class="flex items-center gap-2.5">
        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="TONES[row.key] ?? 'bg-sunken'" aria-hidden="true" />
        <span class="flex-1 text-sm text-ink-muted">{{ row.label }}</span>
        <span class="tabular shrink-0 text-xs text-ink-subtle">{{ row.percentage.toFixed(0) }}%</span>
        <MoneyText :amount="row.amount" size="sm" class="w-24 shrink-0 text-right font-semibold" compact />
      </li>
    </ul>
  </div>
</template>
