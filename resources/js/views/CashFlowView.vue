<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ArrowDownRight, ArrowUpRight, Info, TrendingUp } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import SectionHeader from '@/components/common/SectionHeader.vue'
import BillPaymentSheet from '@/components/budgets/BillPaymentSheet.vue'
import { api } from '@/services/api'
import { formatDate, formatDateRange, relativeDay } from '@/composables/useDates'
import type { CashFlowForecast, UpcomingBill } from '@/types'

const forecast = ref<CashFlowForecast | null>(null)
const payingBill = ref<UpcomingBill | null>(null)
const message = ref('')
const loading = ref(true)

const runningBalance = computed(() => {
  if (!forecast.value) return []

  // Walk the timeline from today's available money so the user can see how
  // each upcoming commitment moves the number.
  let balance = Number.parseFloat(forecast.value.available_to_spend)

  return forecast.value.timeline.map((event) => {
    const amount = Number.parseFloat(event.amount)
    balance += event.direction === 'in' ? amount : -amount
    return { ...event, balance: balance.toFixed(2) }
  })
})

async function load(): Promise<void> {
  try {
    const response = await api.get<{ data: CashFlowForecast | null; message?: string }>('/cash-flow')
    forecast.value = response.data
    message.value = response.message ?? ''
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Cash flow"
      :subtitle="forecast ? formatDateRange(forecast.cycle_start, forecast.cycle_end) : undefined"
    />

    <LoadingState v-if="loading" :rows="3" />

    <EmptyState
      v-else-if="!forecast"
      :icon="TrendingUp"
      title="No active plan"
      :description="message || 'Finalise a monthly plan to see your cash flow.'"
      action-label="Go to planning"
      @action="$router.push('/plan')"
    />

    <div v-else class="space-y-5">
      <section class="card overflow-hidden">
        <div class="bg-brand px-5 py-6 text-on-brand">
          <p class="text-xs font-bold uppercase tracking-[0.08em] opacity-80">Available now</p>
          <MoneyText :amount="forecast.available_to_spend" size="3xl" class="mt-1 block font-bold" />
          <p class="mt-1.5 text-sm opacity-90">
            {{ forecast.days_remaining }}
            {{ forecast.days_remaining === 1 ? 'day' : 'days' }} left in this cycle
          </p>
        </div>

        <dl class="divide-y divide-line">
          <div class="flex items-center justify-between px-5 py-3">
            <dt class="text-sm text-ink-muted">Income this cycle</dt>
            <dd><MoneyText :amount="forecast.total_income" size="sm" class="font-semibold" /></dd>
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <dt class="text-sm text-ink-muted">Set aside for spending</dt>
            <dd><MoneyText :amount="forecast.projected_spending_balance" size="sm" class="font-semibold" /></dd>
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <dt class="text-sm text-ink-muted">Spent so far</dt>
            <dd><MoneyText :amount="forecast.spent_so_far" size="sm" class="font-semibold" /></dd>
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <dt class="text-sm text-ink-muted">Still committed</dt>
            <dd><MoneyText :amount="forecast.total_committed" size="sm" class="font-semibold" /></dd>
          </div>
          <div class="flex items-center justify-between px-5 py-3">
            <dt class="text-sm text-ink-muted">Buffer left</dt>
            <dd><MoneyText :amount="forecast.buffer_remaining" size="sm" class="font-semibold" /></dd>
          </div>
        </dl>
      </section>

      <!-- The projection, clearly labelled as one. -->
      <section class="card p-5">
        <h2 class="eyebrow">Where this cycle is heading</h2>

        <p class="mt-2">
          <MoneyText :amount="forecast.projected_month_end_balance" size="2xl" class="font-bold" colored />
          <span class="block text-sm text-ink-muted">projected left at the end of the cycle</span>
        </p>

        <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-line pt-4">
          <div>
            <dt class="text-xs text-ink-subtle">Average a day so far</dt>
            <dd class="mt-0.5"><MoneyText :amount="forecast.average_daily_spend" size="sm" class="font-semibold" /></dd>
          </div>
          <div>
            <dt class="text-xs text-ink-subtle">Projected further spend</dt>
            <dd class="mt-0.5"><MoneyText :amount="forecast.projected_further_spend" size="sm" class="font-semibold" /></dd>
          </div>
        </dl>

        <p class="mt-3 flex items-start gap-1.5 text-xs text-ink-subtle">
          <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          An estimate based on how fast you have spent so far this cycle.
        </p>
      </section>

      <section v-if="runningBalance.length">
        <SectionHeader title="What is still to come" subtitle="In date order" />

        <ul class="card divide-y divide-line px-4">
          <li v-for="(event, index) in runningBalance" :key="index" class="flex items-center gap-3 py-3">
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
              :class="event.direction === 'in' ? 'bg-safe-soft' : 'bg-over-soft'"
            >
              <component
                :is="event.direction === 'in' ? ArrowDownRight : ArrowUpRight"
                class="h-4 w-4"
                :class="event.direction === 'in' ? 'text-safe' : 'text-over'"
                aria-hidden="true"
              />
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">{{ event.name }}</p>
              <p class="text-xs capitalize text-ink-subtle">
                {{ event.kind }}
                <template v-if="event.date"> · {{ relativeDay(event.date) }}</template>
              </p>
            </div>

            <div class="shrink-0 text-right">
              <MoneyText
                :amount="event.direction === 'in' ? event.amount : `-${event.amount}`"
                size="sm"
                class="font-semibold"
                colored
                signed
              />
              <p class="text-xs text-ink-subtle">
                then <MoneyText :amount="event.balance" size="xs" />
              </p>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="forecast.upcoming_bills.items.length">
        <SectionHeader title="Bills still to pay" />
        <ul class="card divide-y divide-line px-4">
          <li v-for="bill in forecast.upcoming_bills.items" :key="bill.id">
            <button
              type="button"
              class="-mx-4 flex w-[calc(100%+2rem)] items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-sunken"
              @click="payingBill = bill"
            >
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-ink">{{ bill.name }}</p>
                <p class="text-xs" :class="bill.is_overdue ? 'text-over' : 'text-ink-subtle'">
                  {{ bill.is_overdue ? `Overdue since ${formatDate(bill.date)}` : relativeDay(bill.date) }}
                </p>
              </div>
              <span class="flex shrink-0 items-center gap-2">
                <MoneyText :amount="bill.amount" size="sm" class="font-semibold" />
                <span class="badge bg-brand-soft text-brand">Pay</span>
              </span>
            </button>
          </li>
        </ul>
      </section>
    </div>

    <BillPaymentSheet :bill="payingBill" @close="payingBill = null" @paid="load" />
  </div>
</template>
