<script setup lang="ts">
import { useRoute } from 'vue-router'
import {
  Banknote, BarChart3, CalendarDays, CreditCard, Download, Gauge, Home,
  ListChecks, LogOut, PiggyBank, Receipt, Settings, TrendingUp, UserRound, Wallet,
} from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useInstallPrompt } from '@/composables/useInstallPrompt'
import { useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const { canInstall } = useInstallPrompt()

const items = [
  { to: '/', label: 'Dashboard', icon: Home, exact: true },
  { to: '/budget', label: 'Budget', icon: Wallet },
  { to: '/plan', label: 'Monthly plan', icon: ListChecks },
  { to: '/expenses', label: 'Expenses', icon: Receipt },
  { to: '/income', label: 'Income', icon: Banknote },
  { to: '/debts', label: 'Debts', icon: CreditCard },
  { to: '/savings', label: 'Savings', icon: PiggyBank },
  { to: '/cycle', label: 'Cycle progress', icon: Gauge },
  { to: '/reports', label: 'Reports', icon: BarChart3 },
  { to: '/cash-flow', label: 'Cash flow', icon: TrendingUp },
  { to: '/calendar', label: 'Calendar', icon: CalendarDays },
  { to: '/profile', label: 'Profile', icon: UserRound },
  { to: '/settings', label: 'Settings', icon: Settings },
]

function isActive(to: string, exact = false): boolean {
  return exact ? route.path === to : route.path.startsWith(to)
}

async function signOut(): Promise<void> {
  await auth.logout()
  void router.push({ name: 'login' })
}
</script>

<template>
  <aside
    class="fixed inset-y-0 left-0 hidden w-64 shrink-0 flex-col border-r border-line bg-raised lg:flex"
    aria-label="Main"
  >
    <div class="flex items-center gap-2.5 px-5 py-5">
      <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-on-brand">
        <Wallet class="h-5 w-5" aria-hidden="true" />
      </span>
      <span class="text-base font-bold text-ink">Finance Manager</span>
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
      <RouterLink
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        class="flex min-h-11 items-center gap-3 rounded-[var(--radius-field)] px-3 py-2.5 text-sm font-medium transition"
        :class="
          isActive(item.to, item.exact)
            ? 'bg-brand-soft text-ink'
            : 'text-ink-muted hover:bg-sunken hover:text-ink'
        "
        :aria-current="isActive(item.to, item.exact) ? 'page' : undefined"
      >
        <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
        {{ item.label }}
      </RouterLink>
    </nav>

    <div class="border-t border-line p-3">
      <button
        v-if="canInstall"
        type="button"
        class="mb-1 flex min-h-11 w-full items-center gap-3 rounded-[var(--radius-field)] px-3 py-2.5 text-sm font-medium text-ink-muted transition hover:bg-sunken hover:text-ink"
        @click="ui.installSheetOpen = true"
      >
        <Download class="h-5 w-5 shrink-0" aria-hidden="true" />
        Install app
      </button>

      <div class="px-3 py-2">
        <p class="truncate text-sm font-semibold text-ink">{{ auth.user?.name }}</p>
        <p class="truncate text-xs text-ink-subtle">{{ auth.user?.email }}</p>
      </div>
      <button
        type="button"
        class="flex min-h-11 w-full items-center gap-3 rounded-[var(--radius-field)] px-3 py-2.5 text-sm font-medium text-ink-muted transition hover:bg-sunken hover:text-ink"
        @click="signOut"
      >
        <LogOut class="h-5 w-5 shrink-0" aria-hidden="true" />
        Sign out
      </button>
    </div>
  </aside>
</template>
