<script setup lang="ts">
import { ref, watch } from 'vue'
import { ArrowRight, Ban, PiggyBank, Shuffle, Wallet, Undo2 } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import SelectField from '@/components/common/SelectField.vue'
import { useBudgetStore } from '@/stores/budget'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { AdjustmentOptions, AdjustmentType } from '@/types'

const props = defineProps<{ weekId: number | null }>()
const emit = defineEmits<{ close: []; applied: [] }>()

const budget = useBudgetStore()
const ui = useUiStore()

const options = ref<AdjustmentOptions | null>(null)
const loading = ref(false)
const selected = ref<AdjustmentType | null>(null)
const categoryId = ref<number | null>(null)

const ICONS: Record<AdjustmentType, unknown> = {
  next_week: ArrowRight,
  buffer: Wallet,
  category: Shuffle,
  ignore: Ban,
}

watch(
  () => props.weekId,
  async (id) => {
    options.value = null
    selected.value = null
    categoryId.value = null

    if (id === null) return

    loading.value = true
    try {
      options.value = await budget.adjustmentOptions(id)
    } catch (error) {
      if (error instanceof ApiError) ui.error('Could not load your options', error.message)
    } finally {
      loading.value = false
    }
  },
)

/**
 * The week is over because a pot ran out. Top the pot up instead: the excess
 * leaves the week entirely, and the week's own money is never touched.
 */
function topUp(spill: { category_id: number; name: string; spilled: string }): void {
  if (!options.value) return

  ui.topUp = {
    planId: options.value.plan_id,
    allowance: {
      category_id: spill.category_id,
      name: spill.name,
      icon: 'circle',
      color: 'slate',
      allocated: '0.00',
      spent: '0.00',
      over_by: spill.spilled,
    },
  }
  emit('close')
}

async function apply(): Promise<void> {
  if (props.weekId === null || selected.value === null) return

  if (selected.value === 'category' && categoryId.value === null) {
    ui.warning('Choose a category', 'Pick which category budget to reduce.')
    return
  }

  try {
    await budget.applyAdjustment(props.weekId, selected.value, {
      ...(selected.value === 'category' && categoryId.value !== null
        ? { category_id: categoryId.value }
        : {}),
    })

    ui.success(
      selected.value === 'ignore' ? 'Noted' : 'Budget adjusted',
      selected.value === 'ignore'
        ? 'Your plan is unchanged.'
        : 'Your plan has been updated with the change you chose.',
    )
    emit('applied')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not apply that change', error.message)
  }
}
</script>

<template>
  <BottomSheet
    :open="weekId !== null"
    title="You are over budget"
    description="Nothing has been changed. Choose what you would like to do."
    :busy="budget.saving"
    @close="emit('close')"
  >
    <div v-if="loading" class="py-8 text-center text-sm text-ink-muted">Loading your options…</div>

    <div v-else-if="options" class="space-y-4 pb-2">
      <div class="rounded-[var(--radius-card)] bg-over-soft p-4 text-center">
        <p class="text-sm text-ink">Week {{ options.week.week_number }} is over by</p>
        <MoneyText :amount="options.over_by" size="2xl" class="mt-1 block font-bold text-over" />
        <p class="mt-1 text-xs text-ink-muted">
          Spent <MoneyText :amount="options.week.spent" size="xs" class="font-semibold" />
          of <MoneyText :amount="options.week.budget" size="xs" class="font-semibold" />
        </p>
      </div>

      <!-- Lead with the cause. Reducing another category to cover a week
           that is only over because Smoking ran out fixes the wrong thing. -->
      <div
        v-for="spill in options.spills"
        :key="spill.category_id"
        class="mb-4 rounded-[var(--radius-card)] border border-brand/40 bg-brand-soft p-3.5"
      >
        <p class="text-sm font-semibold text-ink">
          <MoneyText :amount="spill.spilled" size="sm" class="font-bold" /> of this is
          {{ spill.name }} running past its allowance.
        </p>
        <p class="mt-0.5 text-sm text-ink-muted">
          Top up {{ spill.name }} from another pot and it leaves this week altogether.
        </p>
        <button type="button" class="btn btn-primary mt-3 !min-h-10 !text-sm" @click="topUp(spill)">
          <Undo2 class="h-4 w-4" aria-hidden="true" />
          Top up {{ spill.name }} instead
        </button>
      </div>

      <div class="space-y-2.5" role="radiogroup" aria-label="What would you like to do?">
        <button
          v-for="option in options.options"
          :key="option.type"
          type="button"
          role="radio"
          :aria-checked="selected === option.type"
          :disabled="!option.available"
          class="w-full rounded-[var(--radius-card)] border p-3.5 text-left transition disabled:opacity-50"
          :class="
            selected === option.type
              ? 'border-brand bg-brand-soft'
              : 'border-line bg-raised hover:border-ink-subtle'
          "
          @click="selected = option.type"
        >
          <div class="flex items-start gap-3">
            <component
              :is="ICONS[option.type]"
              class="mt-0.5 h-5 w-5 shrink-0"
              :class="selected === option.type ? 'text-brand' : 'text-ink-subtle'"
              aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-ink">{{ option.label }}</p>
              <p class="mt-0.5 text-sm text-ink-muted">{{ option.description }}</p>

              <!-- Show exactly what will change before it happens. -->
              <p
                v-if="option.type === 'next_week' && option.available && option.resulting_amount"
                class="mt-2 text-xs text-ink-muted"
              >
                Week {{ option.target_week_number }}:
                <MoneyText :amount="option.original_amount ?? '0'" size="xs" class="line-through" />
                →
                <MoneyText :amount="option.resulting_amount" size="xs" class="font-bold text-ink" />
              </p>

              <p
                v-else-if="option.type === 'buffer' && option.available"
                class="mt-2 text-xs text-ink-muted"
              >
                Buffer:
                <MoneyText :amount="option.buffer_remaining ?? '0'" size="xs" class="line-through" />
                →
                <MoneyText :amount="option.resulting_buffer ?? '0'" size="xs" class="font-bold text-ink" />
              </p>
            </div>
          </div>
        </button>
      </div>

      <!-- Only allowances are listed: a plain category budget is a warning
           line, so reducing it would free no money at all. The server sends
           the pots that can actually cover this week, with what each has
           spare. -->
      <SelectField
        v-if="selected === 'category'"
        v-model="categoryId"
        :options="
          (options?.options.find((row) => row.type === 'category')?.candidates ?? []).map(
            (row) => ({ value: row.category_id, label: `${row.name} — ${row.available} spare` }),
          )
        "
        label="Which allowance should give up the money?"
        placeholder="Choose an allowance"
      />

      <p class="flex items-start gap-1.5 text-xs text-ink-subtle">
        <PiggyBank class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        Your budget is never changed without you choosing one of these.
      </p>
    </div>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="selected === null || budget.saving"
        @click="apply"
      >
        {{ budget.saving ? 'Applying…' : 'Apply this change' }}
      </button>
    </template>
  </BottomSheet>
</template>
