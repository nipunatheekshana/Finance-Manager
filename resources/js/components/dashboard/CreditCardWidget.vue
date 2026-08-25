<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight, CreditCard, Info } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import type { DashboardDebts } from '@/types'

const props = defineProps<{ debts: DashboardDebts }>()

const cards = computed(() => props.debts.credit_cards)

/** The card with the largest balance, shown in full when it is the only one. */
const primary = computed(() => props.debts.credit_card)

const hasMultiple = computed(() => cards.value.count > 1)

const payoffText = computed(() => {
  const payoff = primary.value?.payoff
  if (!payoff) return null

  if (payoff.warning) return payoff.warning
  if (!payoff.will_be_paid_off) return 'Payoff cannot be estimated yet.'
  if (payoff.estimated_months === 0) return 'Cleared.'

  return `About ${payoff.estimated_months} ${payoff.estimated_months === 1 ? 'month' : 'months'} left — around ${payoff.estimated_payoff_label}.`
})

const utilisation = computed(() => {
  const limit = Number.parseFloat(cards.value?.total_limit ?? '0')
  const balance = Number.parseFloat(cards.value?.total_balance ?? '0')
  if (!Number.isFinite(limit) || limit <= 0) return null
  return (balance / limit) * 100
})
</script>

<template>
  <!-- Several cards: a combined total, then each card with its own balance. -->
  <section v-if="hasMultiple" class="card p-4" aria-labelledby="cards-heading">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-2">
        <CreditCard class="h-4 w-4 text-ink-subtle" aria-hidden="true" />
        <h2 id="cards-heading" class="eyebrow">Credit cards</h2>
      </div>
      <span class="tabular text-xs font-semibold text-ink-muted">{{ cards.count }} cards</span>
    </div>

    <MoneyText :amount="cards.total_balance" size="2xl" class="mt-2 block font-bold" />

    <div class="mt-1 flex items-center justify-between text-sm">
      <span class="text-ink-muted">Planned this month</span>
      <MoneyText :amount="cards.total_planned_payment" size="sm" class="font-semibold" />
    </div>

    <p v-if="utilisation !== null" class="mt-1 flex items-center justify-between text-sm">
      <span class="text-ink-muted">Combined usage</span>
      <span class="tabular font-semibold text-ink">{{ utilisation.toFixed(0) }}%</span>
    </p>

    <ul class="mt-4 space-y-3 border-t border-line pt-3">
      <li v-for="card in cards.items" :key="card.id">
        <RouterLink :to="`/debts/${card.id}`" class="block transition hover:opacity-80">
          <div class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium text-ink">{{ card.name }}</span>
            <span class="flex shrink-0 items-center gap-1">
              <MoneyText :amount="card.balance" size="sm" class="font-semibold" />
              <ChevronRight class="h-3.5 w-3.5 text-ink-subtle" aria-hidden="true" />
            </span>
          </div>

          <BudgetProgress
            class="mt-1.5"
            height="sm"
            :percentage="card.progress_percentage"
            status="safe"
            :label="`${card.name}: ${card.progress_percentage.toFixed(0)}% paid off`"
          />
        </RouterLink>
      </li>
    </ul>
  </section>

  <!-- A single card gets the fuller treatment, including its payoff estimate. -->
  <RouterLink
    v-else-if="primary"
    :to="`/debts/${primary.id}`"
    class="card block p-4 transition hover:shadow-[var(--shadow-raised)]"
  >
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-2">
        <CreditCard class="h-4 w-4 text-ink-subtle" aria-hidden="true" />
        <h2 class="eyebrow">{{ primary.name }}</h2>
      </div>
      <span class="tabular text-xs font-semibold text-ink-muted">
        {{ primary.progress_percentage.toFixed(0) }}% paid off
      </span>
    </div>

    <MoneyText :amount="primary.balance" size="2xl" class="mt-2 block font-bold" />

    <BudgetProgress
      class="mt-3"
      :percentage="primary.progress_percentage"
      status="safe"
      :label="`${primary.name}: ${primary.progress_percentage.toFixed(0)}% paid off`"
    />

    <div class="mt-3 flex items-center justify-between text-sm">
      <span class="text-ink-muted">Planned this month</span>
      <MoneyText :amount="primary.planned_payment" size="sm" class="font-semibold" />
    </div>

    <p v-if="primary.utilisation_percentage !== null" class="mt-1 flex items-center justify-between text-sm">
      <span class="text-ink-muted">Card usage</span>
      <span class="tabular font-semibold text-ink">{{ primary.utilisation_percentage.toFixed(0) }}%</span>
    </p>

    <!-- Payoff dates are always presented as estimates. -->
    <p v-if="payoffText" class="mt-2.5 flex items-start gap-1.5 text-xs text-ink-subtle">
      <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span>{{ payoffText }} This is an estimate.</span>
    </p>
  </RouterLink>

  <!-- No cards, but other debts exist. -->
  <RouterLink v-else-if="debts.count > 0" to="/debts" class="card block p-4">
    <h2 class="eyebrow">Total debt</h2>
    <MoneyText :amount="debts.total_balance" size="2xl" class="mt-2 block font-bold" />
    <p class="mt-1 text-sm text-ink-muted">
      Across {{ debts.count }} {{ debts.count === 1 ? 'debt' : 'debts' }}
    </p>
  </RouterLink>
</template>
