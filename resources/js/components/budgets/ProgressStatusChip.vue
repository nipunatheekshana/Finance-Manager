<script setup lang="ts">
import { computed } from 'vue'
import { Check, CircleDashed, Clock } from 'lucide-vue-next'
import type { ProgressStatus } from '@/types'

const props = defineProps<{ status: ProgressStatus; label?: string }>()

/** Never colour alone: each state carries an icon and a word. */
const config = computed(() => {
  switch (props.status) {
    case 'done':
      return { icon: Check, label: 'Done', classes: 'bg-safe-soft text-safe' }
    case 'partial':
      return { icon: Clock, label: 'Part done', classes: 'bg-warn-soft text-warn' }
    default:
      return { icon: CircleDashed, label: 'Not yet', classes: 'bg-sunken text-ink-muted' }
  }
})
</script>

<template>
  <span class="badge shrink-0" :class="config.classes">
    <component :is="config.icon" class="h-3 w-3" aria-hidden="true" />
    {{ label ?? config.label }}
  </span>
</template>
