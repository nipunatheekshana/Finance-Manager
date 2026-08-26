<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { CalendarClock, Check } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { useBudgetStore } from '@/stores/budget'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { formatDate, relativeDay } from '@/composables/useDates'
import type { UpcomingBill } from '@/types'

const props = defineProps<{ bill: UpcomingBill | null }>()
const emit = defineEmits<{ close: []; paid: [] }>()

const budget = useBudgetStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const amount = ref('')

const planned = computed(() => Number.parseFloat(props.bill?.amount ?? '0'))
const entered = computed(() => Number.parseFloat(amount.value))
const differs = computed(
  () => Number.isFinite(entered.value) && Math.abs(entered.value - planned.value) > 0.005,
)

const when = computed(() => {
  if (!props.bill?.date) return 'No due date'
  if (props.bill.is_overdue) return `Was due ${formatDate(props.bill.date)}`

  // "Today" and "In 3 days" both need lowering mid-sentence; a bare date does
  // not, and starts with a digit anyway.
  const relative = relativeDay(props.bill.date)

  return `Due ${relative.charAt(0).toLowerCase()}${relative.slice(1)}`
})

watch(
  () => props.bill,
  (bill) => {
    // The planned amount is what is usually paid; typing over it records what
    // the bill actually came to.
    amount.value = bill ? String(Number.parseFloat(bill.amount)) : ''
  },
)

async function markPaid(): Promise<void> {
  if (!props.bill) return

  const value = entered.value
  if (!Number.isFinite(value) || value <= 0) return

  try {
    // The dashboard knows the plan by id; the budget store holds the plan
    // itself, and may not have loaded it yet on this screen.
    if (!budget.plan) await budget.loadCurrent()

    await budget.updateFixedExpense(props.bill.id, {
      status: 'paid',
      ...(differs.value ? { actual_amount: value.toFixed(2) } : {}),
    })

    ui.success(`${props.bill.name} marked as paid`, 'It is off your list for this cycle.')

    await dashboard.refresh()
    emit('paid')
    emit('close')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not record that payment', error.message)
  }
}
</script>

<template>
  <BottomSheet
    :open="bill !== null"
    :title="bill ? bill.name : 'Bill'"
    :busy="budget.saving"
    @close="emit('close')"
  >
    <div v-if="bill" class="space-y-4 pb-2">
      <div class="flex items-center justify-between gap-3 rounded-[var(--radius-card)] bg-sunken p-4">
        <span class="flex items-center gap-2 text-sm" :class="bill.is_overdue ? 'text-over' : 'text-ink-muted'">
          <CalendarClock class="h-4 w-4 shrink-0" aria-hidden="true" />
          {{ when }}
        </span>
        <MoneyText :amount="bill.amount" size="lg" class="font-bold" />
      </div>

      <MoneyInput
        v-model="amount"
        label="Amount paid"
        hint="Change it if the bill came to something else."
        data-autofocus
      />

      <p v-if="differs" class="text-sm text-ink-muted">
        Recorded as
        <MoneyText :amount="entered.toFixed(2)" size="sm" class="font-semibold text-ink" />
        instead of the planned
        <MoneyText :amount="bill.amount" size="sm" />.
      </p>

      <!-- The money was taken out of income when the plan was built, so
           settling early is not an extra cost. -->
      <p class="text-xs text-ink-subtle">
        Paying early does not touch your spending budget — this money was set
        aside for the bill when you planned the cycle.
      </p>
    </div>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="budget.saving || !(entered > 0)"
        @click="markPaid"
      >
        <Check class="h-5 w-5" aria-hidden="true" />
        {{ budget.saving ? 'Recording…' : 'Mark as paid' }}
      </button>
    </template>
  </BottomSheet>
</template>
