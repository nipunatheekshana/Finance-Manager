<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { AlertTriangle, CreditCard, Info, Trash2 } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import CategorySelector from '@/components/common/CategorySelector.vue'
import SelectField from '@/components/common/SelectField.vue'
import DatePicker from '@/components/common/DatePicker.vue'
import TextField from '@/components/common/TextField.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { useExpenseStore } from '@/stores/expenses'
import { useDashboardStore } from '@/stores/dashboard'
import { useBudgetStore } from '@/stores/budget'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { formatLKR } from '@/composables/useCurrency'
import { todayIso } from '@/composables/useDates'
import type { ExpenseDraft, ExpenseImpact, WeekStateAfterSave } from '@/types'

const ui = useUiStore()
const expenses = useExpenseStore()
const dashboard = useDashboardStore()
const budget = useBudgetStore()

const amount = ref('')
const categoryId = ref<number | null>(null)
const paymentMethodId = ref<number | null>(null)
const expenseDate = ref(todayIso())
const description = ref('')

const submitting = ref(false)
const errors = ref<Record<string, string>>({})
const confirmingDelete = ref(false)
const deleting = ref(false)

/** What this expense would do to the week, refreshed as the amount changes. */
const impact = ref<ExpenseImpact | null>(null)
const checkingImpact = ref(false)

/** Set once the user has seen the over-budget warning and chosen to continue. */
const acknowledgedOverspend = ref(false)

const isEditing = computed(() => ui.editingExpenseId !== null)

const selectedMethod = computed(() =>
  expenses.paymentMethodById(paymentMethodId.value),
)

/** Warn before the save, not after: this spending raises a debt balance. */
const chargesDebt = computed(() => selectedMethod.value?.increases_debt ?? false)

const paymentOptions = computed(() =>
  expenses.activePaymentMethods.map((method) => ({ value: method.id, label: method.name })),
)

const canSubmit = computed(
  () =>
    !submitting.value &&
    amount.value !== '' &&
    Number.parseFloat(amount.value) > 0 &&
    categoryId.value !== null &&
    paymentMethodId.value !== null,
)

let impactTimer: ReturnType<typeof setTimeout> | undefined

/**
 * Ask the server what this expense would do. Debounced so typing an amount
 * does not fire a request per keystroke.
 */
function refreshImpact(): void {
  clearTimeout(impactTimer)

  const value = Number.parseFloat(amount.value)

  if (!Number.isFinite(value) || value <= 0 || categoryId.value === null) {
    impact.value = null
    return
  }

  impactTimer = setTimeout(() => {
    checkingImpact.value = true

    void expenses
      .previewImpact({
        amount: value.toFixed(2),
        expense_date: expenseDate.value,
        category_id: categoryId.value,
        expense_id: ui.editingExpenseId,
      })
      .then((result) => {
        impact.value = result
      })
      .catch(() => {
        // A failed preview must never stand between the user and recording
        // their spending.
        impact.value = null
      })
      .finally(() => {
        checkingImpact.value = false
      })
  }, 350)
}

watch([amount, categoryId, expenseDate], () => {
  acknowledgedOverspend.value = false
  refreshImpact()
})

/** The week would go over, and the user has not acknowledged that yet. */
const needsAcknowledgement = computed(
  () => (impact.value?.will_exceed_week ?? false) && !acknowledgedOverspend.value,
)

function reset(): void {
  amount.value = ''
  description.value = ''
  expenseDate.value = todayIso()
  categoryId.value = expenses.defaultCategory?.id ?? null
  paymentMethodId.value = expenses.defaultPaymentMethod?.id ?? null
  errors.value = {}
  impact.value = null
  acknowledgedOverspend.value = false
}

/** Populate the form when the sheet opens, for a new entry or an edit. */
watch(
  () => ui.expenseSheetOpen,
  (open) => {
    if (!open) return

    const editing = ui.editingExpenseId === null ? undefined : expenses.find(ui.editingExpenseId)

    if (editing) {
      amount.value = String(Number.parseFloat(editing.amount))
      categoryId.value = editing.category_id
      paymentMethodId.value = editing.payment_method_id
      expenseDate.value = editing.expense_date
      description.value = editing.description ?? ''
      errors.value = {}
    } else {
      reset()
    }
  },
)

async function refreshBudgets(): Promise<void> {
  await Promise.allSettled([
    dashboard.refresh(),
    budget.plan ? budget.loadWeeks() : Promise.resolve(),
  ])
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return

  submitting.value = true
  errors.value = {}

  const draft: ExpenseDraft = {
    amount: Number.parseFloat(amount.value).toFixed(2),
    category_id: categoryId.value,
    payment_method_id: paymentMethodId.value,
    expense_date: expenseDate.value,
    description: description.value.trim() || undefined,
  }

  let savedWeek: WeekStateAfterSave | null = null

  try {
    if (isEditing.value && ui.editingExpenseId !== null) {
      const result = await expenses.update(ui.editingExpenseId, draft)
      ui.success('Expense updated')
      savedWeek = result.week
    } else {
      const result = await expenses.create(draft)

      if (result.queued) {
        ui.info('Expense saved locally', 'It will sync automatically when you reconnect.')
      } else {
        ui.success(`${formatLKR(draft.amount)} logged`)
        savedWeek = result.week ?? null
      }
    }

    ui.closeExpenseSheet()
    await refreshBudgets()

    // The week has gone over. Rather than leaving it to be discovered later,
    // hand the user the choice now: adjust a week, use the buffer, or accept it.
    if (savedWeek?.is_over) {
      ui.overspendWeekId = savedWeek.weekly_budget_id
    }
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    } else if (error instanceof ApiError) {
      ui.error('Could not save your expense', error.message)
    }
  } finally {
    submitting.value = false
  }
}

async function confirmDelete(): Promise<void> {
  if (ui.editingExpenseId === null) return

  deleting.value = true
  try {
    await expenses.remove(ui.editingExpenseId)
    confirmingDelete.value = false
    ui.closeExpenseSheet()
    ui.success('Expense deleted')
    await refreshBudgets()
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not delete that expense', error.message)
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <BottomSheet
    :open="ui.expenseSheetOpen"
    :title="isEditing ? 'Edit expense' : 'Add expense'"
    :busy="submitting"
    @close="ui.closeExpenseSheet()"
  >
    <form class="space-y-5 pb-2" @submit.prevent="submit">
      <!-- The amount is focused on open with a numeric keypad, so logging an
           expense is: tap +, type, tap save. -->
      <MoneyInput
        v-model="amount"
        large
        autofocus
        :error="errors.amount"
        data-autofocus
      />

      <!-- What this expense does to the week, worked out before it is saved. -->
      <div v-if="impact?.week" class="-mt-2">
        <div
          class="rounded-[var(--radius-field)] p-3"
          :class="
            impact.will_exceed_week || impact.already_over_week
              ? 'bg-over-soft'
              : impact.week.status_after === 'warning'
                ? 'bg-warn-soft'
                : 'bg-sunken'
          "
        >
          <div class="flex items-start gap-2.5">
            <component
              :is="impact.will_exceed_week || impact.already_over_week ? AlertTriangle : Info"
              class="mt-0.5 h-4 w-4 shrink-0"
              :class="
                impact.will_exceed_week || impact.already_over_week
                  ? 'text-over'
                  : impact.week.status_after === 'warning'
                    ? 'text-warn'
                    : 'text-ink-subtle'
              "
              aria-hidden="true"
            />

            <div class="min-w-0 flex-1 text-sm">
              <p
                class="font-semibold"
                :class="impact.will_exceed_week || impact.already_over_week ? 'text-over' : 'text-ink'"
              >
                {{ impact.headline }}
              </p>

              <p v-if="!impact.will_exceed_week && impact.week.days_remaining" class="mt-0.5 text-ink-muted">
                That is <MoneyText :amount="impact.week.daily_limit_after" size="sm" class="font-semibold" />
                a day for the {{ impact.week.days_remaining }} remaining
                {{ impact.week.days_remaining === 1 ? 'day' : 'days' }}.
              </p>

              <p v-if="impact.will_exceed_category && impact.category" class="mt-0.5 text-ink-muted">
                It also takes {{ impact.category.name }} over its
                <MoneyText :amount="impact.category.budget" size="sm" /> budget.
              </p>
            </div>
          </div>

          <!-- Recording the expense is never blocked, but going over the week
               has to be a deliberate act, not something that just happens. -->
          <label
            v-if="impact.will_exceed_week"
            class="mt-3 flex cursor-pointer items-start gap-2.5 border-t border-over/20 pt-3 text-sm"
          >
            <input
              v-model="acknowledgedOverspend"
              type="checkbox"
              class="mt-0.5 h-4 w-4 shrink-0 rounded border-line accent-[rgb(var(--color-over))]"
            />
            <span class="text-ink">
              I know this goes over — save it and let me choose what to do.
            </span>
          </label>
        </div>
      </div>

      <CategorySelector
        v-model="categoryId"
        :categories="expenses.activeCategories"
        label="Category"
        :error="errors.category_id"
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <SelectField
          v-model="paymentMethodId"
          :options="paymentOptions"
          label="Payment method"
          :error="errors.payment_method_id"
        />

        <DatePicker v-model="expenseDate" label="Date" quick-picks :error="errors.expense_date" />
      </div>

      <div
        v-if="chargesDebt"
        class="flex items-start gap-2.5 rounded-[var(--radius-field)] bg-warn-soft p-3 text-sm text-warn"
        role="status"
      >
        <CreditCard class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <span>This will be added to your {{ selectedMethod?.name }} balance.</span>
      </div>

      <TextField
        v-model="description"
        label="Description"
        placeholder="Optional"
        :error="errors.description"
      />

      <!-- Submit on Enter from any field. -->
      <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Save</button>
    </form>

    <template #footer>
      <div class="flex gap-3">
        <button
          v-if="isEditing"
          type="button"
          class="btn btn-secondary !w-12 !px-0 shrink-0 text-over"
          aria-label="Delete expense"
          :disabled="submitting"
          @click="confirmingDelete = true"
        >
          <Trash2 class="h-5 w-5" aria-hidden="true" />
        </button>

        <button
          type="button"
          class="btn flex-1 !text-base"
          :class="impact?.will_exceed_week ? 'btn-danger' : 'btn-primary'"
          :disabled="!canSubmit || needsAcknowledgement"
          @click="submit"
        >
          <template v-if="submitting">Saving…</template>
          <template v-else-if="needsAcknowledgement">Confirm you want to go over</template>
          <template v-else-if="impact?.will_exceed_week">Save anyway</template>
          <template v-else>{{ isEditing ? 'Save changes' : 'Save expense' }}</template>
        </button>
      </div>
    </template>
  </BottomSheet>

  <ConfirmDialog
    :open="confirmingDelete"
    title="Delete this expense?"
    message="This cannot be undone. Your budgets will be recalculated."
    confirm-label="Delete"
    destructive
    :busy="deleting"
    @confirm="confirmDelete"
    @cancel="confirmingDelete = false"
  />
</template>
