<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight, CreditCard, Landmark, Receipt, Users, Wallet } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import type { Debt } from '@/types'

const props = defineProps<{ debt: Debt }>()

const ICONS = {
  credit_card: CreditCard,
  installment: Receipt,
  loan: Landmark,
  personal: Users,
  other: Wallet,
} as const

const icon = computed(() => ICONS[props.debt.type])
</script>

<template>
  <RouterLink
    :to="`/debts/${debt.id}`"
    class="card block p-4 transition hover:shadow-[var(--shadow-raised)]"
  >
    <div class="flex items-start gap-3">
      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sunken">
        <component :is="icon" class="h-5 w-5 text-ink-muted" aria-hidden="true" />
      </span>

      <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2">
          <p class="truncate text-sm font-semibold text-ink">{{ debt.name }}</p>
          <MoneyText :amount="debt.current_balance" size="lg" class="shrink-0 font-bold" />
        </div>

        <p class="mt-0.5 text-xs text-ink-subtle">
          {{ debt.type_label }}
          <template v-if="debt.remaining_installments !== null">
            · {{ debt.remaining_installments }}
            {{ debt.remaining_installments === 1 ? 'installment' : 'installments' }} left
          </template>
          <template v-else-if="debt.utilisation_percentage !== null">
            · {{ debt.utilisation_percentage.toFixed(0) }}% of limit
          </template>
        </p>

        <BudgetProgress
          class="mt-2.5"
          height="sm"
          :percentage="debt.progress_percentage"
          status="safe"
          :label="`${debt.name}: ${debt.progress_percentage.toFixed(0)}% paid off`"
        />

        <div class="mt-2 flex items-center justify-between text-xs">
          <span class="text-ink-muted">
            {{ debt.progress_percentage.toFixed(0) }}% paid off
          </span>
          <span class="text-ink-muted">
            <MoneyText :amount="debt.planned_payment" size="xs" class="font-semibold text-ink" />
            planned
          </span>
        </div>

        <!-- The schedule total is not a settlement quote, and says so. -->
        <p v-if="debt.scheduled_remaining" class="mt-1.5 text-xs text-ink-subtle">
          Scheduled remaining
          <MoneyText :amount="debt.scheduled_remaining" size="xs" class="font-semibold" />
          — ask your provider for an early settlement figure.
        </p>
      </div>

      <ChevronRight class="mt-2.5 h-4 w-4 shrink-0 text-ink-subtle" aria-hidden="true" />
    </div>
  </RouterLink>
</template>
