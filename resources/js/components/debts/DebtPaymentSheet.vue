<script setup lang="ts">
import { ref, watch } from 'vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import DatePicker from '@/components/common/DatePicker.vue'
import TextField from '@/components/common/TextField.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { useDebtStore } from '@/stores/debts'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { todayIso } from '@/composables/useDates'
import type { Debt } from '@/types'

const props = defineProps<{ open: boolean; debt: Debt | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const debts = useDebtStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const amount = ref('')
const paymentDate = ref(todayIso())
const notes = ref('')
const errors = ref<Record<string, string>>({})

watch(
  () => props.open,
  (open) => {
    if (!open) return
    // Default to the planned payment: usually exactly what the user is paying.
    amount.value = props.debt ? String(Number.parseFloat(props.debt.planned_payment)) : ''
    paymentDate.value = todayIso()
    notes.value = ''
    errors.value = {}
  },
)

async function submit(): Promise<void> {
  if (!props.debt) return

  const value = Number.parseFloat(amount.value)
  if (!Number.isFinite(value) || value <= 0) {
    errors.value = { amount: 'Enter an amount greater than zero.' }
    return
  }

  try {
    const result = await debts.recordPayment(props.debt.id, {
      amount: value.toFixed(2),
      payment_date: paymentDate.value,
      notes: notes.value.trim() || undefined,
    })

    ui.success(
      'Payment recorded',
      `${props.debt.name} is now at ${Number.parseFloat(result.debt.current_balance).toLocaleString('en-LK')}.`,
    )

    await dashboard.refresh()
    emit('saved')
    emit('close')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    } else if (error instanceof ApiError) {
      ui.error('Could not record that payment', error.message)
    }
  }
}
</script>

<template>
  <BottomSheet
    :open="open"
    :title="`Pay ${debt?.name ?? 'debt'}`"
    :busy="debts.saving"
    @close="emit('close')"
  >
    <div v-if="debt" class="space-y-4 pb-2">
      <div class="rounded-[var(--radius-card)] bg-sunken p-4 text-center">
        <p class="text-sm text-ink-muted">Current balance</p>
        <MoneyText :amount="debt.current_balance" size="2xl" class="mt-0.5 block font-bold" />
      </div>

      <MoneyInput v-model="amount" large label="Payment amount" :error="errors.amount" data-autofocus />

      <p v-if="Number.parseFloat(amount) > 0" class="text-sm text-ink-muted">
        New balance:
        <MoneyText
          :amount="Math.max(0, Number.parseFloat(debt.current_balance) - Number.parseFloat(amount)).toFixed(2)"
          size="sm"
          class="font-bold text-ink"
        />
        <span v-if="debt.remaining_installments !== null && debt.remaining_installments > 0">
          · {{ debt.remaining_installments - 1 }} installments left
        </span>
      </p>

      <DatePicker v-model="paymentDate" label="Payment date" quick-picks :error="errors.payment_date" />

      <TextField v-model="notes" label="Notes" placeholder="Optional" :error="errors.notes" />
    </div>

    <template #footer>
      <button type="button" class="btn btn-primary w-full !text-base" :disabled="debts.saving" @click="submit">
        {{ debts.saving ? 'Recording…' : 'Record payment' }}
      </button>
    </template>
  </BottomSheet>
</template>
