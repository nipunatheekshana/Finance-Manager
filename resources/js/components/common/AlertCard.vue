<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next'
import type { FinancialAlert } from '@/types'

const props = defineProps<{ alert: FinancialAlert }>()
const emit = defineEmits<{ dismiss: [number] }>()

const router = useRouter()

const config = computed(() => {
  switch (props.alert.severity) {
    case 'critical':
      return { icon: XCircle, wrap: 'bg-over-soft', tint: 'text-over' }
    case 'warning':
      return { icon: AlertTriangle, wrap: 'bg-warn-soft', tint: 'text-warn' }
    case 'success':
      return { icon: CheckCircle2, wrap: 'bg-safe-soft', tint: 'text-safe' }
    default:
      return { icon: Info, wrap: 'bg-info-soft', tint: 'text-info' }
  }
})

function act(): void {
  if (props.alert.action_route) {
    void router.push(props.alert.action_route)
  }
}
</script>

<template>
  <div class="flex items-start gap-3 rounded-[var(--radius-card)] p-3.5" :class="config.wrap">
    <component :is="config.icon" class="mt-0.5 h-5 w-5 shrink-0" :class="config.tint" aria-hidden="true" />

    <div class="min-w-0 flex-1">
      <p class="text-sm font-semibold text-ink">{{ alert.title }}</p>
      <p class="mt-0.5 text-sm text-ink-muted">{{ alert.message }}</p>

      <button
        v-if="alert.action_label && alert.action_route"
        type="button"
        class="mt-2 text-sm font-semibold underline underline-offset-2"
        :class="config.tint"
        @click="act"
      >
        {{ alert.action_label }}
      </button>
    </div>

    <button
      type="button"
      class="-m-2.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-ink-subtle transition hover:bg-ink/5"
      :aria-label="`Dismiss: ${alert.title}`"
      @click="emit('dismiss', alert.id)"
    >
      <X class="h-4 w-4" aria-hidden="true" />
    </button>
  </div>
</template>
