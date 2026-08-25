<script setup lang="ts">
defineProps<{
  modelValue: string
  options: Array<{ value: string; label: string }>
  ariaLabel?: string
}>()

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <div
    class="inline-flex w-full rounded-[var(--radius-field)] bg-sunken p-1"
    role="tablist"
    :aria-label="ariaLabel"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="tab"
      :aria-selected="modelValue === option.value"
      class="min-h-9 flex-1 rounded-[calc(var(--radius-field)-0.25rem)] px-3 py-1.5 text-sm font-semibold transition"
      :class="
        modelValue === option.value
          ? 'bg-raised text-ink shadow-[var(--shadow-card)]'
          : 'text-ink-muted hover:text-ink'
      "
      @click="$emit('update:modelValue', option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>
