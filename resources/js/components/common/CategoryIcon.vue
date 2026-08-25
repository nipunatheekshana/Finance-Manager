<script setup lang="ts">
import { computed } from 'vue'
import {
  Banknote, Car, CircleEllipsis, Cigarette, Clapperboard, CreditCard, Dumbbell,
  HeartPulse, Landmark, PiggyBank, Receipt, Repeat, Shield, ShoppingBag, Split,
  User, Users, Utensils, Wallet, Circle,
} from 'lucide-vue-next'

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
const ICONS: Record<string, unknown> = {
  utensils: Utensils, car: Car, 'shopping-bag': ShoppingBag, clapperboard: Clapperboard,
  receipt: Receipt, cigarette: Cigarette, user: User, dumbbell: Dumbbell,
  'heart-pulse': HeartPulse, repeat: Repeat, users: Users, 'circle-ellipsis': CircleEllipsis,
  banknote: Banknote, landmark: Landmark, 'credit-card': CreditCard, split: Split,
  wallet: Wallet, 'piggy-bank': PiggyBank, shield: Shield, circle: Circle,
}

const component = computed(() => ICONS[props.icon] ?? Circle)

const TINTS: Record<string, string> = {
  amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
  sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400',
  violet: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400',
  pink: 'bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-400',
  slate: 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300',
  stone: 'bg-stone-100 text-stone-700 dark:bg-stone-500/15 dark:text-stone-300',
  teal: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-400',
  lime: 'bg-lime-100 text-lime-700 dark:bg-lime-500/15 dark:text-lime-400',
  rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
  indigo: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-400',
  orange: 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
  zinc: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/15 dark:text-zinc-300',
}

const tint = computed(() => TINTS[props.color] ?? TINTS.slate)

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
