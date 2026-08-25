<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, ArrowRight, Check, CreditCard, PiggyBank, Plus, Receipt, Trash2, Wallet } from 'lucide-vue-next'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { DebtType, Frequency } from '@/types'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

const step = ref(0)
const submitting = ref(false)
const errors = ref<Record<string, string>>({})

interface RecurringRow {
  name: string
  amount: string
  minimum_amount: string
  maximum_amount: string
  category_name: string
  frequency: Frequency
  due_day: string
  day_of_week: string
}

interface DebtRow {
  name: string
  type: DebtType
  current_balance: string
  credit_limit: string
  interest_rate: string
  minimum_payment: string
  planned_payment: string
  due_day: string
  remaining_installments: string
}

interface GoalRow {
  name: string
  target_amount: string
  current_amount: string
  monthly_target: string
}

const form = reactive({
  base_salary: '',
  salary_day: '25',
  has_extra_income: false,
  default_buffer: '',
  recurring: [] as RecurringRow[],
  debts: [] as DebtRow[],
  savings_goals: [] as GoalRow[],
})

const STEPS = [
  { title: 'Your salary', description: 'What comes in, and when.', icon: Wallet },
  { title: 'Recurring expenses', description: 'The bills you pay every month.', icon: Receipt },
  { title: 'Debts', description: 'Credit cards, installments and loans.', icon: CreditCard },
  { title: 'Savings goals', description: 'What you are putting money aside for.', icon: PiggyBank },
]

const CATEGORY_OPTIONS = [
  'Bills', 'Food', 'Transport', 'Gym', 'Health', 'Smoking',
  'Personal', 'Subscriptions', 'Family', 'Other',
].map((name) => ({ value: name, label: name }))

const FREQUENCY_OPTIONS: Array<{ value: Frequency; label: string }> = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'daily', label: 'Daily' },
  { value: 'yearly', label: 'Yearly' },
]

const DEBT_TYPE_OPTIONS: Array<{ value: DebtType; label: string }> = [
  { value: 'credit_card', label: 'Credit Card' },
  { value: 'installment', label: 'Installment' },
  { value: 'loan', label: 'Loan' },
  { value: 'personal', label: 'Personal Debt' },
  { value: 'other', label: 'Other' },
]

const WEEKDAY_OPTIONS = [
  { value: '1', label: 'Monday' }, { value: '2', label: 'Tuesday' },
  { value: '3', label: 'Wednesday' }, { value: '4', label: 'Thursday' },
  { value: '5', label: 'Friday' }, { value: '6', label: 'Saturday' },
  { value: '0', label: 'Sunday' },
]

const canContinue = computed(() => {
  if (step.value === 0) {
    return Number.parseFloat(form.base_salary) > 0 && Number(form.salary_day) >= 1
  }
  return true
})

const isLastStep = computed(() => step.value === STEPS.length - 1)

function addRecurring(): void {
  form.recurring.push({
    name: '',
    amount: '',
    minimum_amount: '',
    maximum_amount: '',
    category_name: 'Bills',
    frequency: 'monthly',
    due_day: '1',
    day_of_week: '1',
  })
}

function addDebt(): void {
  form.debts.push({
    name: '',
    type: 'credit_card',
    current_balance: '',
    credit_limit: '',
    interest_rate: '',
    minimum_payment: '',
    planned_payment: '',
    due_day: '1',
    remaining_installments: '',
  })
}

function addGoal(): void {
  form.savings_goals.push({ name: '', target_amount: '', current_amount: '', monthly_target: '' })
}

function next(): void {
  if (isLastStep.value) {
    void finish()
    return
  }
  step.value += 1
}

function back(): void {
  if (step.value > 0) step.value -= 1
}

function decimal(value: string): string | undefined {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed.toFixed(2) : undefined
}

/** Build the payload, dropping incomplete rows rather than failing on them. */
function payload(): Record<string, unknown> {
  return {
    base_salary: decimal(form.base_salary),
    salary_day: Number(form.salary_day),
    has_extra_income: form.has_extra_income,
    default_buffer: decimal(form.default_buffer) ?? '0.00',

    recurring: form.recurring
      .filter((row) => row.name.trim() !== '' && Number.parseFloat(row.amount) > 0)
      .map((row) => ({
        name: row.name.trim(),
        amount: decimal(row.amount),
        minimum_amount: decimal(row.minimum_amount),
        maximum_amount: decimal(row.maximum_amount),
        category_name: row.category_name,
        frequency: row.frequency,
        due_day: row.frequency === 'monthly' || row.frequency === 'yearly' ? Number(row.due_day) : null,
        day_of_week: row.frequency === 'weekly' ? Number(row.day_of_week) : null,
      })),

    debts: form.debts
      .filter((row) => row.name.trim() !== '' && Number.parseFloat(row.current_balance) >= 0)
      .map((row) => ({
        name: row.name.trim(),
        type: row.type,
        current_balance: decimal(row.current_balance),
        credit_limit: decimal(row.credit_limit),
        interest_rate: Number.isFinite(Number.parseFloat(row.interest_rate))
          ? Number.parseFloat(row.interest_rate)
          : null,
        minimum_payment: decimal(row.minimum_payment) ?? '0.00',
        planned_payment: decimal(row.planned_payment) ?? '0.00',
        due_day: Number(row.due_day) || null,
        remaining_installments: row.type === 'installment' && row.remaining_installments !== ''
          ? Number(row.remaining_installments)
          : null,
      })),

    savings_goals: form.savings_goals
      .filter((row) => row.name.trim() !== '' && Number.parseFloat(row.target_amount) > 0)
      .map((row) => ({
        name: row.name.trim(),
        target_amount: decimal(row.target_amount),
        current_amount: decimal(row.current_amount) ?? '0.00',
        monthly_target: decimal(row.monthly_target) ?? '0.00',
        allocation_type: 'fixed',
        allocation_value: decimal(row.monthly_target) ?? '0.00',
        priority: 1,
      })),
  }
}

async function finish(): Promise<void> {
  submitting.value = true
  errors.value = {}

  try {
    await auth.completeOnboarding(payload())
    ui.success('You are all set', 'Your plan is ready to build.')
    void router.push({ name: 'dashboard' })
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
      ui.error('Please check your details', 'Some entries could not be saved.')
    } else if (error instanceof ApiError) {
      ui.error('Setup could not be saved', error.message)
    }
  } finally {
    submitting.value = false
  }
}

async function skip(): Promise<void> {
  submitting.value = true
  try {
    await auth.skipOnboarding()
    void router.push({ name: 'dashboard' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto flex min-h-dvh w-full max-w-2xl flex-col px-4 py-6 sm:px-6">
    <!-- Progress across the four steps. -->
    <ol class="mb-7 flex items-center gap-2" aria-label="Setup progress">
      <li v-for="(item, index) in STEPS" :key="item.title" class="flex flex-1 items-center gap-2">
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold transition"
          :class="
            index < step
              ? 'bg-brand text-on-brand'
              : index === step
                ? 'bg-brand text-on-brand ring-4 ring-brand-soft'
                : 'bg-sunken text-ink-subtle'
          "
          :aria-current="index === step ? 'step' : undefined"
        >
          <Check v-if="index < step" class="h-3.5 w-3.5" aria-hidden="true" />
          <template v-else>{{ index + 1 }}</template>
        </span>
        <span class="sr-only">{{ item.title }}</span>
        <span
          v-if="index < STEPS.length - 1"
          class="h-0.5 flex-1 rounded-full"
          :class="index < step ? 'bg-brand' : 'bg-sunken'"
          aria-hidden="true"
        />
      </li>
    </ol>

    <header class="mb-6">
      <h1 class="text-2xl font-bold tracking-tight text-ink">{{ STEPS[step].title }}</h1>
      <p class="mt-1 text-sm text-ink-muted">{{ STEPS[step].description }}</p>
    </header>

    <div class="flex-1 space-y-5">
      <!-- Step 1 — salary -->
      <template v-if="step === 0">
        <MoneyInput
          v-model="form.base_salary"
          large
          autofocus
          label="What is your base monthly salary?"
          :error="errors.base_salary"
        />

        <TextField
          v-model="form.salary_day"
          label="What day do you normally receive it?"
          type="number"
          inputmode="numeric"
          min="1"
          max="31"
          hint="Your monthly cycle runs from this day to the day before the next one."
          :error="errors.salary_day"
        />

        <label class="card flex min-h-14 cursor-pointer items-center justify-between gap-3 p-4">
          <span>
            <span class="block text-sm font-medium text-ink">I sometimes receive extra income</span>
            <span class="block text-xs text-ink-muted">Bonuses, overtime or freelance work.</span>
          </span>
          <input
            v-model="form.has_extra_income"
            type="checkbox"
            class="h-5 w-5 shrink-0 rounded border-line accent-[rgb(var(--color-brand))]"
          />
        </label>

        <MoneyInput
          v-model="form.default_buffer"
          label="Monthly buffer (optional)"
          hint="Money held back for the unexpected. It is kept out of your spending budget."
          :error="errors.default_buffer"
        />
      </template>

      <!-- Step 2 — recurring expenses -->
      <template v-else-if="step === 1">
        <div v-for="(row, index) in form.recurring" :key="index" class="card space-y-4 p-4">
          <div class="flex items-start justify-between gap-3">
            <TextField v-model="row.name" label="Name" placeholder="Gym" class="flex-1" />
            <button
              type="button"
              class="btn btn-ghost mt-6 !min-h-11 !w-11 !p-0 shrink-0 text-over"
              :aria-label="`Remove ${row.name || 'this expense'}`"
              @click="form.recurring.splice(index, 1)"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>

          <MoneyInput v-model="row.amount" label="Expected amount" />

          <div class="grid gap-4 sm:grid-cols-2">
            <SelectField v-model="row.category_name" :options="CATEGORY_OPTIONS" label="Category" />
            <SelectField v-model="row.frequency" :options="FREQUENCY_OPTIONS" label="Frequency" />
          </div>

          <TextField
            v-if="row.frequency === 'monthly' || row.frequency === 'yearly'"
            v-model="row.due_day"
            label="Due day"
            type="number"
            inputmode="numeric"
            min="1"
            max="31"
          />

          <SelectField
            v-else-if="row.frequency === 'weekly'"
            v-model="row.day_of_week"
            :options="WEEKDAY_OPTIONS"
            label="Day of the week"
          />

          <!-- For bills that vary, like a phone bill. -->
          <details class="text-sm">
            <summary class="cursor-pointer py-1 font-medium text-ink-muted">
              This amount varies month to month
            </summary>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
              <MoneyInput v-model="row.minimum_amount" label="Lowest it gets" />
              <MoneyInput v-model="row.maximum_amount" label="Highest it gets" />
            </div>
          </details>
        </div>

        <button type="button" class="btn btn-secondary w-full" @click="addRecurring">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add a recurring expense
        </button>
      </template>

      <!-- Step 3 — debts -->
      <template v-else-if="step === 2">
        <div v-for="(row, index) in form.debts" :key="index" class="card space-y-4 p-4">
          <div class="flex items-start justify-between gap-3">
            <TextField v-model="row.name" label="Name" placeholder="Credit Card" class="flex-1" />
            <button
              type="button"
              class="btn btn-ghost mt-6 !min-h-11 !w-11 !p-0 shrink-0 text-over"
              :aria-label="`Remove ${row.name || 'this debt'}`"
              @click="form.debts.splice(index, 1)"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>

          <SelectField v-model="row.type" :options="DEBT_TYPE_OPTIONS" label="Type" />

          <MoneyInput v-model="row.current_balance" label="Current balance" />

          <div class="grid gap-4 sm:grid-cols-2">
            <MoneyInput v-model="row.minimum_payment" label="Minimum payment" />
            <MoneyInput v-model="row.planned_payment" label="Planned monthly payment" />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <MoneyInput v-if="row.type === 'credit_card'" v-model="row.credit_limit" label="Credit limit" />
            <TextField
              v-if="row.type === 'installment'"
              v-model="row.remaining_installments"
              label="Installments left"
              type="number"
              inputmode="numeric"
              min="0"
            />
            <TextField
              v-model="row.interest_rate"
              label="Interest rate (%)"
              type="number"
              inputmode="decimal"
              min="0"
              hint="Leave blank if you are not sure."
            />
          </div>

          <TextField
            v-model="row.due_day"
            label="Payment due day"
            type="number"
            inputmode="numeric"
            min="1"
            max="31"
          />
        </div>

        <button type="button" class="btn btn-secondary w-full" @click="addDebt">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add a debt
        </button>
      </template>

      <!-- Step 4 — savings -->
      <template v-else>
        <div v-for="(row, index) in form.savings_goals" :key="index" class="card space-y-4 p-4">
          <div class="flex items-start justify-between gap-3">
            <TextField v-model="row.name" label="Goal" placeholder="Emergency Fund" class="flex-1" />
            <button
              type="button"
              class="btn btn-ghost mt-6 !min-h-11 !w-11 !p-0 shrink-0 text-over"
              :aria-label="`Remove ${row.name || 'this goal'}`"
              @click="form.savings_goals.splice(index, 1)"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>

          <MoneyInput v-model="row.target_amount" label="Target amount" />

          <div class="grid gap-4 sm:grid-cols-2">
            <MoneyInput v-model="row.current_amount" label="Already saved" />
            <MoneyInput v-model="row.monthly_target" label="Monthly contribution" />
          </div>

          <p v-if="Number.parseFloat(row.target_amount) > 0" class="text-sm text-ink-muted">
            <MoneyText :amount="row.current_amount || '0'" size="sm" class="font-semibold text-ink" />
            of
            <MoneyText :amount="row.target_amount" size="sm" class="font-semibold text-ink" />
          </p>
        </div>

        <button type="button" class="btn btn-secondary w-full" @click="addGoal">
          <Plus class="h-4 w-4" aria-hidden="true" />
          Add a savings goal
        </button>
      </template>
    </div>

    <footer class="sticky bottom-0 mt-8 space-y-3 bg-surface pb-safe pt-4">
      <div class="flex gap-3">
        <button v-if="step > 0" type="button" class="btn btn-secondary" :disabled="submitting" @click="back">
          <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          Back
        </button>

        <button
          type="button"
          class="btn btn-primary flex-1 !text-base"
          :disabled="!canContinue || submitting"
          @click="next"
        >
          <template v-if="submitting">Saving…</template>
          <template v-else-if="isLastStep">
            <Check class="h-4 w-4" aria-hidden="true" />
            Finish setup
          </template>
          <template v-else>
            Continue
            <ArrowRight class="h-4 w-4" aria-hidden="true" />
          </template>
        </button>
      </div>

      <button
        type="button"
        class="btn btn-ghost w-full !text-sm"
        :disabled="submitting"
        @click="skip"
      >
        Skip for now — I will set this up in Settings
      </button>
    </footer>
  </div>
</template>
