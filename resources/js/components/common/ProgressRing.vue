<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    percentage: number
    size?: number
    stroke?: number
    tone?: 'brand' | 'safe' | 'warning' | 'over'
  }>(),
  { size: 72, stroke: 7, tone: 'brand' },
)

const radius = computed(() => (props.size - props.stroke) / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)
const clamped = computed(() => Math.min(100, Math.max(0, props.percentage)))
const offset = computed(() => circumference.value * (1 - clamped.value / 100))

const strokeColor = computed(
  () =>
    ({
      brand: 'rgb(var(--color-brand))',
      safe: 'rgb(var(--color-safe))',
      warning: 'rgb(var(--color-warn))',
      over: 'rgb(var(--color-over))',
    })[props.tone],
)
</script>

<template>
  <div class="relative inline-flex shrink-0 items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
    <svg :width="size" :height="size" class="-rotate-90" aria-hidden="true">
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        stroke="rgb(var(--color-sunken))"
        :stroke-width="stroke"
      />
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        :stroke="strokeColor"
        :stroke-width="stroke"
        stroke-linecap="round"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="offset"
        class="transition-[stroke-dashoffset] duration-700 ease-out"
      />
    </svg>

    <div class="absolute inset-0 flex flex-col items-center justify-center">
      <slot>
        <span class="tabular text-sm font-bold text-ink">{{ Math.round(clamped) }}%</span>
      </slot>
    </div>
  </div>
</template>
