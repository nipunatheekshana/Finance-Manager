<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ArrowRight, Banknote, CreditCard, PiggyBank, Split, Wallet } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import SelectField from '@/components/common/SelectField.vue'
import { useBudgetStore } from '@/stores/budget'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import { amountToNumber, formatLKR } from '@/composables/useCurrency'
import type { CycleSurplusOptions, SurplusActionType, SurplusAllocation } from '@/types'

const props = defineProps<{ planId: number | null }>()
const emit = defineEmits<{ close: []; resolved: [] }>()

const budget = useBudgetStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const options = ref<CycleSurplusOptions | null>(null)
const loading = ref(false)
const choice = ref<SurplusActionType | 'split' | null>(null)

const debtId = ref<number | null>(null)
const goalId = ref<number | null>(null)

/** Split mode: an amount per destination, all optional. */
const splitDebt = ref('')
const splitSavings = ref('')
const splitCarry = ref('')

const total = computed(() => amountToNumber(options.value?.total ?? '0'))

const splitTotal = computed(
  () =>
    (Number.parseFloat(splitDebt.value) || 0) +
    (Number.parseFloat(splitSavings.value) || 0) +
    (Number.parseFloat(splitCarry.value) || 0),
)

const splitRemaining = computed(() => total.value - splitTotal.value)

const canSubmit = computed(() => {
  if (choice.value === null || budget.saving) return false
  if (choice.value === 'debt') return debtId.value !== null
  if (choice.value === 'savings') return goalId.value !== null
  if (choice.value === 'split') return splitTotal.value > 0 && splitRemaining.value >= -0.005
  return true
})

const selectedDebt = computed(() =>
  options.value?.debts.find((row) => row.debt_id === debtId.value) ?? null,
)

const selectedGoal = computed(() =>
  options.value?.savings_goals.find((row) => row.savings_goal_id === goalId.value) ?? null,
)

watch(
  () => props.planId,
  async (id) => {
    options.value = null
    choice.value = null
    debtId.value = null
    goalId.value = null
    splitDebt.value = ''
    splitSavings.value = ''
    splitCarry.value = ''

    if (id === null) return

    loading.value = true
    try {
      const loaded = await budget.surplusOptions(id)
      options.value = loaded

      // Pre-select the obvious destinations so one tap is enough.
      debtId.value = loaded.debts[0]?.debt_id ?? null
      goalId.value = loaded.savings_goals[0]?.savings_goal_id ?? null
    } catch (error) {
      if (error instanceof ApiError) ui.error('Could not load your options', error.message)
    } finally {
      loading.value = false
    }
  },
)

function buildAllocations(): SurplusAllocation[] {
  const amount = (options.value?.total ?? '0')

  switch (choice.value) {
    case 'debt':
      return debtId.value === null ? [] : [{ type: 'debt', amount, debt_id: debtId.value }]

    case 'savings':
      return goalId.value === null ? [] : [{ type: 'savings', amount, savings_goal_id: goalId.value }]

    case 'carry_forward':
      return [{ type: 'carry_forward', amount }]

    case 'split': {
      const rows: SurplusAllocation[] = []
      const debtAmount = Number.parseFloat(splitDebt.value) || 0
      const savingsAmount = Number.parseFloat(splitSavings.value) || 0
      const carryAmount = Number.parseFloat(splitCarry.value) || 0

      if (debtAmount > 0 && debtId.value !== null) {
        rows.push({ type: 'debt', amount: debtAmount.toFixed(2), debt_id: debtId.value })
      }
      if (savingsAmount > 0 && goalId.value !== null) {
        rows.push({ type: 'savings', amount: savingsAmount.toFixed(2), savings_goal_id: goalId.value })
      }
      if (carryAmount > 0) {
        rows.push({ type: 'carry_forward', amount: carryAmount.toFixed(2) })
      }
      return rows
    }

    // Leave it in the bank: nothing to move.
    default:
      return []
  }
}

async function submit(): Promise<void> {
  if (props.planId === null || !canSubmit.value) return

  try {
    const result = await budget.resolveSurplus(props.planId, buildAllocations())

    ui.success(
      amountToNumber(result.allocated) > 0
        ? `${formatLKR(result.allocated)} put to work`
        : 'Left in your bank account',
      result.applied.map((row) => row.label).join(' · ') || 'Your plan is unchanged.',
    )

    await dashboard.refresh()
    emit('resolved')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not settle the leftover', error.message)
  }
}

const CHOICES = [
  { key: 'debt', icon: CreditCard, label: 'Pay down a debt' },
  { key: 'savings', icon: PiggyBank, label: 'Move to savings' },
  { key: 'carry_forward', icon: ArrowRight, label: "Add to next month's spending" },
  { key: 'split', icon: Split, label: 'Split it' },
  { key: 'leave_in_bank', icon: Banknote, label: 'Leave it in the bank' },
] as const
</script>

<template>
  <BottomSheet
    :open="planId !== null"
    title="Last cycle left over"
    description="This money is still in your account. Decide where it should go."
    :busy="budget.saving"
    @close="emit('close')"
  >
    <div v-if="loading" class="py-8 text-center text-sm text-ink-muted">Working it out…</div>

    <div v-else-if="options" class="space-y-5 pb-2">
      <!-- The arithmetic, shown plainly. -->
      <div class="rounded-[var(--radius-card)] bg-sunken p-4">
        <p class="eyebrow">{{ options.plan_label }}</p>

        <dl class="mt-3 space-y-2">
          <div class="flex items-baseline justify-between gap-3">
            <dt class="text-sm text-ink-muted">Unspent budget</dt>
            <dd><MoneyText :amount="options.unspent_budget" size="sm" class="font-semibold" /></dd>
          </div>
          <div class="flex items-baseline justify-between gap-3">
            <dt class="text-sm text-ink-muted">Unused buffer</dt>
            <dd><MoneyText :amount="options.unused_buffer" size="sm" class="font-semibold" /></dd>
          </div>
          <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2">
            <dt class="text-sm font-bold text-ink">Total</dt>
            <dd><MoneyText :amount="options.total" size="xl" class="font-bold" /></dd>
          </div>
        </dl>
      </div>

      <div>
        <p class="label">What should happen to it?</p>

        <div class="space-y-2.5" role="radiogroup" aria-label="What should happen to the leftover?">
          <button
            v-for="option in CHOICES"
            :key="option.key"
            type="button"
            role="radio"
            :aria-checked="choice === option.key"
            class="w-full rounded-[var(--radius-card)] border p-3.5 text-left transition"
            :class="
              choice === option.key
                ? 'border-brand bg-brand-soft'
                : 'border-line bg-raised hover:border-ink-subtle'
            "
            @click="choice = option.key"
          >
            <div class="flex items-start gap-3">
              <component
                :is="option.icon"
                class="mt-0.5 h-5 w-5 shrink-0"
                :class="choice === option.key ? 'text-brand' : 'text-ink-subtle'"
                aria-hidden="true"
              />

              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink">{{ option.label }}</p>

                <!-- Each choice states its exact effect before it is taken. -->
                <p
                  v-if="option.key === 'debt' && selectedDebt"
                  class="mt-0.5 text-sm text-ink-muted"
                >
                  {{ selectedDebt.name }}:
                  <MoneyText :amount="selectedDebt.balance" size="sm" />
                  →
                  <MoneyText :amount="selectedDebt.resulting_balance" size="sm" class="font-bold text-ink" />
                </p>

                <p
                  v-else-if="option.key === 'savings' && selectedGoal"
                  class="mt-0.5 text-sm text-ink-muted"
                >
                  {{ selectedGoal.name }}:
                  <MoneyText :amount="selectedGoal.current_amount" size="sm" />
                  →
                  <MoneyText :amount="selectedGoal.resulting_amount" size="sm" class="font-bold text-ink" />
                </p>

                <p v-else-if="option.key === 'carry_forward'" class="mt-0.5 text-sm text-ink-muted">
                  {{ options.carry_forward.next_label }} opens with
                  <MoneyText
                    :amount="options.carry_forward.resulting_opening_balance"
                    size="sm"
                    class="font-bold text-ink"
                  />
                  on top of your salary.
                </p>

                <p v-else-if="option.key === 'split'" class="mt-0.5 text-sm text-ink-muted">
                  Send part of it to each.
                </p>

                <p v-else class="mt-0.5 text-sm text-ink-muted">
                  Nothing changes. We will stop asking about this cycle.
                </p>
              </div>
            </div>
          </button>
        </div>
      </div>

      <!-- Which debt / which goal, when there is more than one. -->
      <SelectField
        v-if="choice === 'debt' && options.debts.length > 1"
        v-model="debtId"
        :options="options.debts.map((row) => ({ value: row.debt_id, label: row.name }))"
        label="Which debt?"
      />

      <SelectField
        v-if="choice === 'savings' && options.savings_goals.length > 1"
        v-model="goalId"
        :options="options.savings_goals.map((row) => ({ value: row.savings_goal_id, label: row.name }))"
        label="Which goal?"
      />

      <!-- Split mode -->
      <div v-if="choice === 'split'" class="space-y-4 rounded-[var(--radius-card)] border border-line p-4">
        <SelectField
          v-if="options.debts.length"
          v-model="debtId"
          :options="options.debts.map((row) => ({ value: row.debt_id, label: row.name }))"
          label="Debt"
        />
        <MoneyInput v-if="options.debts.length" v-model="splitDebt" label="To this debt" />

        <SelectField
          v-if="options.savings_goals.length"
          v-model="goalId"
          :options="options.savings_goals.map((row) => ({ value: row.savings_goal_id, label: row.name }))"
          label="Savings goal"
        />
        <MoneyInput v-if="options.savings_goals.length" v-model="splitSavings" label="To this goal" />

        <MoneyInput v-model="splitCarry" :label="`To ${options.carry_forward.next_label} spending`" />

        <div
          class="flex items-baseline justify-between rounded-[var(--radius-field)] px-3 py-2.5"
          :class="splitRemaining < -0.005 ? 'bg-over-soft' : 'bg-sunken'"
        >
          <span class="text-sm" :class="splitRemaining < -0.005 ? 'text-over' : 'text-ink-muted'">
            {{ splitRemaining < -0.005 ? 'Over the leftover by' : 'Still to allocate' }}
          </span>
          <MoneyText
            :amount="Math.abs(splitRemaining).toFixed(2)"
            size="sm"
            class="font-bold"
            :class="splitRemaining < -0.005 ? 'text-over' : 'text-ink'"
          />
        </div>

        <p v-if="splitRemaining > 0.005" class="text-xs text-ink-subtle">
          Anything you do not allocate simply stays in your bank account.
        </p>
      </div>

      <p class="flex items-start gap-1.5 text-xs text-ink-subtle">
        <Wallet class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        Paying a debt or adding to savings records a real transaction, so your
        balances and history stay accurate.
      </p>
    </div>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="!canSubmit"
        @click="submit"
      >
        {{ budget.saving ? 'Applying…' : 'Confirm' }}
      </button>
    </template>
  </BottomSheet>
</template>
