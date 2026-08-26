<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  AlertTriangle, ArrowLeft, ArrowRight, Ban, Check, CheckCircle2,
  Clock, Pencil, Plus, RotateCcw, Wallet,
} from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import TextField from '@/components/common/TextField.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import AllocationChart from '@/components/budgets/AllocationChart.vue'
import AllowanceList from '@/components/budgets/AllowanceList.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import { api } from '@/services/api'
import { useBudgetStore } from '@/stores/budget'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { formatDate, formatDateRange } from '@/composables/useDates'
import { amountToNumber } from '@/composables/useCurrency'

const budget = useBudgetStore()
const dashboard = useDashboardStore()
const ui = useUiStore()
const router = useRouter()

const step = ref(0)
const actualIncome = ref('')
const bufferInput = ref('')
const weekInputs = ref<Record<number, string>>({})
const addingBill = ref(false)
const newBill = ref({ name: '', amount: '', due_date: '' })

/** Amount reserved per category this cycle, keyed by category id. */
const allowanceInputs = ref<Record<number, string>>({})
const allowanceCategories = ref<
  Array<{ id: number; name: string; icon: string; color: string; is_allowance: boolean }>
>([])

const STEPS = [
  'Income',
  'Fixed expenses',
  'Allowances',
  'Debt',
  'Savings',
  'Spending',
  'Weekly budgets',
] as const

const plan = computed(() => budget.plan)
const summary = computed(() => budget.summary)

const fixedExpenses = computed(() => plan.value?.fixed_expense_items ?? [])
const debtAllocations = computed(() => plan.value?.debt_allocations ?? [])
const savingsAllocations = computed(() => plan.value?.savings_allocations ?? [])

const isFinalized = computed(() => plan.value?.status !== 'draft')

/** The weekly rows the user edits, seeded from the suggested even split. */
const weekRows = computed(() =>
  budget.suggestedWeeks.map((week) => {
    const existing = budget.weekSummaries.find((row) => row.week_number === week.week_number)
    return {
      ...week,
      current: existing?.budget ?? week.budget_amount,
    }
  }),
)

const weeklyTotal = computed(() =>
  weekRows.value.reduce((total, row) => {
    const value = Number.parseFloat(weekInputs.value[row.week_number] ?? row.current)
    return total + (Number.isFinite(value) ? value : 0)
  }, 0),
)

const spendingBudget = computed(() => amountToNumber(summary.value?.spending_budget ?? '0'))

/** Difference between what the weeks add up to and the spending budget. */
const weeklyDifference = computed(() => weeklyTotal.value - spendingBudget.value)

const canFinalize = computed(() => summary.value?.can_finalize ?? false)

function seedWeekInputs(): void {
  const seeded: Record<number, string> = {}
  for (const row of weekRows.value) {
    seeded[row.week_number] = String(Number.parseFloat(row.current))
  }
  weekInputs.value = seeded
}

watch(
  () => budget.suggestedWeeks,
  () => seedWeekInputs(),
  { deep: true },
)

watch(
  plan,
  (value) => {
    if (!value) return
    if (actualIncome.value === '') {
      actualIncome.value = String(Number.parseFloat(value.actual_income ?? value.expected_income))
    }
    if (bufferInput.value === '') {
      bufferInput.value = String(Number.parseFloat(value.buffer))
    }
  },
  { immediate: true },
)

const allowanceTotal = computed(() =>
  Object.values(allowanceInputs.value).reduce((total, value) => {
    const amount = Number.parseFloat(value)
    return total + (Number.isFinite(amount) ? amount : 0)
  }, 0),
)

async function loadAllowances(): Promise<void> {
  if (!plan.value) return

  const rows = await budget.loadAllowances()
  const response = await api.get<{
    available_categories: Array<{
      id: number
      name: string
      icon: string
      color: string
      is_allowance: boolean
    }>
  }>(`/monthly-plans/${plan.value.id}/allowances`)

  allowanceCategories.value = response.available_categories

  const seeded: Record<number, string> = {}
  for (const row of rows) {
    seeded[row.category_id] = String(Number.parseFloat(row.allocated))
  }
  allowanceInputs.value = seeded
}

async function saveAllowances(): Promise<void> {
  if (!guardEditable()) return

  await budget.saveAllowances(
    allowanceCategories.value.map((category) => ({
      category_id: category.id,
      amount: Number.parseFloat(allowanceInputs.value[category.id] ?? '0').toFixed(2),
    })),
  )

  await loadAllowances()
  ui.success('Allowances saved')
}

onMounted(async () => {
  await budget.loadCurrent()
  seedWeekInputs()
  await loadAllowances()
})

function guardEditable(): boolean {
  if (!isFinalized.value) return true
  ui.info('This plan is already active', 'Reopen it from the bottom of this page to make changes.')
  return false
}

async function saveIncome(): Promise<void> {
  if (!guardEditable()) return

  try {
    await budget.recordIncome(Number.parseFloat(actualIncome.value).toFixed(2))
    ui.success('Income recorded')
    step.value = 1
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not save that amount', error.message)
  }
}

async function setBillStatus(id: number, status: string): Promise<void> {
  if (!guardEditable()) return
  await budget.updateFixedExpense(id, { status })
}

async function setBillAmount(id: number, amount: string): Promise<void> {
  if (!guardEditable()) return
  const value = Number.parseFloat(amount)
  if (!Number.isFinite(value)) return
  await budget.updateFixedExpense(id, { amount: value.toFixed(2) })
}

async function addBill(): Promise<void> {
  const amount = Number.parseFloat(newBill.value.amount)
  if (newBill.value.name.trim() === '' || !Number.isFinite(amount) || amount <= 0) return

  await budget.addFixedExpense({
    name: newBill.value.name.trim(),
    amount: amount.toFixed(2),
    due_date: newBill.value.due_date || null,
  })

  newBill.value = { name: '', amount: '', due_date: '' }
  addingBill.value = false
}

async function saveDebtAllocation(debtId: number, amount: string): Promise<void> {
  if (!guardEditable()) return
  const value = Number.parseFloat(amount)
  if (!Number.isFinite(value)) return

  await budget.updateAllocations({ debts: [{ debt_id: debtId, planned_amount: value.toFixed(2) }] })
}

async function saveSavingsAllocation(goalId: number, amount: string): Promise<void> {
  if (!guardEditable()) return
  const value = Number.parseFloat(amount)
  if (!Number.isFinite(value)) return

  await budget.updateAllocations({
    savings: [{ savings_goal_id: goalId, planned_amount: value.toFixed(2) }],
  })
}

async function saveBuffer(): Promise<void> {
  if (!guardEditable()) return
  const value = Number.parseFloat(bufferInput.value)
  if (!Number.isFinite(value)) return

  await budget.updateBuffer(value.toFixed(2))
}

async function saveWeeks(): Promise<void> {
  if (!guardEditable()) return

  await budget.saveWeeks(
    weekRows.value.map((row) => ({
      week_number: row.week_number,
      budget_amount: Number.parseFloat(weekInputs.value[row.week_number] ?? '0').toFixed(2),
    })),
  )
  ui.success('Weekly budgets saved')
}

/** Redistribute the spending budget evenly across the weeks. */
function splitEvenly(): void {
  const count = weekRows.value.length
  if (count === 0) return

  const each = Math.floor((spendingBudget.value / count) * 100) / 100
  let remainder = Math.round((spendingBudget.value - each * count) * 100) / 100

  const next: Record<number, string> = {}
  for (const [index, row] of weekRows.value.entries()) {
    const bonus = index === 0 ? remainder : 0
    next[row.week_number] = (each + bonus).toFixed(2)
    if (index === 0) remainder = 0
  }
  weekInputs.value = next
}

async function finalize(): Promise<void> {
  try {
    await saveWeeks()
    await budget.finalize()
    await dashboard.refresh()
    ui.success('Plan is live', 'Your weekly budgets are now in force.')
    void router.push({ name: 'dashboard' })
  } catch (error) {
    if (error instanceof ApiError) {
      ui.error('Could not finalise the plan', error.message)
    }
  }
}

async function toggleDeficit(): Promise<void> {
  await budget.allowDeficit(!(summary.value?.allow_deficit ?? false))
}

async function reopen(): Promise<void> {
  await budget.reopen('Corrections after finalising')
  ui.info('Plan reopened', 'Changes you make are recorded in the audit trail.')
}
</script>

<template>
  <div>
    <PageHeader
      :title="plan ? plan.label : 'Monthly plan'"
      :subtitle="
        plan
          ? `${formatDateRange(plan.cycle_start_date, plan.cycle_end_date)} · ${plan.status === 'draft' ? 'Draft' : plan.status === 'active' ? 'Active' : 'Completed'}`
          : undefined
      "
    />

    <LoadingState v-if="budget.loading && !plan" :rows="3" />

    <div v-else-if="plan && summary" class="space-y-5">
      <!-- Step rail -->
      <ol class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="Planning steps">
        <li v-for="(label, index) in STEPS" :key="label">
          <button
            type="button"
            class="flex min-h-9 shrink-0 items-center gap-1.5 rounded-[var(--radius-pill)] px-3 py-1.5 text-sm font-semibold transition"
            :class="
              index === step
                ? 'bg-brand text-on-brand'
                : index < step
                  ? 'bg-brand-soft text-ink'
                  : 'bg-sunken text-ink-muted'
            "
            :aria-current="index === step ? 'step' : undefined"
            @click="step = index"
          >
            <Check v-if="index < step" class="h-3.5 w-3.5" aria-hidden="true" />
            <span v-else class="tabular">{{ index + 1 }}</span>
            {{ label }}
          </button>
        </li>
      </ol>

      <!-- Over-allocation warning, shown wherever the user is in the flow. -->
      <div
        v-if="summary.is_over_allocated"
        class="rounded-[var(--radius-card)] bg-over-soft p-4"
        role="alert"
      >
        <div class="flex items-start gap-3">
          <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-over" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-bold text-over">Plan over-allocated</p>
            <p class="mt-1 text-sm text-ink">
              You have allocated
              <MoneyText :amount="summary.over_allocated_by" size="sm" class="font-bold" />
              more than your income. Adjust the plan before continuing.
            </p>

            <label class="mt-3 flex cursor-pointer items-start gap-2.5 text-sm">
              <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0 rounded border-line accent-[rgb(var(--color-over))]"
                :checked="summary.allow_deficit"
                @change="toggleDeficit"
              />
              <span class="text-ink-muted">
                I understand and want to continue with a deficit this month.
              </span>
            </label>
          </div>
        </div>
      </div>

      <!-- Step 1 — income -->
      <section v-if="step === 0" class="card space-y-5 p-4">
        <div>
          <p class="eyebrow">Base salary</p>
          <MoneyText :amount="plan.expected_income" size="lg" class="mt-0.5 block font-semibold" />
        </div>

        <!-- Money the previous cycle handed forward. -->
        <div
          v-if="amountToNumber(plan.opening_balance) > 0"
          class="rounded-[var(--radius-field)] bg-safe-soft p-3"
        >
          <p class="text-sm font-semibold text-safe">
            Carried over:
            <MoneyText :amount="plan.opening_balance" size="sm" class="font-bold" />
          </p>
          <p class="mt-0.5 text-xs text-ink-muted">
            Left over from last cycle. It is added on top of your salary.
          </p>
        </div>

        <MoneyInput
          v-model="actualIncome"
          large
          label="How much actually arrived?"
          :disabled="isFinalized"
        />

        <div
          v-if="amountToNumber(actualIncome) > amountToNumber(plan.expected_income)"
          class="rounded-[var(--radius-field)] bg-safe-soft p-3"
        >
          <p class="text-sm font-semibold text-safe">
            Extra income:
            <MoneyText
              :amount="(amountToNumber(actualIncome) - amountToNumber(plan.expected_income)).toFixed(2)"
              size="sm"
              class="font-bold"
            />
          </p>
          <p class="mt-0.5 text-xs text-ink-muted">
            This will be split across debt, savings and spending using your configured rule.
          </p>
        </div>

        <button
          type="button"
          class="btn btn-primary w-full"
          :disabled="budget.saving || isFinalized"
          @click="saveIncome"
        >
          {{ budget.saving ? 'Saving…' : 'Record income' }}
          <ArrowRight class="h-4 w-4" aria-hidden="true" />
        </button>
      </section>

      <!-- Step 2 — fixed expenses -->
      <section v-else-if="step === 1" class="space-y-3">
        <p class="text-sm text-ink-muted">
          These are pulled from your recurring expenses. Editing one here only affects this month.
        </p>

        <div v-for="bill in fixedExpenses" :key="bill.id" class="card p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-ink">{{ bill.name }}</p>
              <p class="text-xs text-ink-subtle">
                <template v-if="bill.occurrences > 1">
                  {{ bill.occurrences }}× this cycle ·
                </template>
                Due {{ formatDate(bill.due_date) }}
              </p>
            </div>

            <MoneyText
              :amount="bill.effective_amount"
              size="base"
              class="shrink-0 font-bold"
              :class="bill.counts_toward_plan ? '' : 'text-ink-subtle line-through'"
            />
          </div>

          <div class="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              class="badge min-h-9 px-3"
              :class="bill.status === 'planned' ? 'bg-brand text-on-brand' : 'bg-sunken text-ink-muted'"
              :disabled="isFinalized"
              @click="setBillStatus(bill.id, 'planned')"
            >
              <CheckCircle2 class="h-3.5 w-3.5" aria-hidden="true" />
              Include
            </button>
            <button
              type="button"
              class="badge min-h-9 px-3"
              :class="bill.status === 'skipped' ? 'bg-over text-white' : 'bg-sunken text-ink-muted'"
              :disabled="isFinalized"
              @click="setBillStatus(bill.id, 'skipped')"
            >
              <Ban class="h-3.5 w-3.5" aria-hidden="true" />
              Skip
            </button>
            <button
              type="button"
              class="badge min-h-9 px-3"
              :class="bill.status === 'postponed' ? 'bg-warn text-white' : 'bg-sunken text-ink-muted'"
              :disabled="isFinalized"
              @click="setBillStatus(bill.id, 'postponed')"
            >
              <Clock class="h-3.5 w-3.5" aria-hidden="true" />
              Postpone
            </button>
            <button
              type="button"
              class="badge min-h-9 bg-sunken px-3 text-ink-muted"
              :disabled="isFinalized"
              @click="setBillStatus(bill.id, 'paid')"
            >
              <Check class="h-3.5 w-3.5" aria-hidden="true" />
              Paid
            </button>
          </div>

          <details v-if="!isFinalized" class="mt-3">
            <summary class="cursor-pointer py-1 text-sm font-medium text-ink-muted">
              <Pencil class="mr-1 inline h-3.5 w-3.5" aria-hidden="true" />
              Change the amount for this month
            </summary>
            <div class="mt-3">
              <MoneyInput
                :model-value="String(Number.parseFloat(bill.amount))"
                @update:model-value="(value) => setBillAmount(bill.id, value)"
              />
            </div>
          </details>
        </div>

        <div v-if="addingBill" class="card space-y-4 p-4">
          <TextField v-model="newBill.name" label="Name" placeholder="One-off bill" />
          <MoneyInput v-model="newBill.amount" label="Amount" />
          <TextField v-model="newBill.due_date" label="Due date" type="date" />
          <div class="flex gap-3">
            <button type="button" class="btn btn-secondary flex-1" @click="addingBill = false">Cancel</button>
            <button type="button" class="btn btn-primary flex-1" @click="addBill">Add</button>
          </div>
        </div>

        <button
          v-else
          type="button"
          class="btn btn-secondary w-full"
          :disabled="isFinalized"
          @click="addingBill = true"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add a one-off bill
        </button>

        <div class="card flex items-center justify-between p-4">
          <span class="text-sm font-semibold text-ink">Fixed expenses</span>
          <MoneyText :amount="summary.fixed_expenses" size="lg" class="font-bold" />
        </div>
      </section>

      <!-- Step 3 — allowances: money set aside for gradual spending -->
      <section v-else-if="step === 2" class="space-y-3">
        <p class="text-sm text-ink-muted">
          Some spending is not one payment — fuel, groceries, eating out. Set
          aside an amount for each and it is reserved out of your income, then
          drawn down as you spend, instead of competing with your daily money.
        </p>

        <div v-for="category in allowanceCategories" :key="category.id" class="card p-4">
          <div class="mb-3 flex items-center gap-2.5">
            <CategoryIcon :icon="category.icon" :color="category.color" size="sm" />
            <span class="text-sm font-semibold text-ink">{{ category.name }}</span>
          </div>

          <MoneyInput
            v-model="allowanceInputs[category.id]"
            :disabled="isFinalized"
            placeholder="0"
          />
        </div>

        <p v-if="!allowanceCategories.length" class="text-sm text-ink-muted">
          You have no categories yet. Add some from Settings first.
        </p>

        <div class="card flex items-center justify-between p-4">
          <span class="text-sm font-semibold text-ink">Set aside</span>
          <MoneyText :amount="allowanceTotal.toFixed(2)" size="lg" class="font-bold" />
        </div>

        <button
          type="button"
          class="btn btn-secondary w-full"
          :disabled="budget.saving || isFinalized"
          @click="saveAllowances"
        >
          {{ budget.saving ? 'Saving…' : 'Save allowances' }}
        </button>

        <!-- Progress only means something once the cycle is under way. -->
        <div v-if="isFinalized && budget.allowances.length" class="card p-4">
          <p class="eyebrow mb-3">How they are going</p>
          <AllowanceList :allowances="budget.allowances" />
        </div>
      </section>

      <!-- Step 4 — debt -->
      <section v-else-if="step === 3" class="space-y-3">
        <p v-if="!debtAllocations.length" class="text-sm text-ink-muted">
          You have no active debts. Nothing to allocate here.
        </p>

        <div v-for="allocation in debtAllocations" :key="allocation.id" class="card space-y-4 p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-ink">{{ allocation.debt?.name }}</p>
              <p class="text-xs text-ink-subtle">
                Balance <MoneyText :amount="allocation.debt?.current_balance ?? '0'" size="xs" />
              </p>
            </div>
          </div>

          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt class="text-xs text-ink-subtle">Minimum</dt>
              <dd><MoneyText :amount="allocation.minimum_payment" size="sm" class="font-semibold" /></dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Recommended</dt>
              <dd><MoneyText :amount="allocation.recommended_payment" size="sm" class="font-semibold" /></dd>
            </div>
          </dl>

          <MoneyInput
            :model-value="String(Number.parseFloat(allocation.planned_amount))"
            label="Pay this month"
            :disabled="isFinalized"
            @update:model-value="(value) => saveDebtAllocation(allocation.debt_id, value)"
          />
        </div>

        <div class="card flex items-center justify-between p-4">
          <span class="text-sm font-semibold text-ink">Total debt payments</span>
          <MoneyText :amount="summary.debt_payment" size="lg" class="font-bold" />
        </div>
      </section>

      <!-- Step 4 — savings -->
      <section v-else-if="step === 4" class="space-y-3">
        <p v-if="!savingsAllocations.length" class="text-sm text-ink-muted">
          You have no savings goals yet. Add one from the Savings screen.
        </p>

        <div v-for="allocation in savingsAllocations" :key="allocation.id" class="card space-y-4 p-4">
          <div>
            <p class="text-sm font-semibold text-ink">{{ allocation.savings_goal?.name }}</p>
            <p class="text-xs text-ink-subtle">
              Recommended
              <MoneyText :amount="allocation.recommended_amount" size="xs" class="font-semibold" />
            </p>
          </div>

          <MoneyInput
            :model-value="String(Number.parseFloat(allocation.planned_amount))"
            label="Save this month"
            :disabled="isFinalized"
            @update:model-value="(value) => saveSavingsAllocation(allocation.savings_goal_id, value)"
          />
        </div>

        <div class="card flex items-center justify-between p-4">
          <span class="text-sm font-semibold text-ink">Total savings</span>
          <MoneyText :amount="summary.savings" size="lg" class="font-bold" />
        </div>
      </section>

      <!-- Step 5 — spending money -->
      <section v-else-if="step === 5" class="space-y-4">
        <div class="card divide-y divide-line">
          <div class="flex items-center justify-between px-4 py-3">
            <span class="text-sm text-ink-muted">Salary</span>
            <MoneyText :amount="summary.salary_income" size="sm" class="font-semibold" />
          </div>
          <div
            v-if="amountToNumber(summary.opening_balance) > 0"
            class="flex items-center justify-between px-4 py-3"
          >
            <span class="text-sm text-ink-muted">+ Carried over</span>
            <MoneyText :amount="summary.opening_balance" size="sm" class="font-semibold text-safe" />
          </div>
          <div class="flex items-center justify-between px-4 py-3">
            <span class="text-sm font-semibold text-ink">Total income</span>
            <MoneyText :amount="summary.total_income" size="sm" class="font-bold" />
          </div>
          <div class="flex items-center justify-between px-4 py-3">
            <span class="text-sm text-ink-muted">− Fixed expenses</span>
            <MoneyText :amount="summary.fixed_expenses" size="sm" class="font-semibold" />
          </div>
          <div
            v-if="amountToNumber(summary.allowances) > 0"
            class="flex items-center justify-between px-4 py-3"
          >
            <span class="text-sm text-ink-muted">− Allowances</span>
            <MoneyText :amount="summary.allowances" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-4 py-3">
            <span class="text-sm text-ink-muted">− Debt payments</span>
            <MoneyText :amount="summary.debt_payment" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-4 py-3">
            <span class="text-sm text-ink-muted">− Savings</span>
            <MoneyText :amount="summary.savings" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-4 py-3">
            <span class="text-sm text-ink-muted">− Buffer</span>
            <MoneyText :amount="summary.buffer" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between bg-sunken px-4 py-3.5">
            <span class="text-sm font-bold text-ink">= Available to spend</span>
            <MoneyText :amount="summary.spending_budget" size="lg" class="font-bold" colored />
          </div>
        </div>

        <div class="card p-4">
          <p class="eyebrow mb-3">Where your income goes</p>
          <AllocationChart :breakdown="summary.breakdown" />
        </div>

        <div class="card space-y-3 p-4">
          <MoneyInput
            v-model="bufferInput"
            label="Buffer"
            hint="Held back for the unexpected. It stays out of your weekly spending."
            :disabled="isFinalized"
          />
          <button
            type="button"
            class="btn btn-secondary w-full"
            :disabled="budget.saving || isFinalized"
            @click="saveBuffer"
          >
            Update buffer
          </button>
        </div>
      </section>

      <!-- Step 6 — weekly budgets -->
      <section v-else class="space-y-3">
        <div class="flex items-center justify-between gap-3">
          <p class="text-sm text-ink-muted">
            Split
            <MoneyText :amount="summary.spending_budget" size="sm" class="font-semibold text-ink" />
            across your weeks. They do not have to be equal.
          </p>
          <button type="button" class="btn btn-ghost shrink-0 !text-sm" :disabled="isFinalized" @click="splitEvenly">
            <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />
            Even split
          </button>
        </div>

        <div v-for="row in weekRows" :key="row.week_number" class="card p-4">
          <div class="mb-3 flex items-baseline justify-between">
            <p class="text-sm font-semibold text-ink">Week {{ row.week_number }}</p>
            <p class="text-xs text-ink-subtle">
              {{ formatDateRange(row.start_date, row.end_date) }} · {{ row.days }} days
            </p>
          </div>

          <MoneyInput v-model="weekInputs[row.week_number]" :disabled="isFinalized" />
        </div>

        <div
          class="card flex items-center justify-between p-4"
          :class="Math.abs(weeklyDifference) > 0.01 ? 'border-warn' : ''"
        >
          <div>
            <p class="text-sm font-semibold text-ink">Allocated across weeks</p>
            <p v-if="Math.abs(weeklyDifference) > 0.01" class="mt-0.5 text-xs text-warn">
              {{ weeklyDifference > 0 ? 'Over' : 'Under' }} your spending budget by
              <MoneyText :amount="Math.abs(weeklyDifference).toFixed(2)" size="xs" class="font-semibold" />
            </p>
          </div>
          <MoneyText :amount="weeklyTotal.toFixed(2)" size="lg" class="font-bold" />
        </div>

        <button
          type="button"
          class="btn btn-secondary w-full"
          :disabled="budget.saving || isFinalized"
          @click="saveWeeks"
        >
          Save weekly budgets
        </button>
      </section>

      <!-- Step navigation -->
      <div class="flex gap-3">
        <button v-if="step > 0" type="button" class="btn btn-secondary" @click="step -= 1">
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          Back
        </button>

        <button
          v-if="step < STEPS.length - 1"
          type="button"
          class="btn btn-primary flex-1"
          @click="step += 1"
        >
          Next
          <ArrowRight class="h-4 w-4" aria-hidden="true" />
        </button>

        <button
          v-else-if="!isFinalized"
          type="button"
          class="btn btn-primary flex-1 !text-base"
          :disabled="!canFinalize || budget.saving"
          @click="finalize"
        >
          <Wallet class="h-4 w-4" aria-hidden="true" />
          {{ budget.saving ? 'Finalising…' : 'Finalise plan' }}
        </button>
      </div>

      <p v-if="!canFinalize && step === STEPS.length - 1" class="text-center text-sm text-over">
        Resolve the over-allocation above before finalising.
      </p>

      <!-- Reopening a live plan is deliberate and audited. -->
      <div v-if="isFinalized" class="card p-4">
        <p class="text-sm font-semibold text-ink">This plan is {{ plan.status }}</p>
        <p class="mt-1 text-sm text-ink-muted">
          Reopen it to make corrections. The change is recorded in your audit trail.
        </p>
        <button type="button" class="btn btn-secondary mt-3 w-full" @click="reopen">
          <RotateCcw class="h-4 w-4" aria-hidden="true" />
          Reopen for corrections
        </button>
      </div>
    </div>
  </div>
</template>
