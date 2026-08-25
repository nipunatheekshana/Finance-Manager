<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import TextField from '@/components/common/TextField.vue'
import SelectField from '@/components/common/SelectField.vue'
import { useSavingsStore } from '@/stores/savings'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { AllocationType, SavingsGoal } from '@/types'

const props = defineProps<{ open: boolean; goal?: SavingsGoal | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const savings = useSavingsStore()
const ui = useUiStore()

const ALLOCATION_OPTIONS: Array<{ value: AllocationType; label: string }> = [
  { value: 'fixed', label: 'A fixed amount each month' },
  { value: 'salary_percentage', label: 'A percentage of my salary' },
  { value: 'extra_percentage', label: 'A percentage of extra income' },
  { value: 'custom', label: 'A custom amount' },
]

const PRIORITY_OPTIONS = [
  { value: '1', label: '1 — Highest' },
  { value: '2', label: '2' },
  { value: '3', label: '3 — Normal' },
  { value: '4', label: '4' },
  { value: '5', label: '5 — Lowest' },
]

const form = reactive({
  name: '',
  target_amount: '',
  current_amount: '',
  monthly_target: '',
  allocation_type: 'fixed' as AllocationType,
  allocation_value: '',
  target_date: '',
  priority: '3',
})

const errors = reactive<Record<string, string>>({})

const isEditing = computed(() => Boolean(props.goal))

const isPercentage = computed(
  () => form.allocation_type === 'salary_percentage' || form.allocation_type === 'extra_percentage',
)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    Object.keys(errors).forEach((key) => delete errors[key])

    const goal = props.goal
    form.name = goal?.name ?? ''
    form.target_amount = goal ? String(Number.parseFloat(goal.target_amount)) : ''
    form.current_amount = goal ? String(Number.parseFloat(goal.current_amount)) : ''
    form.monthly_target = goal ? String(Number.parseFloat(goal.monthly_target)) : ''
    form.allocation_type = goal?.allocation_type ?? 'fixed'
    form.allocation_value = goal ? String(Number.parseFloat(goal.allocation_value)) : ''
    form.target_date = goal?.target_date ?? ''
    form.priority = String(goal?.priority ?? 3)
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
    target_amount: decimal(form.target_amount) ?? '0.00',
    monthly_target: decimal(form.monthly_target) ?? '0.00',
    allocation_type: form.allocation_type,
    allocation_value: isPercentage.value
      ? (decimal(form.allocation_value) ?? '0.00')
      : (decimal(form.monthly_target) ?? '0.00'),
    target_date: form.target_date || null,
    priority: Number(form.priority),
  }

  // The starting balance is only set when the goal is created.
  if (!isEditing.value) {
    payload.current_amount = decimal(form.current_amount) ?? '0.00'
  }

  try {
    if (isEditing.value && props.goal) {
      await savings.update(props.goal.id, payload)
      ui.success('Goal updated')
    } else {
      await savings.create(payload)
      ui.success('Goal created')
    }
    emit('saved')
    emit('close')
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      Object.entries(error.errors).forEach(([field, messages]) => {
        errors[field] = messages[0] ?? ''
      })
    } else if (error instanceof ApiError) {
      ui.error('Could not save this goal', error.message)
    }
  }
}
</script>

<template>
  <BottomSheet
    :open="open"
    :title="isEditing ? 'Edit goal' : 'New savings goal'"
    :busy="savings.saving"
    @close="emit('close')"
  >
    <form class="space-y-4 pb-2" @submit.prevent="submit">
      <TextField
        v-model="form.name"
        label="What are you saving for?"
        placeholder="Emergency Fund"
        required
        :error="errors.name"
        data-autofocus
      />

      <MoneyInput v-model="form.target_amount" label="Target amount" :error="errors.target_amount" />

      <MoneyInput
        v-if="!isEditing"
        v-model="form.current_amount"
        label="Already saved"
        hint="Leave at zero if you are starting fresh."
        :error="errors.current_amount"
      />

      <MoneyInput v-model="form.monthly_target" label="Monthly contribution" :error="errors.monthly_target" />

      <SelectField
        v-model="form.allocation_type"
        :options="ALLOCATION_OPTIONS"
        label="How should this be funded?"
        :error="errors.allocation_type"
      />

      <TextField
        v-if="isPercentage"
        v-model="form.allocation_value"
        label="Percentage"
        type="number"
        inputmode="decimal"
        min="0"
        max="100"
        hint="For example, 10 for 10%."
        :error="errors.allocation_value"
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <TextField v-model="form.target_date" label="Target date" type="date" :error="errors.target_date" />
        <SelectField v-model="form.priority" :options="PRIORITY_OPTIONS" label="Priority" :error="errors.priority" />
      </div>

      <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Save</button>
    </form>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="savings.saving || form.name.trim() === ''"
        @click="submit"
      >
        {{ savings.saving ? 'Saving…' : isEditing ? 'Save changes' : 'Create goal' }}
      </button>
    </template>
  </BottomSheet>
</template>
