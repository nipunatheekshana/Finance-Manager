<script setup lang="ts">
import { computed } from 'vue'

// Fallthrough attributes belong on the input itself, so things like
// data-autofocus target a focusable element rather than the wrapper.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    type?: string
    placeholder?: string
    error?: string
    hint?: string
    autocomplete?: string
    inputmode?: 'text' | 'numeric' | 'decimal' | 'email' | 'tel' | 'search'
    required?: boolean
    disabled?: boolean
    id?: string
    min?: string | number
    max?: string | number
  }>(),
  { type: 'text', required: false, disabled: false },
)

defineEmits<{ 'update:modelValue': [string] }>()

const fieldId = computed(() => props.id ?? `field-${Math.random().toString(36).slice(2, 9)}`)
</script>

<template>
  <div>
    <label v-if="label" :for="fieldId" class="label">
      {{ label }}
      <span v-if="required" class="text-over" aria-hidden="true">*</span>
    </label>

    <input
      :id="fieldId"
      :value="modelValue"
      :type="type"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :required="required"
      :disabled="disabled"
      :min="min"
      :max="max"
      :aria-invalid="Boolean(error)"
      :aria-describedby="error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined"
      v-bind="$attrs"
      class="input"
      :class="error ? 'input-error' : ''"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="error" :id="`${fieldId}-error`" class="mt-1.5 text-sm text-over">{{ error }}</p>
    <p v-else-if="hint" :id="`${fieldId}-hint`" class="mt-1.5 text-sm text-ink-muted">{{ hint }}</p>
  </div>
</template>
