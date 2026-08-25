<script setup lang="ts">
import { computed } from 'vue'
import { ChevronDown } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null
    options: Array<{ value: string | number; label: string }>
    label?: string
    placeholder?: string
    error?: string
    hint?: string
    disabled?: boolean
    id?: string
  }>(),
  { disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [string | number | null] }>()

const fieldId = computed(() => props.id ?? `select-${Math.random().toString(36).slice(2, 9)}`)

function onChange(event: Event): void {
  const raw = (event.target as HTMLSelectElement).value
  if (raw === '') {
    emit('update:modelValue', null)
    return
  }
  // Preserve the original option type so numeric ids stay numbers.
  const match = props.options.find((option) => String(option.value) === raw)
  emit('update:modelValue', match ? match.value : raw)
}
</script>

<template>
  <div>
    <label v-if="label" :for="fieldId" class="label">{{ label }}</label>

    <div class="relative">
      <select
        :id="fieldId"
        :value="modelValue === null ? '' : String(modelValue)"
        :disabled="disabled"
        :aria-invalid="Boolean(error)"
        class="input appearance-none pr-10"
        :class="error ? 'input-error' : ''"
        @change="onChange"
      >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="String(option.value)">
          {{ option.label }}
        </option>
      </select>

      <ChevronDown
        class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle"
        aria-hidden="true"
      />
    </div>

    <p v-if="error" class="mt-1.5 text-sm text-over">{{ error }}</p>
    <p v-else-if="hint" class="mt-1.5 text-sm text-ink-muted">{{ hint }}</p>
  </div>
</template>
