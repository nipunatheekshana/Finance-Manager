<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { CreditCard, Info, TrendingUp } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import SectionHeader from '@/components/common/SectionHeader.vue'
import DonutChart from '@/components/charts/DonutChart.vue'
import BarChart from '@/components/charts/BarChart.vue'
import { api } from '@/services/api'
import { amountToNumber } from '@/composables/useCurrency'
import { formatDate } from '@/composables/useDates'
import type { CreditCardOverview, CreditCardsPage } from '@/types'

const page = ref<CreditCardsPage | null>(null)
const loading = ref(true)

onMounted(async () => {
  try {
    const response = await api.get<{ data: CreditCardsPage }>('/credit-cards')
    page.value = response.data
  } finally {
    loading.value = false
  }
})

function tone(percentage: number | null): 'safe' | 'warning' | 'over' {
  if (percentage === null) return 'safe'
  return percentage >= 90 ? 'over' : percentage >= 70 ? 'warning' : 'safe'
}

function payoffText(card: CreditCardOverview): string {
  const payoff = card.payoff
  if (payoff.warning) return payoff.warning
  if (!payoff.will_be_paid_off) return 'Payoff cannot be estimated at the planned payment.'
  if (payoff.estimated_months === 0) return 'Cleared.'
  return `About ${payoff.estimated_months} ${payoff.estimated_months === 1 ? 'month' : 'months'} at the planned payment — around ${payoff.estimated_payoff_label}.`
}
</script>

<template>
  <div>
    <PageHeader
      title="Credit cards"
      subtitle="What is on each card, what is left to charge, and where it is heading"
    />

    <LoadingState v-if="loading" :rows="3" />

    <EmptyState
      v-else-if="!page || page.cards.length === 0"
      :icon="CreditCard"
      title="No credit cards"
      description="Add a card as a debt and it appears here with its limit, its balance and its own payment method."
      action-label="Go to debts"
      @action="$router.push('/debts')"
    />

    <div v-else class="space-y-6">
      <!-- Across every card, when there is more than one. -->
      <section v-if="page.cards.length > 1" class="card p-4">
        <p class="eyebrow">All cards</p>
        <div class="mt-2 flex items-baseline gap-1.5">
          <MoneyText :amount="page.totals.balance" size="2xl" class="font-bold" />
          <span class="text-sm text-ink-subtle">
            owed of <MoneyText :amount="page.totals.credit_limit" size="sm" class="font-medium" /> limit
          </span>
        </div>
        <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-center">
          <div>
            <dt class="text-xs text-ink-subtle">Available</dt>
            <dd class="mt-0.5"><MoneyText :amount="page.totals.available" size="sm" class="font-semibold text-safe" compact /></dd>
          </div>
          <div>
            <dt class="text-xs text-ink-subtle">Charged this cycle</dt>
            <dd class="mt-0.5"><MoneyText :amount="page.totals.charged_this_cycle" size="sm" class="font-semibold" compact /></dd>
          </div>
          <div>
            <dt class="text-xs text-ink-subtle">Paying this cycle</dt>
            <dd class="mt-0.5"><MoneyText :amount="page.totals.planned_payment" size="sm" class="font-semibold" compact /></dd>
          </div>
        </dl>
      </section>

      <section v-for="card in page.cards" :key="card.id" class="space-y-4">
        <SectionHeader
          :title="card.name"
          :subtitle="card.due_day ? `Due on day ${card.due_day} of the month` : undefined"
          action-label="Pay this card"
          :action-to="`/debts/${card.id}?pay=1`"
        />

        <!-- Spent against the limit: the picture the card is really about. -->
        <div class="card p-4">
          <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-center">
            <div v-if="card.credit_limit !== null && card.available !== null" class="relative shrink-0">
              <DonutChart
                :labels="['Spent', 'Available']"
                :values="[amountToNumber(card.balance), amountToNumber(card.available)]"
                :height="180"
              />
              <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span class="tabular text-lg font-bold text-ink">
                  {{ (card.utilisation_percentage ?? 0).toFixed(0) }}%
                </span>
                <span class="text-[0.6875rem] text-ink-subtle">of limit used</span>
              </div>
            </div>

            <div class="min-w-0 flex-1 text-center sm:text-left">
              <p class="text-sm text-ink-muted">Spent</p>
              <MoneyText :amount="card.balance" size="2xl" class="block font-bold" />

              <template v-if="card.available !== null">
                <p class="mt-2 text-sm text-ink-muted">Available to charge</p>
                <MoneyText :amount="card.available" size="xl" class="block font-bold text-safe" />
                <p class="mt-1 text-xs text-ink-subtle">
                  Credit limit <MoneyText :amount="card.credit_limit ?? '0'" size="xs" class="font-semibold" /> — set by the bank,
                  changed only by you.
                </p>
              </template>
              <p v-else class="mt-2 text-xs text-ink-subtle">
                No credit limit recorded. Add one to the card to see what is left to charge.
              </p>
            </div>
          </div>

          <BudgetProgress
            v-if="card.utilisation_percentage !== null"
            class="mt-4"
            :percentage="card.utilisation_percentage"
            :status="tone(card.utilisation_percentage)"
            :label="`${card.name}: ${card.utilisation_percentage.toFixed(0)}% of the credit limit used`"
          />
        </div>

        <!-- The bill side is on Debts; here, only what this cycle put on the card. -->
        <div class="card p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="eyebrow">This cycle</p>
              <p v-if="page.plan_label" class="mt-0.5 text-xs text-ink-subtle">{{ page.plan_label }}</p>
            </div>
            <span v-if="card.is_growing" class="badge bg-warn-soft text-warn">
              <TrendingUp class="h-3 w-3" aria-hidden="true" />
              Growing
            </span>
          </div>

          <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div>
              <dt class="text-xs text-ink-subtle">Charged</dt>
              <dd class="mt-0.5"><MoneyText :amount="card.charged_this_cycle" size="sm" class="font-semibold" compact /></dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Paying back</dt>
              <dd class="mt-0.5"><MoneyText :amount="card.planned_payment" size="sm" class="font-semibold" compact /></dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Net</dt>
              <dd class="mt-0.5">
                <MoneyText :amount="card.net_change" size="sm" class="font-semibold" signed compact
                  :class="card.is_growing ? 'text-over' : 'text-safe'" />
              </dd>
            </div>
          </dl>

          <p class="mt-3 flex items-start gap-1.5 text-xs text-ink-subtle">
            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            Purchases on the card never touch your weekly budget. What you pay back is planned under Debts.
          </p>
        </div>

        <div v-if="card.charges_by_cycle.length > 1" class="card p-4">
          <p class="eyebrow mb-3">Charged per cycle</p>
          <BarChart
            :labels="card.charges_by_cycle.map((row) => row.label)"
            :datasets="[{ label: 'Charged', values: card.charges_by_cycle.map((row) => amountToNumber(row.charged)), token: '--color-brand' }]"
            :height="160"
          />
        </div>

        <div class="card p-4">
          <p class="eyebrow">Payoff estimate</p>
          <p class="mt-1 text-sm text-ink">{{ payoffText(card) }}</p>
          <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-line pt-3">
            <div>
              <dt class="text-xs text-ink-subtle">Minimum payment</dt>
              <dd class="mt-0.5"><MoneyText :amount="card.minimum_payment" size="sm" class="font-semibold" /></dd>
            </div>
            <div v-if="card.interest_rate">
              <dt class="text-xs text-ink-subtle">Interest</dt>
              <dd class="tabular mt-0.5 text-sm font-semibold text-ink">{{ Number.parseFloat(card.interest_rate).toFixed(1) }}% a year</dd>
            </div>
          </dl>
          <p class="mt-2 text-xs text-ink-subtle">This is an estimate.</p>
        </div>

        <div v-if="card.recent_purchases.length" class="card divide-y divide-line px-4">
          <p class="eyebrow py-3">Recent purchases</p>
          <div
            v-for="purchase in card.recent_purchases"
            :key="purchase.id"
            class="flex items-center gap-3 py-3"
          >
            <CategoryIcon :icon="purchase.icon" :color="purchase.color" size="sm" />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">{{ purchase.description || purchase.category }}</p>
              <p class="text-xs text-ink-subtle">{{ purchase.category }} · {{ formatDate(purchase.expense_date) }}</p>
            </div>
            <MoneyText :amount="purchase.amount" size="sm" class="shrink-0 font-semibold" />
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
