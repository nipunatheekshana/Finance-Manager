<script setup lang="ts">
import { computed } from 'vue'
import { formatLKR, formatLKRCompact } from '@/composables/useCurrency'
import type { Money } from '@/types'

const props = withDefaults(
  defineProps<{
    amount: Money | number | null | undefined
    /** Shorten large figures to LKR 377k for tight spaces. */
    compact?: boolean
    cents?: boolean
    signed?: boolean
    /** Colour negatives red and positives green. */
    colored?: boolean
    size?: 'xs' | 'sm' | 'base' | 'lg' | 'xl' | '2xl' | '3xl'
  }>(),
  { compact: false, signed: false, colored: false, size: 'base' },
)

const value = computed(() => {
  const raw = props.amount
  if (raw === null || raw === undefined) return 0
  return typeof raw === 'number' ? raw : Number.parseFloat(raw) || 0
})

const text = computed(() =>
  props.compact
    ? formatLKRCompact(props.amount)
    : formatLKR(props.amount, { cents: props.cents, signed: props.signed }),
)

const sizeClass = computed(
  () =>
    ({
      xs: 'text-xs',
      sm: 'text-sm',
      base: 'text-base',
      lg: 'text-lg',
      xl: 'text-xl',
      '2xl': 'text-2xl',
      '3xl': 'text-3xl',
    })[props.size],
)

const colorClass = computed(() => {
  if (!props.colored) return ''
  if (value.value < 0) return 'text-over'
  if (value.value > 0) return 'text-safe'
  return ''
})
</script>

<template>
  <span class="tabular" :class="[sizeClass, colorClass]">{{ text }}</span>
</template>
