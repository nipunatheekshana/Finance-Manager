<script setup lang="ts">
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Banknote, BarChart3, CalendarDays, ChevronRight, CreditCard, Download, Gauge,
  Home, ListChecks, LogOut, PiggyBank, Receipt, Settings, TrendingUp, Wallet,
} from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useInstallPrompt } from '@/composables/useInstallPrompt'
import type { Component } from 'vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const { canInstall } = useInstallPrompt()

/**
 * Every destination, including the four in the bottom bar: on a phone this
 * sheet is the only complete map of the app, so leaving pages out of it is
 * how they become unreachable.
 */
interface NavItem {
  to: string
  label: string
  icon: Component
  /** Match the path exactly, so Dashboard does not light up everywhere. */
  exact?: boolean
}

const GROUPS: { label: string; items: NavItem[] }[] = [
  {
    label: 'Overview',
    items: [
      { to: '/', label: 'Dashboard', icon: Home, exact: true },
      { to: '/budget', label: 'Budget', icon: Wallet },
      { to: '/plan', label: 'Monthly plan', icon: ListChecks },
    ],
  },
  {
    label: 'Money',
    items: [
      { to: '/expenses', label: 'Expenses', icon: Receipt },
      { to: '/income', label: 'Income', icon: Banknote },
      { to: '/debts', label: 'Debts', icon: CreditCard },
      { to: '/savings', label: 'Savings', icon: PiggyBank },
    ],
  },
  {
    label: 'Insight',
    items: [
      { to: '/cycle', label: 'Cycle progress', icon: Gauge },
      { to: '/reports', label: 'Reports', icon: BarChart3 },
      { to: '/cash-flow', label: 'Cash flow', icon: TrendingUp },
      { to: '/calendar', label: 'Calendar', icon: CalendarDays },
    ],
  },
  {
    label: 'Account',
    items: [{ to: '/settings', label: 'Settings', icon: Settings }],
  },
]

function isActive(to: string, exact = false): boolean {
  return exact ? route.path === to : route.path.startsWith(to)
}

// Tapping a destination should leave the menu behind, not stack it over the
// page that just loaded.
watch(() => route.fullPath, () => emit('close'))

function openInstall(): void {
  emit('close')
  ui.installSheetOpen = true
}

async function signOut(): Promise<void> {
  emit('close')
  await auth.logout()
  void router.push({ name: 'login' })
}
</script>

<template>
  <BottomSheet :open="props.open" title="All pages" @close="emit('close')">
    <div class="space-y-5 pb-2">
      <section v-for="group in GROUPS" :key="group.label">
        <p class="eyebrow">{{ group.label }}</p>

        <ul class="mt-1.5 space-y-1">
          <li v-for="item in group.items" :key="item.to">
            <RouterLink
              :to="item.to"
              class="flex min-h-12 items-center gap-3 rounded-[var(--radius-field)] px-3 py-2.5 text-sm font-medium transition"
              :class="
                isActive(item.to, item.exact)
                  ? 'bg-brand-soft text-ink'
                  : 'text-ink-muted hover:bg-sunken hover:text-ink'
              "
              :aria-current="isActive(item.to, item.exact) ? 'page' : undefined"
            >
              <component
                :is="item.icon"
                class="h-5 w-5 shrink-0"
                :class="isActive(item.to, item.exact) ? 'text-brand' : 'text-ink-subtle'"
                aria-hidden="true"
              />
              <span class="flex-1">{{ item.label }}</span>
              <ChevronRight class="h-4 w-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            </RouterLink>
          </li>
        </ul>
      </section>

      <!-- Nothing else in the app mentions that it can be installed. -->
      <section v-if="canInstall" class="border-t border-line pt-4">
        <button
          type="button"
          class="flex min-h-12 w-full items-center gap-3 rounded-[var(--radius-field)] bg-brand-soft px-3 py-2.5 text-sm font-semibold text-ink transition"
          @click="openInstall"
        >
          <Download class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
          <span class="flex-1 text-left">Add to home screen</span>
          <ChevronRight class="h-4 w-4 shrink-0 text-ink-subtle" aria-hidden="true" />
        </button>
      </section>

      <!-- Signing out lived only on the Settings screen, which a phone could
           not reach. -->
      <section class="border-t border-line pt-4">
        <div class="px-3">
          <p class="truncate text-sm font-semibold text-ink">{{ auth.user?.name }}</p>
          <p class="truncate text-xs text-ink-subtle">{{ auth.user?.email }}</p>
        </div>

        <button
          type="button"
          class="mt-2 flex min-h-12 w-full items-center gap-3 rounded-[var(--radius-field)] px-3 py-2.5 text-sm font-medium text-over transition hover:bg-over-soft"
          @click="signOut"
        >
          <LogOut class="h-5 w-5 shrink-0" aria-hidden="true" />
          Sign out
        </button>
      </section>
    </div>
  </BottomSheet>
</template>
