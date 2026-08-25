<script setup lang="ts">
withDefaults(
  defineProps<{
    /** How many skeleton rows to draw. */
    rows?: number
    variant?: 'cards' | 'list' | 'text'
  }>(),
  { rows: 3, variant: 'cards' },
)
</script>

<template>
  <div class="space-y-3" role="status" aria-live="polite" aria-busy="true">
    <span class="sr-only">Loading</span>

    <template v-if="variant === 'cards'">
      <div v-for="row in rows" :key="row" class="card p-4">
        <div class="skeleton h-3 w-24" />
        <div class="skeleton mt-3 h-7 w-36" />
        <div class="skeleton mt-3 h-2.5 w-full" />
      </div>
    </template>

    <template v-else-if="variant === 'list'">
      <div v-for="row in rows" :key="row" class="flex items-center gap-3 py-3">
        <div class="skeleton h-10 w-10 rounded-full" />
        <div class="flex-1 space-y-2">
          <div class="skeleton h-3.5 w-32" />
          <div class="skeleton h-3 w-20" />
        </div>
        <div class="skeleton h-4 w-16" />
      </div>
    </template>

    <template v-else>
      <div v-for="row in rows" :key="row" class="skeleton h-4" :style="{ width: `${100 - row * 12}%` }" />
    </template>
  </div>
</template>
