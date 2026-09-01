<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  Activity, CalendarRange, Check, CreditCard, History, PiggyBank, Receipt, X,
} from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import SectionHeader from '@/components/common/SectionHeader.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import SegmentedControl from '@/components/common/SegmentedControl.vue'
import TextField from '@/components/common/TextField.vue'
import AvatarPicker from '@/components/profile/AvatarPicker.vue'
import { api, ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatDateRange } from '@/composables/useDates'
import type { ActivityPage, ProfileOverview, User } from '@/types'

const auth = useAuthStore()
const ui = useUiStore()

const user = ref<User | null>(null)
const overview = ref<ProfileOverview | null>(null)
const activity = ref<ActivityPage | null>(null)
const loading = ref(true)
const tab = ref<'months' | 'debts' | 'savings' | 'activity'>('months')

const handle = ref('')
const editingHandle = ref(false)
const handleError = ref('')
const savingHandle = ref(false)

const TABS = [
  { value: 'months', label: 'Months' },
  { value: 'debts', label: 'Debt' },
  { value: 'savings', label: 'Savings' },
  { value: 'activity', label: 'Activity' },
]

const lifetime = computed(() => overview.value?.lifetime ?? null)

onMounted(async () => {
  try {
    const response = await api.get<{ user: User; data: ProfileOverview }>('/me')
    user.value = response.user
    overview.value = response.data
    handle.value = response.user.handle ?? ''
  } finally {
    loading.value = false
  }
})

async function loadActivity(): Promise<void> {
  if (activity.value !== null) return

  try {
    const response = await api.get<{ data: ActivityPage }>('/me/activity')
    activity.value = response.data
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not load your activity', error.message)
  }
}

function openTab(value: string): void {
  tab.value = value as typeof tab.value
  if (value === 'activity') void loadActivity()
}

async function saveHandle(): Promise<void> {
  handleError.value = ''
  savingHandle.value = true

  try {
    const response = await api.put<{ user: User }>('/me', { handle: handle.value })
    user.value = response.user
    auth.user = response.user
    editingHandle.value = false
    ui.success('Handle updated', `You are @${response.user.handle}.`)
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      handleError.value = error.errors.handle?.[0] ?? 'That handle cannot be used.'
    } else if (error instanceof ApiError) {
      ui.error('Could not save that handle', error.message)
    }
  } finally {
    savingHandle.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="Profile" />

    <LoadingState v-if="loading" :rows="4" />

    <div v-else-if="user && lifetime" class="space-y-5">
      <!-- Who this account is -->
      <section class="card space-y-4 p-4">
        <AvatarPicker :user="user" @updated="(updated) => (user = updated)" />

        <div v-if="editingHandle" class="space-y-3 border-t border-line pt-4">
          <TextField
            v-model="handle"
            label="Handle"
            hint="Letters, numbers, dots and underscores. This is how the app refers to you."
            :error="handleError"
            data-autofocus
          />
          <div class="flex gap-2">
            <button
              type="button"
              class="btn btn-primary flex-1 !min-h-10 !text-sm"
              :disabled="savingHandle || handle.trim().length < 3"
              @click="saveHandle"
            >
              <Check class="h-4 w-4" aria-hidden="true" />
              {{ savingHandle ? 'Saving…' : 'Save handle' }}
            </button>
            <button
              type="button"
              class="btn btn-secondary !min-h-10 !text-sm"
              @click="editingHandle = false; handle = user.handle ?? ''; handleError = ''"
            >
              <X class="h-4 w-4" aria-hidden="true" />
              Cancel
            </button>
          </div>
        </div>

        <button
          v-else
          type="button"
          class="btn btn-secondary w-full !min-h-10 !text-sm"
          @click="editingHandle = true"
        >
          Change handle
        </button>

        <p v-if="user.member_since" class="text-xs text-ink-subtle">
          Keeping track since {{ formatDate(lifetime.tracking_since ?? user.member_since, true) }}.
        </p>
      </section>

      <!-- What the account has actually achieved -->
      <section>
        <SectionHeader title="All time" />
        <div class="grid grid-cols-2 gap-3">
          <div class="card p-4">
            <p class="flex items-center gap-1.5 text-xs text-ink-subtle">
              <CreditCard class="h-3.5 w-3.5" aria-hidden="true" /> Debt paid off
            </p>
            <p class="mt-1"><MoneyText :amount="lifetime.debt_paid" size="lg" class="font-bold" compact /></p>
            <p class="mt-0.5 text-xs text-ink-subtle">
              {{ lifetime.debts_cleared }} {{ lifetime.debts_cleared === 1 ? 'debt' : 'debts' }} cleared
            </p>
          </div>

          <div class="card p-4">
            <p class="flex items-center gap-1.5 text-xs text-ink-subtle">
              <PiggyBank class="h-3.5 w-3.5" aria-hidden="true" /> Saved
            </p>
            <p class="mt-1"><MoneyText :amount="lifetime.currently_saved" size="lg" class="font-bold" compact /></p>
            <p class="mt-0.5 text-xs text-ink-subtle">
              <MoneyText :amount="lifetime.saved_net" size="xs" compact /> put aside in total
            </p>
          </div>

          <div class="card p-4">
            <p class="flex items-center gap-1.5 text-xs text-ink-subtle">
              <CalendarRange class="h-3.5 w-3.5" aria-hidden="true" /> Cycles planned
            </p>
            <p class="tabular mt-1 text-lg font-bold text-ink">{{ lifetime.cycles_planned }}</p>
            <p class="mt-0.5 text-xs text-ink-subtle">{{ lifetime.cycles_completed }} finished</p>
          </div>

          <div class="card p-4">
            <p class="flex items-center gap-1.5 text-xs text-ink-subtle">
              <Receipt class="h-3.5 w-3.5" aria-hidden="true" /> Expenses logged
            </p>
            <p class="tabular mt-1 text-lg font-bold text-ink">{{ lifetime.expenses_logged }}</p>
            <p class="mt-0.5 text-xs text-ink-subtle">
              <MoneyText :amount="lifetime.total_spent" size="xs" compact /> in total
            </p>
          </div>
        </div>
      </section>

      <SegmentedControl :model-value="tab" :options="TABS" @update:model-value="openTab" />

      <!-- Month by month -->
      <section v-if="tab === 'months'">
        <p v-if="!overview?.months.length" class="card p-6 text-center text-sm text-ink-muted">
          No finished cycles yet. Your first month will appear here.
        </p>

        <ul v-else class="space-y-3">
          <li v-for="month in overview.months" :key="month.plan_id" class="card p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-ink">{{ month.label }}</p>
                <p class="mt-0.5 text-xs text-ink-subtle">
                  {{ formatDateRange(month.cycle_start, month.cycle_end) }}
                </p>
              </div>
              <StatusBadge :status="month.status_label" />
            </div>

            <div class="mt-2 flex items-baseline gap-1.5">
              <MoneyText :amount="month.spent" size="lg" class="font-bold" />
              <span class="text-sm text-ink-subtle">
                of <MoneyText :amount="month.spending_budget" size="sm" />
              </span>
            </div>

            <BudgetProgress
              class="mt-2.5"
              :percentage="month.percentage_used"
              :status="month.status_label"
              :label="`${month.label}: ${month.percentage_used.toFixed(0)}% of the spending budget used`"
            />

            <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-center">
              <div>
                <dt class="text-xs text-ink-subtle">Income</dt>
                <dd class="mt-0.5"><MoneyText :amount="month.income" size="xs" class="font-semibold" compact /></dd>
              </div>
              <div>
                <dt class="text-xs text-ink-subtle">Debt paid</dt>
                <dd class="mt-0.5"><MoneyText :amount="month.debt_paid" size="xs" class="font-semibold" compact /></dd>
              </div>
              <div>
                <dt class="text-xs text-ink-subtle">Saved</dt>
                <dd class="mt-0.5"><MoneyText :amount="month.saved" size="xs" class="font-semibold" compact /></dd>
              </div>
            </dl>
          </li>
        </ul>
      </section>

      <!-- Every debt, and how it has come down -->
      <section v-else-if="tab === 'debts'" class="space-y-3">
        <p v-if="!overview?.debts.items.length" class="card p-6 text-center text-sm text-ink-muted">
          No debts recorded.
        </p>

        <div v-for="debt in overview?.debts.items ?? []" :key="debt.debt_id" class="card p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-ink">{{ debt.name }}</p>
              <p class="text-xs text-ink-subtle">
                {{ debt.type_label }} ·
                {{ debt.payments_count }} {{ debt.payments_count === 1 ? 'payment' : 'payments' }}
              </p>
            </div>
            <span v-if="debt.status === 'paid_off'" class="badge bg-safe-soft text-safe">
              <Check class="h-3 w-3" aria-hidden="true" />
              Cleared
            </span>
          </div>

          <div class="mt-2 flex items-baseline gap-1.5">
            <MoneyText :amount="debt.paid_total" size="lg" class="font-bold" />
            <span class="text-sm text-ink-subtle">paid off</span>
          </div>

          <BudgetProgress
            class="mt-2.5"
            :percentage="debt.progress_percentage"
            status="safe"
            :label="`${debt.name}: ${debt.progress_percentage.toFixed(0)}% paid down`"
          />

          <p class="mt-2 text-xs text-ink-subtle">
            <MoneyText :amount="debt.current_balance" size="xs" class="font-semibold" /> still owed
            <template v-if="debt.cleared_on">
              · cleared {{ formatDate(debt.cleared_on, true) }}
            </template>
          </p>
        </div>

        <section v-if="overview?.debts.recent_payments.length">
          <SectionHeader title="Recent payments" />
          <ul class="card divide-y divide-line px-4">
            <li
              v-for="payment in overview.debts.recent_payments"
              :key="payment.id"
              class="flex items-center justify-between gap-3 py-3"
            >
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-ink">{{ payment.debt_name }}</p>
                <p class="text-xs text-ink-subtle">{{ formatDate(payment.payment_date, true) }}</p>
              </div>
              <MoneyText :amount="payment.amount" size="sm" class="shrink-0 font-semibold" />
            </li>
          </ul>
        </section>
      </section>

      <!-- Goals -->
      <section v-else-if="tab === 'savings'" class="space-y-3">
        <p v-if="!overview?.savings.goals.length" class="card p-6 text-center text-sm text-ink-muted">
          No savings goals yet.
        </p>

        <div v-for="goal in overview?.savings.goals ?? []" :key="goal.savings_goal_id" class="card p-4">
          <div class="flex items-start justify-between gap-3">
            <p class="truncate text-sm font-semibold text-ink">{{ goal.name }}</p>
            <span class="tabular shrink-0 text-xs text-ink-subtle">
              {{ goal.percentage.toFixed(0) }}%
            </span>
          </div>

          <div class="mt-2 flex items-baseline gap-1.5">
            <MoneyText :amount="goal.current_amount" size="lg" class="font-bold" />
            <span class="text-sm text-ink-subtle">
              of <MoneyText :amount="goal.target_amount" size="sm" />
            </span>
          </div>

          <BudgetProgress
            class="mt-2.5"
            :percentage="goal.percentage"
            status="safe"
            :label="`${goal.name}: ${goal.percentage.toFixed(0)}% of the target`"
          />
        </div>
      </section>

      <!-- Everything this account has done -->
      <section v-else>
        <p v-if="activity === null" class="card p-6 text-center text-sm text-ink-muted">
          Loading your activity…
        </p>

        <p v-else-if="!activity.items.length" class="card p-6 text-center text-sm text-ink-muted">
          Nothing recorded yet.
        </p>

        <ul v-else class="card divide-y divide-line px-4">
          <li v-for="entry in activity.items" :key="entry.id" class="flex items-start gap-3 py-3">
            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sunken">
              <Activity class="h-4 w-4 text-ink-subtle" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-ink">{{ entry.label }}</p>
              <p class="text-xs text-ink-subtle">
                {{ entry.subject }}
                <template v-if="entry.happened_at"> · {{ formatDate(entry.happened_at, true) }}</template>
              </p>
              <p v-if="entry.note" class="mt-0.5 text-xs text-ink-muted">{{ entry.note }}</p>
            </div>
          </li>
        </ul>

        <p v-if="activity && activity.total > activity.items.length" class="mt-3 text-center text-xs text-ink-subtle">
          <History class="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />
          Showing the {{ activity.items.length }} most recent of {{ activity.total }}.
        </p>
      </section>
    </div>
  </div>
</template>
