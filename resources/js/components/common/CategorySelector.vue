<script setup lang="ts">
import CategoryIcon from './CategoryIcon.vue'
import type { Category } from '@/types'

defineProps<{
  modelValue: number | null
  categories: Category[]
  label?: string
  error?: string
}>()

defineEmits<{ 'update:modelValue': [number] }>()
</script>

<template>
  <div>
    <span v-if="label" class="label">{{ label }}</span>

    <!-- A scrollable chip row keeps one-tap selection on a small screen. -->
    <div
      class="no-scrollbar -mx-1 flex gap-2 overflow-x-auto px-1 pb-1"
      role="radiogroup"
      :aria-label="label ?? 'Category'"
    >
      <button
        v-for="category in categories"
        :key="category.id"
        type="button"
        role="radio"
        :aria-checked="modelValue === category.id"
        class="flex min-h-11 shrink-0 items-center gap-2 rounded-[var(--radius-pill)] border px-3 py-2 text-sm font-medium transition"
        :class="
          modelValue === category.id
            ? 'border-brand bg-brand-soft text-ink'
            : 'border-line bg-raised text-ink-muted hover:border-ink-subtle'
        "
        @click="$emit('update:modelValue', category.id)"
      >
        <CategoryIcon :icon="category.icon" :color="category.color" size="sm" :chip="false" />
        {{ category.name }}
      </button>
    </div>

    <p v-if="error" class="mt-1.5 text-sm text-over">{{ error }}</p>
  </div>
</template>
