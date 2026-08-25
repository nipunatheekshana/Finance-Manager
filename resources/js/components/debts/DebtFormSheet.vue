<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { CreditCard } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import { useDebtStore } from '@/stores/debts'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { Debt, DebtType } from '@/types'

const props = defineProps<{ open: boolean; debt?: Debt | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const debts = useDebtStore()
const ui = useUiStore()

const TYPE_OPTIONS: Array<{ value: DebtType; label: string }> = [
  { value: 'credit_card', label: 'Credit Card' },
  { value: 'installment', label: 'Installment' },
  { value: 'loan', label: 'Loan' },
  { value: 'personal', label: 'Personal Debt' },
  { value: 'other', label: 'Other' },
]

const form = reactive({
  name: '',
  type: 'credit_card' as DebtType,
  current_balance: '',
  original_amount: '',
  credit_limit: '',
  interest_rate: '',
  minimum_payment: '',
  planned_payment: '',
  due_day: '',
  remaining_installments: '',
  early_settlement_amount: '',
})

const errors = reactive<Record<string, string>>({})

const isEditing = computed(() => Boolean(props.debt))

watch(
  () => props.open,
  (open) => {
    if (!open) return
    Object.keys(errors).forEach((key) => delete errors[key])

    const debt = props.debt
    form.name = debt?.name ?? ''
    form.type = debt?.type ?? 'credit_card'
    form.current_balance = debt ? String(Number.parseFloat(debt.current_balance)) : ''
    form.original_amount = debt ? String(Number.parseFloat(debt.original_amount)) : ''
    form.credit_limit = debt?.credit_limit ? String(Number.parseFloat(debt.credit_limit)) : ''
    form.interest_rate = debt?.interest_rate ? String(Number.parseFloat(debt.interest_rate)) : ''
    form.minimum_payment = debt ? String(Number.parseFloat(debt.minimum_payment)) : ''
    form.planned_payment = debt ? String(Number.parseFloat(debt.planned_payment)) : ''
    form.due_day = debt?.due_day ? String(debt.due_day) : ''
    form.remaining_installments = debt?.remaining_installments != null
      ? String(debt.remaining_installments)
      : ''
    form.early_settlement_amount = debt?.early_settlement_amount
      ? String(Number.parseFloat(debt.early_settlement_amount))
      : ''
  },
)

function decimal(value: string): string | null {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed.toFixed(2) : null
}

async function submit(): Promise<void> {
  Object.keys(errors).forEach((key) => delete errors[key])

  const payload: Record<string, unknown> = {
    name: form.name.trim(),
    type: form.type,
    current_balance: decimal(form.current_balance) ?? '0.00',
    original_amount: decimal(form.original_amount),
    credit_limit: form.type === 'credit_card' ? decimal(form.credit_limit) : null,
    interest_rate: Number.isFinite(Number.parseFloat(form.interest_rate))
      ? Number.parseFloat(form.interest_rate)
      : null,
    minimum_payment: decimal(form.minimum_payment) ?? '0.00',
    planned_payment: decimal(form.planned_payment) ?? '0.00',
    due_day: Number(form.due_day) || null,
    remaining_installments:
      form.type === 'installment' && form.remaining_installments !== ''
        ? Number(form.remaining_installments)
        : null,
    early_settlement_amount: decimal(form.early_settlement_amount),
  }

  if (form.type === 'installment' && payload.planned_payment) {
    payload.installment_amount = payload.planned_payment
  }

  try {
    if (isEditing.value && props.debt) {
      await debts.update(props.debt.id, payload)
      ui.success('Debt updated')
    } else {
      await debts.create(payload)
      ui.success('Debt added')
    }
    emit('saved')
    emit('close')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      Object.entries(error.errors).forEach(([field, messages]) => {
        errors[field] = messages[0] ?? ''
      })
    } else if (error instanceof ApiError) {
      ui.error('Could not save this debt', error.message)
    }
  }
}
</script>

<template>
  <BottomSheet
    :open="open"
    :title="isEditing ? 'Edit debt' : 'Add a debt'"
    :busy="debts.saving"
    @close="emit('close')"
  >
    <form class="space-y-4 pb-2" @submit.prevent="submit">
      <TextField v-model="form.name" label="Name" placeholder="Credit Card" required :error="errors.name" data-autofocus />

      <SelectField v-model="form.type" :options="TYPE_OPTIONS" label="Type" :error="errors.type" />

      <MoneyInput v-model="form.current_balance" label="Current balance" :error="errors.current_balance" />

      <MoneyInput
        v-model="form.original_amount"
        label="Original amount"
        hint="Progress is measured against this. Leave blank to use the current balance."
        :error="errors.original_amount"
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <MoneyInput v-model="form.minimum_payment" label="Minimum payment" :error="errors.minimum_payment" />
        <MoneyInput v-model="form.planned_payment" label="Planned payment" :error="errors.planned_payment" />
      </div>

      <MoneyInput
        v-if="form.type === 'credit_card'"
        v-model="form.credit_limit"
        label="Credit limit"
        :error="errors.credit_limit"
      />

      <!-- Make the per-card linkage discoverable at the point of creation. -->
      <p
        v-if="form.type === 'credit_card' && !isEditing"
        class="flex items-start gap-2 rounded-[var(--radius-field)] bg-info-soft p-3 text-sm text-ink-muted"
      >
        <CreditCard class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
        <span>
          This card gets its own payment method, so spending you log against it
          is added to <strong class="font-semibold text-ink">this card only</strong>.
        </span>
      </p>

      <template v-if="form.type === 'installment'">
        <TextField
          v-model="form.remaining_installments"
          label="Installments remaining"
          type="number"
          inputmode="numeric"
          min="0"
          :error="errors.remaining_installments"
        />
        <MoneyInput
          v-model="form.early_settlement_amount"
          label="Early settlement amount"
          hint="If your provider has quoted one. It is usually less than the scheduled total."
          :error="errors.early_settlement_amount"
        />
      </template>

      <div class="grid gap-4 sm:grid-cols-2">
        <TextField
          v-model="form.interest_rate"
          label="Annual interest rate (%)"
          type="number"
          inputmode="decimal"
          min="0"
          hint="Leave blank for a no-interest estimate."
          :error="errors.interest_rate"
        />
        <TextField
          v-model="form.due_day"
          label="Payment due day"
          type="number"
          inputmode="numeric"
          min="1"
          max="31"
          :error="errors.due_day"
        />
      </div>

      <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Save</button>
    </form>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="debts.saving || form.name.trim() === ''"
        @click="submit"
      >
        {{ debts.saving ? 'Saving…' : isEditing ? 'Save changes' : 'Add debt' }}
      </button>
    </template>
  </BottomSheet>
</template>
