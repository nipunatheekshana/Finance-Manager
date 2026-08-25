<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

const icons = {
  success: CheckCircle2,
  error: XCircle,
  warning: AlertTriangle,
  info: Info,
} as const

const tints = {
  success: 'text-safe',
  error: 'text-over',
  warning: 'text-warn',
  info: 'text-info',
} as const

const timers = new Map<number, ReturnType<typeof setTimeout>>()

function scheduleDismissal(id: number, timeout: number): void {
  if (timers.has(id)) return
  timers.set(
    id,
    setTimeout(() => {
      ui.dismissToast(id)
      timers.delete(id)
    }, timeout),
  )
}

watch(
  () => ui.toasts,
  (toasts) => {
    toasts.forEach((toast) => scheduleDismissal(toast.id, toast.timeout))
  },
  { deep: true, immediate: true },
)

onMounted(() => ui.toasts.forEach((toast) => scheduleDismissal(toast.id, toast.timeout)))
</script>

<template>
  <!-- Announced politely so a screen reader hears the outcome of an action. -->
  <div
    class="pointer-events-none fixed inset-x-0 top-0 z-[60] flex flex-col items-center gap-2 px-3 pt-3 pt-safe"
    role="status"
    aria-live="polite"
  >
    <TransitionGroup
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="-translate-y-3 opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="-translate-y-2 opacity-0"
    >
      <div
        v-for="toast in ui.toasts"
        :key="toast.id"
        class="card pointer-events-auto flex w-full max-w-md items-start gap-3 p-3.5 shadow-[var(--shadow-raised)]"
      >
        <component
          :is="icons[toast.kind]"
          class="mt-0.5 h-5 w-5 shrink-0"
          :class="tints[toast.kind]"
          aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-ink">{{ toast.title }}</p>
          <p v-if="toast.message" class="mt-0.5 text-sm text-ink-muted">{{ toast.message }}</p>
        </div>
        <button
          type="button"
          class="-m-2.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-ink-subtle transition hover:bg-ink/5"
          aria-label="Dismiss notification"
          @click="ui.dismissToast(toast.id)"
        >
          <X class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
