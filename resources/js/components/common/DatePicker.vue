<script setup lang="ts">
import { computed } from 'vue'
import { todayIso } from '@/composables/useDates'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    error?: string
    max?: string
    min?: string
    disabled?: boolean
    /** Show Today / Yesterday shortcuts above the field. */
    quickPicks?: boolean
    id?: string
  }>(),
  { quickPicks: false, disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const fieldId = computed(() => props.id ?? `date-${Math.random().toString(36).slice(2, 9)}`)

const today = todayIso()

const yesterday = computed(() => {
  const date = new Date()
  date.setDate(date.getDate() - 1)
  return date.toISOString().slice(0, 10)
})

const picks = computed(() => [
  { label: 'Today', value: today },
  { label: 'Yesterday', value: yesterday.value },
])
</script>

<template>
  <div>
    <label v-if="label" :for="fieldId" class="label">{{ label }}</label>

    <div v-if="quickPicks" class="mb-2 flex gap-2">
      <button
        v-for="pick in picks"
        :key="pick.value"
        type="button"
        class="badge min-h-9 px-3"
        :class="modelValue === pick.value ? 'bg-brand text-on-brand' : 'bg-sunken text-ink-muted'"
        :aria-pressed="modelValue === pick.value"
        @click="emit('update:modelValue', pick.value)"
      >
        {{ pick.label }}
      </button>
    </div>

    <input
      :id="fieldId"
      :value="modelValue"
      type="date"
      :max="max ?? today"
      :min="min"
      :disabled="disabled"
      :aria-invalid="Boolean(error)"
      class="input"
      :class="error ? 'input-error' : ''"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="error" class="mt-1.5 text-sm text-over">{{ error }}</p>
  </div>
</template>
