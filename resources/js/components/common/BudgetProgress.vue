<script setup lang="ts">
import { computed } from 'vue'
import type { BudgetStatus } from '@/types'

const props = withDefaults(
  defineProps<{
    percentage: number
    status?: BudgetStatus
    /** Accessible description of what the bar represents. */
    label?: string
    height?: 'sm' | 'md' | 'lg'
    showMarker?: boolean
    markerAt?: number
  }>(),
  { status: 'safe', height: 'md', showMarker: false, markerAt: 80 },
)

const clamped = computed(() => Math.min(100, Math.max(0, props.percentage)))

const barClass = computed(
  () =>
    ({
      safe: 'bg-safe',
      warning: 'bg-warn',
      over: 'bg-over',
    })[props.status],
)

const heightClass = computed(
  () => ({ sm: 'h-1.5', md: 'h-2.5', lg: 'h-3.5' })[props.height],
)
</script>

<template>
  <div
    class="relative w-full overflow-hidden rounded-full bg-sunken"
    :class="heightClass"
    role="progressbar"
    :aria-valuenow="Math.round(clamped)"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-label="label ?? `${Math.round(percentage)}% used`"
  >
    <div
      class="h-full rounded-full transition-[width] duration-500 ease-out"
      :class="barClass"
      :style="{ width: `${clamped}%` }"
    />
    <!-- Where the warning threshold sits, so the bar is readable at a glance. -->
    <div
      v-if="showMarker && markerAt < 100"
      class="absolute inset-y-0 w-px bg-ink/25"
      :style="{ left: `${markerAt}%` }"
      aria-hidden="true"
    />
  </div>
</template>
