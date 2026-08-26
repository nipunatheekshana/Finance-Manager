<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { CreditCard, Home, MoreHorizontal, Plus, Wallet } from 'lucide-vue-next'
import MoreSheet from './MoreSheet.vue'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const ui = useUiStore()

const moreOpen = ref(false)

/**
 * Four tabs is the most a phone can hold either side of the action button.
 * Everything else — including Settings and signing out — lives behind More,
 * which is why the last slot is a menu rather than a fifth destination.
 */
const items = [
  { name: 'dashboard', to: '/', label: 'Home', icon: Home },
  { name: 'budget', to: '/budget', label: 'Budget', icon: Wallet },
  { name: 'debts', to: '/debts', label: 'Debt', icon: CreditCard },
] as const

/** Pages that are only reachable through More, so the tab reads as active. */
const MORE_PATHS = [
  '/plan', '/expenses', '/income', '/savings', '/cycle',
  '/reports', '/cash-flow', '/calendar', '/settings',
]

function isActive(to: string): boolean {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}

function isMoreActive(): boolean {
  return MORE_PATHS.some((path) => route.path.startsWith(path))
}
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-raised/95 backdrop-blur-md lg:hidden"
    aria-label="Main"
  >
    <div class="mx-auto grid max-w-lg grid-cols-5 items-end pb-safe">
      <RouterLink
        v-for="item in items.slice(0, 2)"
        :key="item.name"
        :to="item.to"
        class="flex min-h-[3.5rem] flex-col items-center justify-center gap-0.5 px-1 pt-2 text-[0.6875rem] font-medium transition"
        :class="isActive(item.to) ? 'text-brand' : 'text-ink-subtle'"
        :aria-current="isActive(item.to) ? 'page' : undefined"
      >
        <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
        {{ item.label }}
      </RouterLink>

      <!-- The centre action: log an expense in one tap. -->
      <div class="flex justify-center">
        <button
          type="button"
          class="-mt-5 flex h-14 w-14 items-center justify-center rounded-full bg-brand text-on-brand shadow-[var(--shadow-fab)] transition active:scale-95"
          aria-label="Add expense"
          @click="ui.openExpenseSheet()"
        >
          <Plus class="h-7 w-7" aria-hidden="true" />
        </button>
      </div>

      <RouterLink
        v-for="item in items.slice(2)"
        :key="item.name"
        :to="item.to"
        class="flex min-h-[3.5rem] flex-col items-center justify-center gap-0.5 px-1 pt-2 text-[0.6875rem] font-medium transition"
        :class="isActive(item.to) ? 'text-brand' : 'text-ink-subtle'"
        :aria-current="isActive(item.to) ? 'page' : undefined"
      >
        <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
        {{ item.label }}
      </RouterLink>

      <button
        type="button"
        class="flex min-h-[3.5rem] flex-col items-center justify-center gap-0.5 px-1 pt-2 text-[0.6875rem] font-medium transition"
        :class="moreOpen || isMoreActive() ? 'text-brand' : 'text-ink-subtle'"
        aria-haspopup="dialog"
        :aria-expanded="moreOpen"
        @click="moreOpen = true"
      >
        <MoreHorizontal class="h-5 w-5" aria-hidden="true" />
        More
      </button>
    </div>
  </nav>

  <MoreSheet :open="moreOpen" @close="moreOpen = false" />
</template>
