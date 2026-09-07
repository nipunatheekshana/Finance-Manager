<script setup lang="ts">
import { computed } from 'vue'
import { Circle } from 'lucide-vue-next'
import { CATEGORY_COLORS, CATEGORY_ICONS } from '@/data/categoryOptions'

const props = withDefaults(
  defineProps<{
    icon: string
    color?: string
    size?: 'sm' | 'md' | 'lg'
    /** Draw the icon inside a tinted round chip. */
    chip?: boolean
  }>(),
  { color: 'slate', size: 'md', chip: true },
)

/** Icon names come from the database, so unknown values fall back safely. */
const component = computed(() => CATEGORY_ICONS[props.icon] ?? Circle)

const tint = computed(() => CATEGORY_COLORS[props.color] ?? CATEGORY_COLORS.slate)

const chipSize = computed(() => ({ sm: 'h-8 w-8', md: 'h-10 w-10', lg: 'h-12 w-12' })[props.size])
const iconSize = computed(() => ({ sm: 'h-4 w-4', md: 'h-5 w-5', lg: 'h-6 w-6' })[props.size])
</script>

<template>
  <span
    v-if="chip"
    class="inline-flex shrink-0 items-center justify-center rounded-full"
    :class="[chipSize, tint]"
    aria-hidden="true"
  >
    <component :is="component" :class="iconSize" />
  </span>
  <component :is="component" v-else :class="iconSize" aria-hidden="true" />
</template>
