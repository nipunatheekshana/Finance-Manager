<script setup lang="ts">
import type { Component } from 'vue'

withDefaults(
  defineProps<{
    icon?: Component
    title: string
    description?: string
    actionLabel?: string
  }>(),
  {},
)

defineEmits<{ action: [] }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center rounded-[var(--radius-card)] border border-dashed border-line px-6 py-12 text-center">
    <div v-if="icon" class="mb-3 rounded-full bg-sunken p-3">
      <component :is="icon" class="h-6 w-6 text-ink-subtle" aria-hidden="true" />
    </div>
    <h3 class="text-base font-semibold text-ink">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-ink-muted">{{ description }}</p>
    <button v-if="actionLabel" type="button" class="btn btn-primary mt-5" @click="$emit('action')">
      {{ actionLabel }}
    </button>
    <slot />
  </div>
</template>
