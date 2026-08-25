<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { CreditCard, Plus } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { api, ApiError } from '@/services/api'
import { useExpenseStore } from '@/stores/expenses'
import { useDebtStore } from '@/stores/debts'
import { useUiStore } from '@/stores/ui'
import type { PaymentMethod } from '@/types'

const expenses = useExpenseStore()
const debts = useDebtStore()
const ui = useUiStore()

const loading = ref(true)
const sheetOpen = ref(false)
const editing = ref<PaymentMethod | null>(null)
const saving = ref(false)
const deleting = ref(false)
const confirmDelete = ref<PaymentMethod | null>(null)
const errors = reactive<Record<string, string>>({})

const TYPE_OPTIONS = [
  { value: 'cash', label: 'Cash' },
  { value: 'bank', label: 'Bank account' },
  { value: 'debit_card', label: 'Debit card' },
  { value: 'credit_card', label: 'Credit card' },
  { value: 'bnpl', label: 'Buy now, pay later' },
  { value: 'other', label: 'Other' },
]

const form = reactive({
  name: '',
  type: 'cash',
  icon: 'wallet',
  debt_id: null as number | null,
})

const debtOptions = computed(() =>
  debts.items.map((debt) => ({ value: debt.id, label: debt.name })),
)

function open(method: PaymentMethod | null): void {
  editing.value = method
  Object.keys(errors).forEach((key) => delete errors[key])

  form.name = method?.name ?? ''
  form.type = method?.type ?? 'cash'
  form.icon = method?.icon ?? 'wallet'
  form.debt_id = method?.debt_id ?? null

  sheetOpen.value = true
}

async function submit(): Promise<void> {
  saving.value = true
  Object.keys(errors).forEach((key) => delete errors[key])

  const payload = {
    name: form.name.trim(),
    type: form.type,
    icon: form.icon,
    debt_id: form.debt_id,
  }

  try {
    if (editing.value) {
      await api.put(`/payment-methods/${editing.value.id}`, payload)
      ui.success('Payment method updated')
    } else {
      await api.post('/payment-methods', payload)
      ui.success('Payment method added')
    }

    await expenses.loadReference(true)
    sheetOpen.value = false
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      Object.entries(error.errors).forEach(([field, messages]) => {
        errors[field] = messages[0] ?? ''
      })
    } else if (error instanceof ApiError) {
      ui.error('Could not save that payment method', error.message)
    }
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!confirmDelete.value) return
  deleting.value = true

  try {
    const response = await api.delete<{ message: string }>(`/payment-methods/${confirmDelete.value.id}`)
    ui.success(response.message)
    await expenses.loadReference(true)
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not remove that payment method', error.message)
  } finally {
    deleting.value = false
    confirmDelete.value = null
  }
}

onMounted(async () => {
  await Promise.all([expenses.loadReference(true), debts.fetch()])
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader
      title="Payment methods"
      subtitle="Link a card to a debt so spending on it raises that balance."
      back-to="/settings"
    >
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="open(null)">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span class="sr-only">Add payment method</span>
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="loading" variant="list" :rows="5" />

    <EmptyState
      v-else-if="expenses.paymentMethods.length === 0"
      :icon="CreditCard"
      title="No payment methods"
      description="Add how you pay for things."
      action-label="Add payment method"
      @action="open(null)"
    />

    <ul v-else class="card divide-y divide-line px-4">
      <li v-for="method in expenses.paymentMethods" :key="method.id" class="flex items-center gap-3 py-3">
        <button type="button" class="min-w-0 flex-1 text-left" @click="open(method)">
          <p class="truncate text-sm font-medium text-ink">
            {{ method.name }}
            <span v-if="method.increases_debt" class="badge ml-1 bg-warn-soft text-warn">Adds to debt</span>
            <span v-if="!method.active" class="badge ml-1 bg-sunken text-ink-subtle">Hidden</span>
          </p>
          <p class="text-xs capitalize text-ink-subtle">{{ method.type.replace(/_/g, ' ') }}</p>
        </button>

        <button
          type="button"
          class="btn btn-ghost !min-h-11 !px-3 !text-xs text-over"
          @click="confirmDelete = method"
        >
          Remove
        </button>
      </li>
    </ul>

    <BottomSheet
      :open="sheetOpen"
      :title="editing ? 'Edit payment method' : 'New payment method'"
      :busy="saving"
      @close="sheetOpen = false"
    >
      <div class="space-y-4 pb-2">
        <TextField v-model="form.name" label="Name" required :error="errors.name" data-autofocus />

        <SelectField v-model="form.type" :options="TYPE_OPTIONS" label="Type" :error="errors.type" />

        <SelectField
          v-model="form.debt_id"
          :options="debtOptions"
          label="Linked debt"
          placeholder="Not linked"
          hint="When linked, spending on this method increases that debt's balance."
          :error="errors.debt_id"
        />
      </div>

      <template #footer>
        <button
          type="button"
          class="btn btn-primary w-full !text-base"
          :disabled="saving || form.name.trim() === ''"
          @click="submit"
        >
          {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add payment method' }}
        </button>
      </template>
    </BottomSheet>

    <ConfirmDialog
      :open="confirmDelete !== null"
      title="Remove this payment method?"
      message="Methods with expenses are hidden instead of deleted, so your history stays intact."
      confirm-label="Remove"
      destructive
      :busy="deleting"
      @confirm="remove"
      @cancel="confirmDelete = null"
    />
  </div>
</template>
