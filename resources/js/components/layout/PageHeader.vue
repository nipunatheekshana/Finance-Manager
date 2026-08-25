<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ChevronLeft } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    title: string
    subtitle?: string
    /** Show a back chevron on mobile for detail screens. */
    backTo?: string
  }>(),
  {},
)

const router = useRouter()

function goBack(to?: string): void {
  if (to) {
    void router.push(to)
    return
  }
  router.back()
}
</script>

<template>
  <header class="mb-5 flex items-start gap-3">
    <button
      v-if="backTo !== undefined"
      type="button"
      class="btn btn-ghost -ml-2 !min-h-11 !w-11 !p-0 shrink-0"
      aria-label="Go back"
      @click="goBack(backTo)"
    >
      <ChevronLeft class="h-5 w-5" aria-hidden="true" />
    </button>

    <div class="min-w-0 flex-1">
      <h1 class="text-2xl font-bold tracking-tight text-ink">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
    </div>

    <div class="shrink-0">
      <slot name="actions" />
    </div>
  </header>
</template>
