<script setup lang="ts">
import { computed } from 'vue'
import { AlertTriangle, TrendingDown, TrendingUp } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import { formatDateRange } from '@/composables/useDates'
import { amountToNumber } from '@/composables/useCurrency'
import type { MonthlySummary, SalarySection } from '@/types'

const props = defineProps<{
  periodLabel: string
  available: string
  salary: SalarySection
  month: MonthlySummary | null
}>()

const isNegative = computed(() => amountToNumber(props.available) < 0)

const receivedExtra = computed(() => amountToNumber(props.salary.extra) > 0)

const belowExpected = computed(
  () =>
    props.salary.actual !== null &&
    amountToNumber(props.salary.actual) < amountToNumber(props.salary.expected),
)
</script>

<template>
  <section class="card overflow-hidden">
    <div class="bg-brand px-5 py-6 text-on-brand">
      <p class="text-xs font-bold uppercase tracking-[0.08em] opacity-80">Available to spend</p>

      <MoneyText :amount="available" size="3xl" class="mt-1 block font-bold" />

      <p v-if="isNegative" class="mt-1.5 text-sm font-medium opacity-90">
        You are over your planned spending for this cycle.
      </p>
      <p v-else-if="month" class="mt-1.5 text-sm opacity-90">
        {{ month.days_remaining }} {{ month.days_remaining === 1 ? 'day' : 'days' }} left in
        {{ formatDateRange(month.cycle_start, month.cycle_end) }}
      </p>
    </div>

    <!-- Irregular earners care about the pot and its runway, not a pay day. -->
    <div v-if="salary.holding_pot" class="grid grid-cols-2 divide-x divide-line">
      <div class="px-5 py-3.5">
        <p class="text-xs font-semibold text-ink-subtle">Banked</p>
        <MoneyText :amount="salary.holding_pot.balance" size="base" class="mt-0.5 block font-semibold" />
      </div>

      <div class="px-5 py-3.5">
        <p class="text-xs font-semibold text-ink-subtle">Runway</p>
        <p class="mt-0.5 flex items-center gap-1.5">
          <span class="tabular text-base font-semibold text-ink">
            {{ salary.holding_pot.months === null ? '—' : `${salary.holding_pot.months} mo` }}
          </span>
          <span
            v-if="salary.holding_pot.is_low || salary.holding_pot.is_negative"
            class="badge bg-warn-soft text-warn"
          >
            <AlertTriangle class="h-3 w-3" aria-hidden="true" />
            Low
          </span>
        </p>
      </div>
    </div>

    <div v-else class="grid grid-cols-2 divide-x divide-line">
      <div class="px-5 py-3.5">
        <p class="text-xs font-semibold text-ink-subtle">
          {{ salary.has_pay_day ? 'Expected salary' : 'Planned income' }}
        </p>
        <MoneyText :amount="salary.expected" size="base" class="mt-0.5 block font-semibold" />
      </div>

      <div class="px-5 py-3.5">
        <p class="text-xs font-semibold text-ink-subtle">
          {{
            salary.actual === null
              ? salary.has_pay_day
                ? 'Not received yet'
                : 'Received so far'
              : salary.has_pay_day
                ? 'Actual salary'
                : 'Received'
          }}
        </p>
        <!-- Stacked, so a long figure and the badge never fight for width. -->
        <div class="mt-0.5">
          <div class="flex items-center gap-1.5">
            <MoneyText
              :amount="salary.actual ?? salary.expected"
              size="base"
              class="font-semibold"
              :class="salary.actual === null ? 'text-ink-subtle' : ''"
            />
            <TrendingDown
              v-if="belowExpected"
              class="h-4 w-4 shrink-0 text-warn"
              aria-label="Below the expected salary"
            />
          </div>

          <span v-if="receivedExtra" class="badge mt-1 bg-safe-soft text-safe">
            <TrendingUp class="h-3 w-3" aria-hidden="true" />
            <MoneyText :amount="salary.extra" size="xs" class="font-semibold" signed compact />
            extra
          </span>
        </div>
      </div>
    </div>
  </section>
</template>
