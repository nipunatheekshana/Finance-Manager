<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Banknote, CreditCard, PiggyBank, Undo2, Wallet } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { api, ApiError } from '@/services/api'
import { useBudgetStore } from '@/stores/budget'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { formatLKR } from '@/composables/useCurrency'
import type { CommitmentOption, CommitmentOptions, CommitmentSource, PendingDebt } from '@/types'

const props = defineProps<{ planId: number | null; debt: PendingDebt | null }>()
const emit = defineEmits<{ close: []; added: [] }>()

const budget = useBudgetStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const amount = ref('')
const source = ref<CommitmentSource | null>(null)
const options = ref<CommitmentOptions | null>(null)
const loading = ref(false)
const saving = ref(false)

const ICONS: Record<CommitmentSource, unknown> = {
  spending: Wallet,
  buffer: Banknote,
  savings: PiggyBank,
  savings_withdrawal: Undo2,
  debts: CreditCard,
}

const canSubmit = computed(
  () => source.value !== null && Number.parseFloat(amount.value) > 0 && !saving.value,
)

/** The one figure each choice changes, spelled out before it is taken. */
function effect(option: CommitmentOption): { label: string; value: string } | null {
  if (option.resulting_spending_budget !== undefined) {
    return { label: 'Left to spend this cycle', value: option.resulting_spending_budget }
  }
  if (option.resulting_buffer !== undefined) {
    return { label: 'Buffer left', value: option.resulting_buffer }
  }
  if (option.resulting_savings !== undefined) {
    return { label: 'Savings this cycle', value: option.resulting_savings }
  }
  return null
}

async function loadOptions(): Promise<void> {
  if (props.planId === null || props.debt === null) return

  loading.value = true
  try {
    const response = await api.get<{ data: CommitmentOptions }>(
      `/monthly-plans/${props.planId}/pending-debts/${props.debt.debt_id}/options`,
      { params: { amount: Number.parseFloat(amount.value || '0').toFixed(2) } },
    )
    options.value = response.data
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not work out the options', error.message)
  } finally {
    loading.value = false
  }
}

watch(
  () => props.debt,
  (debt) => {
    source.value = null
    options.value = null
    amount.value = debt ? String(Number.parseFloat(debt.suggested_amount)) : ''
    if (debt) void loadOptions()
  },
)

// Changing the amount changes every figure on screen, so re-ask.
let timer: ReturnType<typeof setTimeout> | undefined
watch(amount, () => {
  clearTimeout(timer)
  if (props.debt === null) return
  timer = setTimeout(() => void loadOptions(), 400)
})

async function submit(): Promise<void> {
  if (props.planId === null || props.debt === null || source.value === null) return

  saving.value = true
  try {
    await api.post(`/monthly-plans/${props.planId}/pending-debts/${props.debt.debt_id}`, {
      amount: Number.parseFloat(amount.value).toFixed(2),
      source: source.value,
    })

    ui.success(
      `${props.debt.name} added to this cycle`,
      `${formatLKR(amount.value)} taken from ${options.value?.options.find((row) => row.source === source.value)?.label.toLowerCase() ?? 'your plan'}.`,
    )

    await Promise.allSettled([budget.loadCurrent(), budget.loadWeeks(), dashboard.refresh()])
    emit('added')
    emit('close')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not add it to the plan', error.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BottomSheet
    :open="debt !== null"
    :title="debt ? `Add ${debt.name} to this cycle` : 'Add to this cycle'"
    description="This plan is already running, so the money has to come from somewhere. Choose what gives."
    :busy="saving"
    @close="emit('close')"
  >
    <div v-if="debt" class="space-y-5 pb-2">
      <MoneyInput
        v-model="amount"
        label="Payment this cycle"
        :hint="`Minimum is ${formatLKR(debt.minimum_payment)}.`"
        data-autofocus
      />

      <div v-if="loading" class="py-4 text-center text-sm text-ink-muted">Working it out…</div>

      <div v-else-if="options">
        <p class="label">Where should it come from?</p>

        <div class="space-y-2.5" role="radiogroup" aria-label="Where the money comes from">
          <button
            v-for="option in options.options"
            :key="option.source"
            type="button"
            role="radio"
            :aria-checked="source === option.source"
            :disabled="!option.available"
            class="w-full rounded-[var(--radius-card)] border p-3.5 text-left transition"
            :class="
              !option.available
                ? 'border-line bg-sunken opacity-60'
                : source === option.source
                  ? 'border-brand bg-brand-soft'
                  : 'border-line bg-raised hover:border-ink-subtle'
            "
            @click="source = option.source"
          >
            <div class="flex items-start gap-3">
              <component
                :is="ICONS[option.source]"
                class="mt-0.5 h-5 w-5 shrink-0"
                :class="source === option.source ? 'text-brand' : 'text-ink-subtle'"
                aria-hidden="true"
              />

              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink">{{ option.label }}</p>
                <p class="mt-0.5 text-sm text-ink-muted">
                  {{ option.available ? option.description : option.unavailable_reason }}
                </p>

                <p
                  v-if="option.available && effect(option)"
                  class="mt-1 text-sm text-ink-muted"
                >
                  {{ effect(option)!.label }}:
                  <MoneyText :amount="effect(option)!.value" size="sm" class="font-bold text-ink" />
                </p>

                <p
                  v-if="option.available && option.source === 'spending' && option.weeks_affected"
                  class="mt-0.5 text-xs text-ink-subtle"
                >
                  Spread across the {{ option.weeks_affected }}
                  {{ option.weeks_affected === 1 ? 'week' : 'weeks' }} you have left.
                </p>
              </div>
            </div>
          </button>
        </div>
      </div>
    </div>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="!canSubmit"
        @click="submit"
      >
        {{ saving ? 'Adding…' : 'Add to this cycle' }}
      </button>
    </template>
  </BottomSheet>
</template>
