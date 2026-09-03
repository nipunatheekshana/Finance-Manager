<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ArrowLeftRight, Banknote, PiggyBank, Undo2, Wallet } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import SelectField from '@/components/common/SelectField.vue'
import { api, ApiError } from '@/services/api'
import { useBudgetStore } from '@/stores/budget'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { formatLKR } from '@/composables/useCurrency'
import type { OverspentAllowance, TopUpOption, TopUpOptions, TopUpSource } from '@/types'

const props = defineProps<{ planId: number | null; allowance: OverspentAllowance | null }>()
const emit = defineEmits<{ close: []; applied: [] }>()

const budget = useBudgetStore()
const dashboard = useDashboardStore()
const ui = useUiStore()

const amount = ref('')
const source = ref<TopUpSource | null>(null)
const fromCategory = ref<number | null>(null)
const options = ref<TopUpOptions | null>(null)
const loading = ref(false)
const saving = ref(false)

const ICONS: Record<TopUpSource, unknown> = {
  allowance: ArrowLeftRight,
  spending: Wallet,
  buffer: Banknote,
  savings: PiggyBank,
  savings_withdrawal: Undo2,
}

const canSubmit = computed(() => {
  if (source.value === null || saving.value) return false
  if (!(Number.parseFloat(amount.value) > 0)) return false
  if (source.value === 'allowance') return fromCategory.value !== null
  return true
})

/** The one figure a choice changes, spelled out before it is taken. */
function effect(option: TopUpOption): { label: string; value: string } | null {
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
  if (props.planId === null || props.allowance === null) return

  loading.value = true
  try {
    const response = await api.get<{ data: TopUpOptions }>(
      `/monthly-plans/${props.planId}/allowance-top-ups/${props.allowance.category_id}/options`,
      { params: { amount: Number.parseFloat(amount.value || '0').toFixed(2) } },
    )
    options.value = response.data
    fromCategory.value ??= response.data.other_allowances[0]?.category_id ?? null
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not work out the options', error.message)
  } finally {
    loading.value = false
  }
}

watch(
  () => props.allowance,
  (row) => {
    source.value = null
    fromCategory.value = null
    options.value = null
    amount.value = row ? String(Number.parseFloat(row.over_by)) : ''
    if (row) void loadOptions()
  },
)

let timer: ReturnType<typeof setTimeout> | undefined
watch(amount, () => {
  clearTimeout(timer)
  if (props.allowance === null) return
  timer = setTimeout(() => void loadOptions(), 400)
})

async function submit(): Promise<void> {
  if (props.planId === null || props.allowance === null || source.value === null) return

  saving.value = true
  try {
    await api.post(
      `/monthly-plans/${props.planId}/allowance-top-ups/${props.allowance.category_id}`,
      {
        amount: Number.parseFloat(amount.value).toFixed(2),
        source: source.value,
        from_category_id: source.value === 'allowance' ? fromCategory.value : undefined,
      },
    )

    ui.success(
      `${props.allowance.name} topped up`,
      `${formatLKR(amount.value)} moved across. Your plan still adds up.`,
    )

    await Promise.allSettled([budget.loadCurrent(), budget.loadAllowances(), dashboard.refresh()])
    emit('applied')
    emit('close')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not top it up', error.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BottomSheet
    :open="allowance !== null"
    :title="allowance ? `Top up ${allowance.name}` : 'Top up allowance'"
    description="The spending has already happened. Choose which pot pays for it."
    :busy="saving"
    @close="emit('close')"
  >
    <div v-if="allowance" class="space-y-5 pb-2">
      <div class="rounded-[var(--radius-card)] bg-over-soft p-4">
        <p class="text-sm text-ink">
          <MoneyText :amount="allowance.spent" size="sm" class="font-bold" /> spent against
          <MoneyText :amount="allowance.allocated" size="sm" /> reserved.
        </p>
        <p class="mt-1 text-sm font-semibold text-over">
          <MoneyText :amount="allowance.over_by" size="sm" class="font-bold" /> over the pot.
        </p>
      </div>

      <MoneyInput v-model="amount" label="Amount to move" data-autofocus />

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

                <p v-if="option.available && effect(option)" class="mt-1 text-sm text-ink-muted">
                  {{ effect(option)!.label }}:
                  <MoneyText :amount="effect(option)!.value" size="sm" class="font-bold text-ink" />
                </p>
              </div>
            </div>
          </button>
        </div>

        <!-- Which pot hands it over. -->
        <SelectField
          v-if="source === 'allowance' && options.other_allowances.length"
          v-model="fromCategory"
          class="mt-4"
          label="Move it from"
          :options="
            options.other_allowances.map((row) => ({
              value: row.category_id,
              label: `${row.name} — ${row.available} spare`,
            }))
          "
        />
      </div>
    </div>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="!canSubmit"
        @click="submit"
      >
        {{ saving ? 'Moving…' : 'Move the money' }}
      </button>
    </template>
  </BottomSheet>
</template>
