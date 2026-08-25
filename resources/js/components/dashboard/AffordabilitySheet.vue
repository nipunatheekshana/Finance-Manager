<script setup lang="ts">
import { ref, watch } from 'vue'
import { CheckCircle2, AlertTriangle, XCircle } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { useDashboardStore } from '@/stores/dashboard'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { AffordabilityResult } from '@/types'

const ui = useUiStore()
const dashboard = useDashboardStore()

const amount = ref('')
const result = ref<AffordabilityResult | null>(null)
const error = ref('')

const VERDICTS = {
  safe: { icon: CheckCircle2, wrap: 'bg-safe-soft', tint: 'text-safe' },
  warning: { icon: AlertTriangle, wrap: 'bg-warn-soft', tint: 'text-warn' },
  over: { icon: XCircle, wrap: 'bg-over-soft', tint: 'text-over' },
} as const

watch(
  () => ui.affordabilitySheetOpen,
  (open) => {
    if (open) {
      amount.value = ''
      result.value = null
      error.value = ''
    }
  },
)

async function check(): Promise<void> {
  const value = Number.parseFloat(amount.value)

  if (!Number.isFinite(value) || value <= 0) {
    error.value = 'Enter an amount greater than zero.'
    return
  }

  error.value = ''

  try {
    result.value = await dashboard.checkAffordability(value.toFixed(2))
  } catch (caught) {
    if (caught instanceof ApiError) {
      error.value = caught.fieldError('amount') ?? caught.message
    }
  }
}
</script>

<template>
  <BottomSheet
    :open="ui.affordabilitySheetOpen"
    title="Can I afford this?"
    description="Check a purchase against your plan before you make it."
    @close="ui.affordabilitySheetOpen = false"
  >
    <div class="space-y-5 pb-2">
      <form @submit.prevent="check">
        <MoneyInput
          v-model="amount"
          large
          label="How much is it?"
          :error="error"
          data-autofocus
        />
        <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Check</button>
      </form>

      <div v-if="result" class="space-y-4">
        <div class="rounded-[var(--radius-card)] p-4" :class="VERDICTS[result.verdict].wrap">
          <div class="flex items-start gap-3">
            <component
              :is="VERDICTS[result.verdict].icon"
              class="mt-0.5 h-6 w-6 shrink-0"
              :class="VERDICTS[result.verdict].tint"
              aria-hidden="true"
            />
            <div>
              <p class="text-base font-bold" :class="VERDICTS[result.verdict].tint">
                {{ result.headline }}
              </p>
              <p class="mt-0.5 text-sm text-ink">{{ result.message }}</p>
            </div>
          </div>

          <ul v-if="result.reasons.length" class="mt-3 space-y-1.5 pl-9">
            <li v-for="reason in result.reasons" :key="reason" class="text-sm text-ink-muted">
              {{ reason }}
            </li>
          </ul>
        </div>

        <!-- Show the working, so the verdict is never a black box. -->
        <div class="card divide-y divide-line">
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-sm text-ink-muted">Left this week</span>
            <MoneyText :amount="result.factors.week_remaining" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-sm text-ink-muted">After this purchase</span>
            <MoneyText :amount="result.factors.week_remaining_after" size="sm" class="font-semibold" colored />
          </div>
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-sm text-ink-muted">Left this cycle</span>
            <MoneyText :amount="result.factors.month_remaining_after" size="sm" class="font-semibold" colored />
          </div>
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-sm text-ink-muted">New daily limit</span>
            <MoneyText :amount="result.factors.new_daily_limit" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-sm text-ink-muted">Bills still to pay</span>
            <MoneyText :amount="result.factors.upcoming_bills" size="sm" class="font-semibold" />
          </div>
          <div class="flex items-center justify-between px-4 py-2.5">
            <span class="text-sm text-ink-muted">Buffer left</span>
            <MoneyText :amount="result.factors.buffer_remaining" size="sm" class="font-semibold" />
          </div>
        </div>

        <p class="text-xs text-ink-subtle">{{ result.disclaimer }}</p>
      </div>
    </div>

    <template #footer>
      <button
        type="button"
        class="btn btn-primary w-full !text-base"
        :disabled="dashboard.checking"
        @click="check"
      >
        {{ dashboard.checking ? 'Checking…' : result ? 'Check again' : 'Check' }}
      </button>
    </template>
  </BottomSheet>
</template>
