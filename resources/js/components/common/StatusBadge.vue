<script setup lang="ts">
import { computed } from 'vue'
import { CheckCircle2, AlertTriangle, XCircle, Info } from 'lucide-vue-next'
import type { BudgetStatus } from '@/types'

const props = withDefaults(
  defineProps<{
    status: BudgetStatus | 'info'
    /** Override the default wording for the status. */
    label?: string
    iconOnly?: boolean
  }>(),
  { iconOnly: false },
)

/**
 * Status is never signalled by colour alone: every badge carries an icon and
 * a text label as well.
 */
const config = computed(() => {
  switch (props.status) {
    case 'safe':
      return { icon: CheckCircle2, label: 'On track', classes: 'bg-safe-soft text-safe' }
    case 'warning':
      return { icon: AlertTriangle, label: 'Close to limit', classes: 'bg-warn-soft text-warn' }
    case 'over':
      return { icon: XCircle, label: 'Over budget', classes: 'bg-over-soft text-over' }
    default:
      return { icon: Info, label: 'Info', classes: 'bg-info-soft text-info' }
  }
})
</script>

<template>
  <span class="badge" :class="config.classes">
    <component :is="config.icon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
    <span v-if="!iconOnly">{{ label ?? config.label }}</span>
    <span v-else class="sr-only">{{ label ?? config.label }}</span>
  </span>
</template>
