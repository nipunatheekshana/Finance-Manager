<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { Plus, Repeat } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { api, ApiError } from '@/services/api'
import { useExpenseStore } from '@/stores/expenses'
import { useUiStore } from '@/stores/ui'
import { todayIso } from '@/composables/useDates'
import type { ApiCollection, Frequency, RecurringProjection, RecurringTransaction } from '@/types'

const expenses = useExpenseStore()
const ui = useUiStore()

const items = ref<RecurringTransaction[]>([])
const projected = ref<Record<number, RecurringProjection>>({})
const projectedTotal = ref('0.00')
const windowRange = ref<{ start: string; end: string } | null>(null)

const loading = ref(true)
const sheetOpen = ref(false)
const editing = ref<RecurringTransaction | null>(null)
const saving = ref(false)
const deleting = ref(false)
const confirmDelete = ref<RecurringTransaction | null>(null)
const errors = reactive<Record<string, string>>({})

const FREQUENCY_OPTIONS: Array<{ value: Frequency; label: string }> = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'daily', label: 'Daily' },
  { value: 'yearly', label: 'Yearly' },
  { value: 'custom', label: 'Every N days' },
]

const WEEKDAY_OPTIONS = [
  { value: '1', label: 'Monday' }, { value: '2', label: 'Tuesday' },
  { value: '3', label: 'Wednesday' }, { value: '4', label: 'Thursday' },
  { value: '5', label: 'Friday' }, { value: '6', label: 'Saturday' },
  { value: '0', label: 'Sunday' },
]

const form = reactive({
  name: '',
  amount: '',
  minimum_amount: '',
  maximum_amount: '',
  category_id: null as number | null,
  payment_method_id: null as number | null,
  frequency: 'monthly' as Frequency,
  due_day: '1',
  day_of_week: '1',
  interval_days: '30',
  start_date: todayIso(),
  end_date: '',
})

const isVariable = computed(
  () => form.minimum_amount.trim() !== '' || form.maximum_amount.trim() !== '',
)

async function load(): Promise<void> {
  const response = await api.get<
    ApiCollection<
      RecurringTransaction,
      {
        window: { start: string; end: string }
        projected: Record<number, RecurringProjection>
        projected_total: string
      }
    >
  >('/recurring-transactions', { params: { include_inactive: true } })

  items.value = response.data
  projected.value = response.meta?.projected ?? {}
  projectedTotal.value = response.meta?.projected_total ?? '0.00'
  windowRange.value = response.meta?.window ?? null
}

function open(item: RecurringTransaction | null): void {
  editing.value = item
  Object.keys(errors).forEach((key) => delete errors[key])

  form.name = item?.name ?? ''
  form.amount = item ? String(Number.parseFloat(item.amount)) : ''
  form.minimum_amount = item?.minimum_amount ? String(Number.parseFloat(item.minimum_amount)) : ''
  form.maximum_amount = item?.maximum_amount ? String(Number.parseFloat(item.maximum_amount)) : ''
  form.category_id = item?.category_id ?? null
  form.payment_method_id = item?.payment_method_id ?? null
  form.frequency = item?.frequency ?? 'monthly'
  form.due_day = String(item?.due_day ?? 1)
  form.day_of_week = String(item?.day_of_week ?? 1)
  form.interval_days = String(item?.interval_days ?? 30)
  form.start_date = item?.start_date ?? todayIso()
  form.end_date = item?.end_date ?? ''

  sheetOpen.value = true
}

function decimal(value: string): string | null {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed.toFixed(2) : null
}

async function submit(): Promise<void> {
  saving.value = true
  Object.keys(errors).forEach((key) => delete errors[key])

  const payload = {
    name: form.name.trim(),
    amount: decimal(form.amount) ?? '0.00',
    minimum_amount: decimal(form.minimum_amount),
    maximum_amount: decimal(form.maximum_amount),
    amount_type: isVariable.value ? 'range' : 'fixed',
    category_id: form.category_id,
    payment_method_id: form.payment_method_id,
    frequency: form.frequency,
    due_day: form.frequency === 'monthly' || form.frequency === 'yearly' ? Number(form.due_day) : null,
    day_of_week: form.frequency === 'weekly' ? Number(form.day_of_week) : null,
    interval_days: form.frequency === 'custom' ? Number(form.interval_days) : null,
    start_date: form.start_date,
    end_date: form.end_date || null,
  }

  try {
    if (editing.value) {
      await api.put(`/recurring-transactions/${editing.value.id}`, payload)
      ui.success('Recurring expense updated')
    } else {
      await api.post('/recurring-transactions', payload)
      ui.success('Recurring expense added')
    }

    await load()
    sheetOpen.value = false
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      Object.entries(error.errors).forEach(([field, messages]) => {
        errors[field] = messages[0] ?? ''
      })
    } else if (error instanceof ApiError) {
      ui.error('Could not save that expense', error.message)
    }
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!confirmDelete.value) return
  deleting.value = true

  try {
    await api.delete(`/recurring-transactions/${confirmDelete.value.id}`)
    ui.success('Recurring expense removed')
    await load()
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not remove it', error.message)
  } finally {
    deleting.value = false
    confirmDelete.value = null
  }
}

function describe(item: RecurringTransaction): string {
  switch (item.frequency) {
    case 'monthly':
      return `Monthly on day ${item.due_day ?? 1}`
    case 'weekly':
      return `Weekly on ${WEEKDAY_OPTIONS.find((day) => Number(day.value) === item.day_of_week)?.label ?? 'a set day'}`
    case 'daily':
      return 'Every day'
    case 'yearly':
      return 'Once a year'
    default:
      return `Every ${item.interval_days ?? 30} days`
  }
}

onMounted(async () => {
  await Promise.all([expenses.loadReference(), load()])
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader
      title="Recurring expenses"
      subtitle="These are pulled into every monthly plan automatically."
      back-to="/settings"
    >
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="open(null)">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span class="sr-only">Add recurring expense</span>
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" variant="list" :rows="5" />

    <EmptyState
      v-else-if="items.length === 0"
      :icon="Repeat"
      title="No recurring expenses"
      description="Add the bills you pay regularly so they are budgeted for automatically."
      action-label="Add recurring expense"
      @action="open(null)"
    />

    <div v-else class="space-y-4">
      <!-- Counted from real dates, so a five-Monday month costs five weeks. -->
      <div v-if="windowRange" class="card p-4">
        <p class="eyebrow">Next 30 days</p>
        <MoneyText :amount="projectedTotal" size="xl" class="mt-1 block font-bold" />
        <p class="mt-0.5 text-xs text-ink-subtle">
          Counted from the actual dates each bill falls on, not a flat monthly figure.
        </p>
      </div>

      <ul class="card divide-y divide-line px-4">
        <li v-for="item in items" :key="item.id" class="flex items-center gap-3 py-3">
          <button type="button" class="min-w-0 flex-1 text-left" @click="open(item)">
            <p class="truncate text-sm font-medium text-ink">
              {{ item.name }}
              <span v-if="item.is_variable" class="badge ml-1 bg-info-soft text-info">Varies</span>
              <span v-if="!item.active" class="badge ml-1 bg-sunken text-ink-subtle">Paused</span>
            </p>
            <p class="text-xs text-ink-subtle">
              {{ describe(item) }}
              <template v-if="projected[item.id] && projected[item.id].occurrences > 1">
                · {{ projected[item.id].occurrences }}× in the next 30 days
              </template>
            </p>
            <p v-if="item.is_variable" class="text-xs text-ink-subtle">
              Between <MoneyText :amount="item.minimum_amount ?? '0'" size="xs" />
              and <MoneyText :amount="item.maximum_amount ?? '0'" size="xs" />
            </p>
          </button>

          <div class="shrink-0 text-right">
            <MoneyText :amount="item.amount" size="sm" class="font-semibold" />
            <button
              type="button"
              class="block text-xs font-medium text-over"
              @click="confirmDelete = item"
            >
              Remove
            </button>
          </div>
        </li>
      </ul>
    </div>

    <BottomSheet
      :open="sheetOpen"
      :title="editing ? 'Edit recurring expense' : 'New recurring expense'"
      :busy="saving"
      @close="sheetOpen = false"
    >
      <div class="space-y-4 pb-2">
        <TextField v-model="form.name" label="Name" placeholder="SLT" required :error="errors.name" data-autofocus />

        <MoneyInput
          v-model="form.amount"
          label="Expected amount"
          hint="The figure used when planning. For a variable bill, your best estimate."
          :error="errors.amount"
        />

        <details :open="isVariable">
          <summary class="cursor-pointer py-1 text-sm font-medium text-ink-muted">
            This amount varies month to month
          </summary>
          <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <MoneyInput v-model="form.minimum_amount" label="Lowest" :error="errors.minimum_amount" />
            <MoneyInput v-model="form.maximum_amount" label="Highest" :error="errors.maximum_amount" />
          </div>
        </details>

        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField
            v-model="form.category_id"
            :options="expenses.activeCategories.map((c) => ({ value: c.id, label: c.name }))"
            label="Category"
            placeholder="None"
          />
          <SelectField
            v-model="form.payment_method_id"
            :options="expenses.activePaymentMethods.map((m) => ({ value: m.id, label: m.name }))"
            label="Paid with"
            placeholder="None"
          />
        </div>

        <SelectField v-model="form.frequency" :options="FREQUENCY_OPTIONS" label="How often" :error="errors.frequency" />

        <TextField
          v-if="form.frequency === 'monthly' || form.frequency === 'yearly'"
          v-model="form.due_day"
          label="Due day"
          type="number"
          inputmode="numeric"
          min="1"
          max="31"
          :error="errors.due_day"
        />

        <SelectField
          v-else-if="form.frequency === 'weekly'"
          v-model="form.day_of_week"
          :options="WEEKDAY_OPTIONS"
          label="Day of the week"
          :error="errors.day_of_week"
        />

        <TextField
          v-else-if="form.frequency === 'custom'"
          v-model="form.interval_days"
          label="Every how many days?"
          type="number"
          inputmode="numeric"
          min="1"
          :error="errors.interval_days"
        />

        <div class="grid gap-4 sm:grid-cols-2">
          <TextField v-model="form.start_date" label="Starts" type="date" :error="errors.start_date" />
          <TextField v-model="form.end_date" label="Ends (optional)" type="date" :error="errors.end_date" />
        </div>
      </div>

      <template #footer>
        <button
          type="button"
          class="btn btn-primary w-full !text-base"
          :disabled="saving || form.name.trim() === ''"
          @click="submit"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add expense' }}
        </button>
      </template>
    </BottomSheet>

    <ConfirmDialog
      :open="confirmDelete !== null"
      title="Remove this recurring expense?"
      message="It will no longer be pulled into future plans. Past plans are unaffected."
      confirm-label="Remove"
      destructive
      :busy="deleting"
      @confirm="remove"
      @cancel="confirmDelete = null"
    />
  </div>
</template>
