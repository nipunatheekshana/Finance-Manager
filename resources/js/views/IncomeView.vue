<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { AlertTriangle, Banknote, Check, Clock, Plus, TrendingUp, Wallet } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import DatePicker from '@/components/common/DatePicker.vue'
import SegmentedControl from '@/components/common/SegmentedControl.vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import { api, ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { amountToNumber } from '@/composables/useCurrency'
import { formatDate, relativeDay, todayIso } from '@/composables/useDates'
import type {
  Funding,
  HoldingPot,
  IncomeSource,
  IncomeStatus,
  IncomeSummary,
  IncomeTransaction,
} from '@/types'

const auth = useAuthStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const items = ref<IncomeTransaction[]>([])
const sources = ref<IncomeSource[]>([])
const summary = ref<IncomeSummary | null>(null)
const funding = ref<Funding | null>(null)
const pot = ref<HoldingPot | null>(null)
const cycle = ref<{ start: string; end: string } | null>(null)

const sheetOpen = ref(false)
const errors = ref<Record<string, string>>({})

const form = ref({
  amount: '',
  status: 'received' as IncomeStatus,
  income_source_id: null as number | null,
  received_date: todayIso(),
  due_date: '',
  description: '',
  reference: '',
})

const STATUS_OPTIONS = [
  { value: 'received', label: 'Received' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'expected', label: 'Expected' },
]

const profile = computed(() => auth.profile)

const outstanding = computed(() => items.value.filter((row) => !row.is_received))
const received = computed(() => items.value.filter((row) => row.is_received))

/** How far this cycle's income has got toward what the plan assumes. */
const coverage = computed(() => {
  if (!summary.value || !funding.value) return null

  const target = amountToNumber(funding.value.amount)
  if (target <= 0) return null

  return Math.min(100, (amountToNumber(summary.value.received) / target) * 100)
})

async function load(): Promise<void> {
  const response = await api.get<{
    data: IncomeTransaction[]
    meta: {
      cycle: { start: string; end: string }
      summary: IncomeSummary
      funding: Funding
      holding_pot: HoldingPot | null
      sources: IncomeSource[]
    }
  }>('/income')

  items.value = response.data
  cycle.value = response.meta.cycle
  summary.value = response.meta.summary
  funding.value = response.meta.funding
  pot.value = response.meta.holding_pot
  sources.value = response.meta.sources
}

function openSheet(): void {
  errors.value = {}
  form.value = {
    amount: '',
    status: 'received',
    income_source_id: sources.value.find((s) => !s.archived)?.id ?? null,
    received_date: todayIso(),
    due_date: '',
    description: '',
    reference: '',
  }
  sheetOpen.value = true
}

async function submit(): Promise<void> {
  const value = Number.parseFloat(form.value.amount)
  if (!Number.isFinite(value) || value <= 0) {
    errors.value = { amount: 'Enter an amount greater than zero.' }
    return
  }

  saving.value = true
  errors.value = {}

  try {
    await api.post('/income', {
      amount: value.toFixed(2),
      status: form.value.status,
      income_source_id: form.value.income_source_id,
      received_date: form.value.status === 'received' ? form.value.received_date : null,
      due_date: form.value.due_date || null,
      description: form.value.description.trim() || null,
      reference: form.value.reference.trim() || null,
    })

    ui.success(form.value.status === 'received' ? 'Income recorded' : 'Invoice logged')
    sheetOpen.value = false

    await Promise.all([load(), dashboard.refresh()])
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    } else if (error instanceof ApiError) {
      ui.error('Could not record that income', error.message)
    }
  } finally {
    saving.value = false
  }
}

async function markReceived(row: IncomeTransaction): Promise<void> {
  try {
    await api.post(`/income/${row.id}/received`, { received_date: todayIso() })
    ui.success('Marked as received')
    await Promise.all([load(), dashboard.refresh()])
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not update that entry', error.message)
  }
}

onMounted(async () => {
  await Promise.all([load(), auth.refreshProfile()])
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader title="Income" subtitle="What has come in, and what is still owed to you.">
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="openSheet">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span class="sr-only">Record income</span>
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" :rows="3" />

    <div v-else class="space-y-5">
      <!-- Runway: the number that matters most when income is lumpy. -->
      <section v-if="pot" class="card overflow-hidden">
        <div class="bg-brand px-5 py-6 text-on-brand">
          <p class="text-xs font-bold uppercase tracking-[0.08em] opacity-80">Money banked</p>
          <MoneyText :amount="pot.balance" size="3xl" class="mt-1 block font-bold" />

          <p v-if="pot.months !== null" class="mt-1.5 text-sm opacity-90">
            About {{ pot.months }} {{ pot.months === 1 ? 'month' : 'months' }} of runway at
            <MoneyText :amount="pot.draw" size="sm" class="font-semibold" /> a month
          </p>
        </div>

        <div
          v-if="pot.is_low || pot.is_negative"
          class="flex items-start gap-2.5 bg-warn-soft px-5 py-3 text-sm text-warn"
          role="status"
        >
          <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span v-if="pot.is_negative">
            You have drawn more than you have earned. The pot is empty.
          </span>
          <span v-else>
            Less than a month of runway left. Consider lowering your draw or chasing invoices.
          </span>
        </div>

        <dl class="grid grid-cols-2 divide-x divide-line">
          <div class="px-5 py-3.5">
            <dt class="text-xs font-semibold text-ink-subtle">Earned in total</dt>
            <dd class="mt-0.5"><MoneyText :amount="pot.received" size="base" class="font-semibold" /></dd>
          </div>
          <div class="px-5 py-3.5">
            <dt class="text-xs font-semibold text-ink-subtle">Paid to yourself</dt>
            <dd class="mt-0.5"><MoneyText :amount="pot.drawn" size="base" class="font-semibold" /></dd>
          </div>
        </dl>
      </section>

      <!-- This cycle -->
      <section v-if="summary && funding" class="card p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="eyebrow">This cycle</h2>
            <p v-if="cycle" class="mt-0.5 text-xs text-ink-subtle">
              {{ formatDate(cycle.start) }} – {{ formatDate(cycle.end) }}
            </p>
          </div>
          <span class="badge bg-sunken text-ink-muted">{{ funding.method_label }}</span>
        </div>

        <!-- Stacked so a long figure never wraps around its own caption. -->
        <div class="mt-2">
          <MoneyText :amount="summary.received" size="2xl" class="block font-bold" />
          <p class="text-sm text-ink-subtle">
            received of <MoneyText :amount="funding.amount" size="sm" class="font-medium" /> planned
          </p>
        </div>

        <BudgetProgress
          v-if="coverage !== null"
          class="mt-3"
          :percentage="coverage"
          :status="coverage >= 100 ? 'safe' : coverage >= 60 ? 'warning' : 'over'"
          :label="`${coverage.toFixed(0)}% of this cycle's planned income received`"
        />

        <p v-if="funding.explanation" class="mt-2.5 flex items-start gap-1.5 text-xs text-ink-subtle">
          <TrendingUp class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {{ funding.explanation }}
        </p>
      </section>

      <!-- Outstanding invoices -->
      <section v-if="outstanding.length">
        <h2 class="mb-3 text-base font-semibold text-ink">
          Owed to you
          <MoneyText
            v-if="summary"
            :amount="summary.outstanding"
            size="sm"
            class="ml-1 font-semibold text-ink-muted"
          />
        </h2>

        <ul class="card divide-y divide-line px-4">
          <li v-for="row in outstanding" :key="row.id" class="flex items-center gap-3 py-3">
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
              :class="row.is_overdue ? 'bg-over-soft' : 'bg-sunken'"
            >
              <Clock class="h-4 w-4" :class="row.is_overdue ? 'text-over' : 'text-ink-muted'" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">
                {{ row.source?.name ?? row.description ?? 'Income' }}
              </p>
              <p class="truncate text-xs" :class="row.is_overdue ? 'text-over' : 'text-ink-subtle'">
                <template v-if="row.due_date">
                  {{ row.is_overdue ? 'Overdue since' : 'Due' }} {{ formatDate(row.due_date) }}
                </template>
                <template v-else>{{ row.status === 'invoiced' ? 'Invoiced' : 'Expected' }}</template>
                <template v-if="row.reference"> · {{ row.reference }}</template>
              </p>
            </div>

            <div class="shrink-0 text-right">
              <MoneyText :amount="row.amount" size="sm" class="font-semibold" />
              <button
                type="button"
                class="block text-xs font-semibold text-brand"
                @click="markReceived(row)"
              >
                Mark paid
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Received -->
      <section>
        <h2 class="mb-3 text-base font-semibold text-ink">Received</h2>

        <ul v-if="received.length" class="card divide-y divide-line px-4">
          <li v-for="row in received" :key="row.id" class="flex items-center gap-3 py-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-safe-soft">
              <Check class="h-4 w-4 text-safe" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">
                {{ row.source?.name ?? row.description ?? 'Income' }}
              </p>
              <p class="truncate text-xs text-ink-subtle">
                {{ relativeDay(row.received_date) }}
                <template v-if="row.description && row.source"> · {{ row.description }}</template>
              </p>
            </div>

            <MoneyText :amount="row.amount" size="sm" class="shrink-0 font-semibold" colored signed />
          </li>
        </ul>

        <EmptyState
          v-else
          :icon="Banknote"
          title="No income recorded yet"
          :description="
            profile?.has_irregular_income
              ? 'Record what you earn so your runway and forecasts mean something.'
              : 'Your salary is recorded when you set up each monthly plan.'
          "
          action-label="Record income"
          @action="openSheet"
        />
      </section>
    </div>

    <!-- Record income -->
    <BottomSheet :open="sheetOpen" title="Record income" :busy="saving" @close="sheetOpen = false">
      <div class="space-y-4 pb-2">
        <MoneyInput v-model="form.amount" large :error="errors.amount" data-autofocus />

        <SegmentedControl
          v-model="form.status"
          :options="STATUS_OPTIONS"
          aria-label="Has this money arrived?"
        />

        <p class="-mt-2 text-xs text-ink-subtle">
          Only received income counts toward what you can spend.
        </p>

        <SelectField
          v-if="sources.length"
          v-model="form.income_source_id"
          :options="sources.filter((s) => !s.archived).map((s) => ({ value: s.id, label: s.name }))"
          label="Source"
          placeholder="Not set"
          :error="errors.income_source_id"
        />

        <DatePicker
          v-if="form.status === 'received'"
          v-model="form.received_date"
          label="When did it arrive?"
          quick-picks
          :error="errors.received_date"
        />

        <TextField
          v-else
          v-model="form.due_date"
          label="Due date"
          type="date"
          :error="errors.due_date"
        />

        <TextField
          v-model="form.description"
          label="What was it for?"
          placeholder="Optional"
          :error="errors.description"
        />

        <TextField
          v-model="form.reference"
          label="Invoice number"
          placeholder="Optional"
          :error="errors.reference"
        />
      </div>

      <template #footer>
        <button type="button" class="btn btn-primary w-full !text-base" :disabled="saving" @click="submit">
          <Wallet class="h-4 w-4" aria-hidden="true" />
          {{ saving ? 'Saving…' : 'Record income' }}
        </button>
      </template>
    </BottomSheet>
  </div>
</template>
