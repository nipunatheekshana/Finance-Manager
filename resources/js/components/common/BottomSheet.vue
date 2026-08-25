<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { X } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    description?: string
    /** Prevent closing while a submit is in flight. */
    busy?: boolean
  }>(),
  { busy: false },
)

const emit = defineEmits<{ close: [] }>()

const panel = ref<HTMLElement | null>(null)
const previouslyFocused = ref<HTMLElement | null>(null)

function close(): void {
  if (props.busy) return
  emit('close')
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
    return
  }

  // Keep tabbing inside the sheet while it is open.
  if (event.key !== 'Tab' || !panel.value) return

  const focusable = panel.value.querySelectorAll<HTMLElement>(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )
  if (focusable.length === 0) return

  const first = focusable[0]!
  const last = focusable[focusable.length - 1]!

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      previouslyFocused.value = document.activeElement as HTMLElement | null
      document.body.style.overflow = 'hidden'
      document.addEventListener('keydown', onKeydown)
      await nextTick()
      panel.value?.querySelector<HTMLElement>('[data-autofocus]')?.focus()
    } else {
      document.body.style.overflow = ''
      document.removeEventListener('keydown', onKeydown)
      previouslyFocused.value?.focus()
    }
  },
)

onBeforeUnmount(() => {
  document.body.style.overflow = ''
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
      <div
        class="animate-fade-in absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]"
        aria-hidden="true"
        @click="close"
      />

      <div
        ref="panel"
        class="animate-sheet-in relative flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-raised shadow-[var(--shadow-raised)] sm:max-w-lg sm:rounded-3xl"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <!-- Grab handle, mobile only. -->
        <div class="flex justify-center pt-3 sm:hidden" aria-hidden="true">
          <div class="h-1 w-10 rounded-full bg-line" />
        </div>

        <header class="flex items-start justify-between gap-4 px-5 pb-3 pt-4">
          <div>
            <h2 class="text-lg font-semibold text-ink">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-sm text-ink-muted">{{ description }}</p>
          </div>
          <button
            type="button"
            class="btn btn-ghost -mr-2 -mt-1 !min-h-11 !w-11 !p-0"
            :disabled="busy"
            aria-label="Close"
            @click="close"
          >
            <X class="h-5 w-5" aria-hidden="true" />
          </button>
        </header>

        <div class="flex-1 overflow-y-auto px-5 pb-2">
          <slot />
        </div>

        <footer v-if="$slots.footer" class="border-t border-line px-5 py-4 pb-safe">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>
