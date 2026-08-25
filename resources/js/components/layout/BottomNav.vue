<script setup lang="ts">
import { useRoute } from 'vue-router'
import { CreditCard, Home, PiggyBank, Plus, Wallet } from 'lucide-vue-next'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const ui = useUiStore()

const items = [
  { name: 'dashboard', to: '/', label: 'Home', icon: Home },
  { name: 'budget', to: '/budget', label: 'Budget', icon: Wallet },
  { name: 'debts', to: '/debts', label: 'Debt', icon: CreditCard },
  { name: 'savings', to: '/savings', label: 'Goals', icon: PiggyBank },
] as const

function isActive(to: string): boolean {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
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
    </div>
  </nav>
</template>
