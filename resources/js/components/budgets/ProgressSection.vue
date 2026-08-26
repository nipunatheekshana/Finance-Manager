<script setup lang="ts">
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import ProgressStatusChip from './ProgressStatusChip.vue'
import type { ProgressStatus } from '@/types'

/**
 * The repeating frame of the board: a title, what is left to do, and one bar.
 * Each section fills the slot with its own rows.
 */
withDefaults(
  defineProps<{
    title: string
    /** Money still to settle, or spend. */
    outstanding?: string
    outstandingLabel?: string
    planned?: string
    settled?: string
    percentage: number
    status?: ProgressStatus
    /** Overrides the chip when a section is not a task list. */
    chipLabel?: string
    tone?: 'brand' | 'safe' | 'warning' | 'over'
    subtitle?: string
  }>(),
  { tone: 'brand', outstandingLabel: 'still to go' },
)
</script>

<template>
  <section class="card p-4">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 class="text-base font-semibold text-ink">{{ title }}</h2>
        <p v-if="subtitle" class="mt-0.5 text-xs text-ink-subtle">{{ subtitle }}</p>
      </div>
      <ProgressStatusChip v-if="status" :status="status" :label="chipLabel" />
    </div>

    <div v-if="settled !== undefined && planned !== undefined" class="mt-2 flex items-baseline gap-1.5">
      <MoneyText :amount="settled" size="xl" class="font-bold" />
      <span class="text-sm text-ink-subtle">
        of <MoneyText :amount="planned" size="sm" class="font-medium" />
      </span>
    </div>

    <BudgetProgress
      class="mt-2.5"
      :percentage="percentage"
      :status="tone === 'over' ? 'over' : tone === 'warning' ? 'warning' : 'safe'"
      :label="`${title}: ${percentage.toFixed(0)}%`"
    />

    <p v-if="outstanding !== undefined" class="mt-2 text-sm text-ink-muted">
      <MoneyText :amount="outstanding" size="sm" class="font-semibold text-ink" />
      {{ outstandingLabel }}
    </p>

    <div v-if="$slots.default" class="mt-3 divide-y divide-line border-t border-line">
      <slot />
    </div>
  </section>
</template>
