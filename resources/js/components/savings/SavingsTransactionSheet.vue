<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import DatePicker from '@/components/common/DatePicker.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import SegmentedControl from '@/components/common/SegmentedControl.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { useSavingsStore } from '@/stores/savings'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { todayIso } from '@/composables/useDates'

const props = defineProps<{ goalId: number | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const savings = useSavingsStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const type = ref<'deposit' | 'withdrawal' | 'transfer'>('deposit')
const amount = ref('')
const transactionDate = ref(todayIso())
const description = ref('')
const toGoalId = ref<number | null>(null)
const errors = ref<Record<string, string>>({})

const TYPE_OPTIONS = [
  { value: 'deposit', label: 'Add' },
  { value: 'withdrawal', label: 'Withdraw' },
  { value: 'transfer', label: 'Transfer' },
]

const goal = computed(() => savings.goals.find((item) => item.id === props.goalId) ?? null)

const transferTargets = computed(() =>
  savings.activeGoals
    .filter((item) => item.id !== props.goalId)
    .map((item) => ({ value: item.id, label: item.name })),
)

watch(
  () => props.goalId,
  (id) => {
    if (id === null) return
    type.value = 'deposit'
    amount.value = goal.value ? String(Number.parseFloat(goal.value.monthly_target)) : ''
    transactionDate.value = todayIso()
    description.value = ''
    toGoalId.value = null
    errors.value = {}
  },
)

async function submit(): Promise<void> {
  if (props.goalId === null) return

  const value = Number.parseFloat(amount.value)
  if (!Number.isFinite(value) || value <= 0) {
    errors.value = { amount: 'Enter an amount greater than zero.' }
    return
  }

  if (type.value === 'transfer' && toGoalId.value === null) {
    errors.value = { to_goal_id: 'Choose which goal to transfer into.' }
    return
  }

  try {
    await savings.addTransaction(props.goalId, {
      type: type.value,
      amount: value.toFixed(2),
      transaction_date: transactionDate.value,
      description: description.value.trim() || undefined,
      ...(type.value === 'transfer' && toGoalId.value !== null ? { to_goal_id: toGoalId.value } : {}),
    })

    ui.success(
      type.value === 'deposit' ? 'Money added' : type.value === 'withdrawal' ? 'Money withdrawn' : 'Transfer complete',
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
      ui.error('Could not save that transaction', error.message)
    }
  }
}
</script>

<template>
  <BottomSheet
    :open="goalId !== null"
    :title="goal?.name ?? 'Savings'"
    :busy="savings.saving"
    @close="emit('close')"
  >
    <div v-if="goal" class="space-y-4 pb-2">
      <div class="rounded-[var(--radius-card)] bg-sunken p-4 text-center">
        <p class="text-sm text-ink-muted">Currently saved</p>
        <MoneyText :amount="goal.current_amount" size="2xl" class="mt-0.5 block font-bold" />
        <p class="mt-0.5 text-xs text-ink-subtle">
          of <MoneyText :amount="goal.target_amount" size="xs" class="font-semibold" />
          · {{ goal.percentage.toFixed(0) }}%
        </p>
      </div>

      <SegmentedControl v-model="type" :options="TYPE_OPTIONS" aria-label="Transaction type" />

      <MoneyInput v-model="amount" large :error="errors.amount" data-autofocus />

      <SelectField
        v-if="type === 'transfer'"
        v-model="toGoalId"
        :options="transferTargets"
        label="Transfer into"
        placeholder="Choose a goal"
        :error="errors.to_goal_id"
      />

      <DatePicker v-model="transactionDate" label="Date" quick-picks :error="errors.transaction_date" />

      <TextField v-model="description" label="Note" placeholder="Optional" :error="errors.description" />
    </div>

    <template #footer>
      <button type="button" class="btn btn-primary w-full !text-base" :disabled="savings.saving" @click="submit">
        {{ savings.saving ? 'Saving…' : type === 'deposit' ? 'Add money' : type === 'withdrawal' ? 'Withdraw' : 'Transfer' }}
      </button>
    </template>
  </BottomSheet>
</template>
