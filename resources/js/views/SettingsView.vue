<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  Bell, ChevronRight, CreditCard, LogOut, Moon, Palette, PiggyBank,
  Repeat, Shapes, Shield, Sun, User, Wallet,
} from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SegmentedControl from '@/components/common/SegmentedControl.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { ThemePreference } from '@/types'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

const baseSalary = ref('')
const salaryDay = ref('')
const defaultBuffer = ref('')
const extraDebt = ref('')
const extraSavings = ref('')
const extraSpending = ref('')

const saving = ref(false)
const errors = ref<Record<string, string>>({})

const THEME_OPTIONS = [
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
  { value: 'system', label: 'System' },
]

const LINKS = [
  { to: '/settings/categories', label: 'Categories', description: 'Names, icons and monthly limits', icon: Shapes },
  { to: '/settings/payment-methods', label: 'Payment methods', description: 'Cash, cards and card links', icon: CreditCard },
  { to: '/settings/recurring', label: 'Recurring expenses', description: 'The bills pulled into each plan', icon: Repeat },
  { to: '/debts', label: 'Debts', description: 'Balances, payments and payoff settings', icon: Wallet },
  { to: '/savings', label: 'Savings goals', description: 'Targets and monthly contributions', icon: PiggyBank },
  { to: '/settings/notifications', label: 'Notifications', description: 'Which reminders you want', icon: Bell },
  { to: '/settings/security', label: 'Security', description: 'Email and password', icon: Shield },
]

const extraTotal = computed(
  () => Number(extraDebt.value || 0) + Number(extraSavings.value || 0) + Number(extraSpending.value || 0),
)

function hydrate(): void {
  const profile = auth.profile
  if (!profile) return

  baseSalary.value = String(Number.parseFloat(profile.base_salary))
  salaryDay.value = String(profile.salary_day)
  defaultBuffer.value = String(Number.parseFloat(profile.default_buffer))
  extraDebt.value = String(profile.extra_debt_percentage)
  extraSavings.value = String(profile.extra_savings_percentage)
  extraSpending.value = String(profile.extra_spending_percentage)
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}

  try {
    await auth.updateProfile({
      base_salary: Number.parseFloat(baseSalary.value || '0').toFixed(2),
      salary_day: Number(salaryDay.value),
      default_buffer: Number.parseFloat(defaultBuffer.value || '0').toFixed(2),
      extra_debt_percentage: Number(extraDebt.value),
      extra_savings_percentage: Number(extraSavings.value),
      extra_spending_percentage: Number(extraSpending.value),
    } as never)

    ui.success('Settings saved')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    } else if (error instanceof ApiError) {
      ui.error('Could not save your settings', error.message)
    }
  } finally {
    saving.value = false
  }
}

async function signOut(): Promise<void> {
  await auth.logout()
  void router.push({ name: 'login' })
}

onMounted(async () => {
  await auth.refreshProfile()
  hydrate()
})
</script>

<template>
  <div>
    <PageHeader title="Settings" />

    <div class="space-y-6">
      <!-- Account -->
      <section class="card p-4">
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-soft">
            <User class="h-5 w-5 text-brand" aria-hidden="true" />
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink">{{ auth.user?.name }}</p>
            <p class="truncate text-xs text-ink-subtle">{{ auth.user?.email }}</p>
          </div>
        </div>
      </section>

      <!-- Salary -->
      <section class="card space-y-4 p-4">
        <h2 class="text-base font-semibold text-ink">Salary</h2>

        <MoneyInput v-model="baseSalary" label="Base monthly salary" :error="errors.base_salary" />

        <TextField
          v-model="salaryDay"
          label="Salary day"
          type="number"
          inputmode="numeric"
          min="1"
          max="31"
          hint="Your cycle runs from this day to the day before the next one."
          :error="errors.salary_day"
        />

        <MoneyInput
          v-model="defaultBuffer"
          label="Default buffer"
          hint="Pre-filled on each new plan. Held out of your spending budget."
          :error="errors.default_buffer"
        />
      </section>

      <!-- Extra income split -->
      <section class="card space-y-4 p-4">
        <div>
          <h2 class="text-base font-semibold text-ink">Extra income</h2>
          <p class="mt-0.5 text-sm text-ink-muted">
            How a bonus or higher-than-usual salary gets split. Must add up to 100%.
          </p>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <TextField v-model="extraDebt" label="Debt %" type="number" inputmode="numeric" min="0" max="100" />
          <TextField v-model="extraSavings" label="Savings %" type="number" inputmode="numeric" min="0" max="100" />
          <TextField v-model="extraSpending" label="Spending %" type="number" inputmode="numeric" min="0" max="100" />
        </div>

        <p
          class="text-sm font-medium"
          :class="extraTotal === 100 ? 'text-safe' : 'text-over'"
        >
          Total: {{ extraTotal }}%
          <span v-if="extraTotal !== 100"> — this must be exactly 100%.</span>
        </p>

        <p v-if="errors.extra_debt_percentage" class="text-sm text-over">
          {{ errors.extra_debt_percentage }}
        </p>
      </section>

      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="saving || extraTotal !== 100"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Save changes' }}
      </button>

      <!-- Theme -->
      <section class="card space-y-3 p-4">
        <div class="flex items-center gap-2">
          <Palette class="h-4 w-4 text-ink-subtle" aria-hidden="true" />
          <h2 class="text-base font-semibold text-ink">Appearance</h2>
        </div>

        <SegmentedControl
          :model-value="ui.theme"
          :options="THEME_OPTIONS"
          aria-label="Theme"
          @update:model-value="(value) => ui.setTheme(value as ThemePreference)"
        />

        <p class="flex items-center gap-1.5 text-xs text-ink-subtle">
          <component :is="ui.prefersDark ? Moon : Sun" class="h-3.5 w-3.5" aria-hidden="true" />
          Currently showing the {{ ui.prefersDark ? 'dark' : 'light' }} theme.
        </p>
      </section>

      <!-- Everything else -->
      <section>
        <ul class="card divide-y divide-line">
          <li v-for="link in LINKS" :key="link.to">
            <RouterLink :to="link.to" class="flex min-h-14 items-center gap-3 px-4 py-3 transition hover:bg-sunken">
              <component :is="link.icon" class="h-5 w-5 shrink-0 text-ink-subtle" aria-hidden="true" />
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-ink">{{ link.label }}</span>
                <span class="block truncate text-xs text-ink-subtle">{{ link.description }}</span>
              </span>
              <ChevronRight class="h-4 w-4 shrink-0 text-ink-subtle" aria-hidden="true" />
            </RouterLink>
          </li>
        </ul>
      </section>

      <button type="button" class="btn btn-secondary w-full text-over" @click="signOut">
        <LogOut class="h-4 w-4" aria-hidden="true" />
        Sign out
      </button>

      <p class="pb-4 text-center text-xs text-ink-subtle">
        All amounts are in Sri Lankan Rupees.
      </p>
    </div>
  </div>
</template>
