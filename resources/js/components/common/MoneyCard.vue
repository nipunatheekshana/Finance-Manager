<script setup lang="ts">
import MoneyText from './MoneyText.vue'

withDefaults(
  defineProps<{
    label: string
    amount: string | number | null | undefined
    caption?: string
    /** Renders the card as a link-like button. */
    clickable?: boolean
    emphasis?: boolean
    compact?: boolean
  }>(),
  { clickable: false, emphasis: false, compact: false },
)

defineEmits<{ click: [] }>()
</script>

<template>
  <component
    :is="clickable ? 'button' : 'div'"
    :type="clickable ? 'button' : undefined"
    class="card w-full p-4 text-left"
    :class="clickable ? 'transition hover:shadow-[var(--shadow-raised)] active:scale-[0.99]' : ''"
    @click="clickable && $emit('click')"
  >
    <p class="eyebrow">{{ label }}</p>
    <MoneyText
      :amount="amount"
      :compact="compact"
      :size="emphasis ? '3xl' : 'xl'"
      class="mt-1 block font-semibold"
      colored
    />
    <p v-if="caption" class="mt-1 text-sm text-ink-muted">{{ caption }}</p>
    <slot />
  </component>
</template>
