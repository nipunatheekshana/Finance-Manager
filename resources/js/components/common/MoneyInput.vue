<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'

// Fallthrough attributes belong on the input itself, so things like
// data-autofocus target a focusable element rather than the wrapper.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    placeholder?: string
    error?: string
    hint?: string
    autofocus?: boolean
    disabled?: boolean
    /** Renders the amount large, for the primary field on the expense sheet. */
    large?: boolean
    id?: string
  }>(),
  { placeholder: '0', autofocus: false, disabled: false, large: false },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const input = ref<HTMLInputElement | null>(null)
const fieldId = computed(() => props.id ?? `money-${Math.random().toString(36).slice(2, 9)}`)

/**
 * Keep only digits and a single decimal point, capped at two places, so the
 * value always matches the DECIMAL(15,2) column it lands in.
 */
function sanitise(raw: string): string {
  let cleaned = raw.replace(/[^0-9.]/g, '')

  const firstDot = cleaned.indexOf('.')
  if (firstDot !== -1) {
    cleaned = cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '')
    const [whole, decimals = ''] = cleaned.split('.')
    cleaned = `${whole}.${decimals.slice(0, 2)}`
  }

  return cleaned
}

function onInput(event: Event): void {
  emit('update:modelValue', sanitise((event.target as HTMLInputElement).value))
}

/** Tidy up to a plain number on blur; the caller formats for display. */
function onBlur(): void {
  if (props.modelValue === '' || props.modelValue === '.') {
    emit('update:modelValue', '')
    return
  }
  const value = Number.parseFloat(props.modelValue)
  if (Number.isFinite(value)) {
    emit('update:modelValue', String(value))
  }
}

function focus(): void {
  input.value?.focus()
  input.value?.select()
}

onMounted(() => {
  if (props.autofocus) {
    // Wait for the sheet transition so mobile keyboards open reliably.
    void nextTick(() => setTimeout(focus, 60))
  }
})

defineExpose({ focus })
</script>

<template>
  <div>
    <label v-if="label" :for="fieldId" class="label">{{ label }}</label>

    <div class="relative">
      <span
        class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 font-semibold text-ink-subtle"
        :class="large ? 'text-2xl' : 'text-base'"
        aria-hidden="true"
      >
        LKR
      </span>

      <input
        :id="fieldId"
        ref="input"
        :value="modelValue"
        type="text"
        inputmode="decimal"
        enterkeyhint="done"
        autocomplete="off"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="Boolean(error)"
        :aria-describedby="error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined"
        v-bind="$attrs"
        class="input tabular font-semibold"
        :class="[large ? 'h-16 pl-16 text-3xl' : 'pl-14', error ? 'input-error' : '']"
        @input="onInput"
        @blur="onBlur"
      />
    </div>

    <p v-if="error" :id="`${fieldId}-error`" class="mt-1.5 text-sm text-over">{{ error }}</p>
    <p v-else-if="hint" :id="`${fieldId}-hint`" class="mt-1.5 text-sm text-ink-muted">{{ hint }}</p>
  </div>
</template>
