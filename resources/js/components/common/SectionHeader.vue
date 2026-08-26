<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
  actionLabel?: string
  actionTo?: string
}>()

defineEmits<{ action: [] }>()
</script>

<template>
  <div class="mb-3 flex items-end justify-between gap-3">
    <div class="min-w-0">
      <h2 class="text-base font-semibold text-ink">{{ title }}</h2>
      <p v-if="subtitle" class="mt-0.5 text-sm text-ink-muted">{{ subtitle }}</p>
    </div>

    <RouterLink
      v-if="actionLabel && actionTo"
      :to="actionTo"
      class="-mr-2 flex min-h-11 shrink-0 items-center px-2 text-sm font-semibold text-brand"
    >
      {{ actionLabel }}
    </RouterLink>
    <button
      v-else-if="actionLabel"
      type="button"
      class="-mr-2 flex min-h-11 shrink-0 items-center px-2 text-sm font-semibold text-brand"
      @click="$emit('action')"
    >
      {{ actionLabel }}
    </button>
  </div>
</template>
