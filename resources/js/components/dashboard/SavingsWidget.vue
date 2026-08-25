<script setup lang="ts">
import { PiggyBank } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import type { DashboardSavings } from '@/types'

defineProps<{ savings: DashboardSavings }>()
</script>

<template>
  <RouterLink to="/savings" class="card block p-4 transition hover:shadow-[var(--shadow-raised)]">
    <div class="flex items-center gap-2">
      <PiggyBank class="h-4 w-4 text-ink-subtle" aria-hidden="true" />
      <h2 class="eyebrow">Savings</h2>
    </div>

    <MoneyText :amount="savings.total" size="2xl" class="mt-2 block font-bold" />

    <div class="mt-1 flex items-center justify-between text-sm">
      <span class="text-ink-muted">Saved this cycle</span>
      <MoneyText :amount="savings.this_month" size="sm" class="font-semibold" colored signed />
    </div>

    <div v-if="savings.goals.length" class="mt-3 space-y-2.5">
      <div v-for="goal in savings.goals.slice(0, 2)" :key="goal.id">
        <div class="flex items-center justify-between text-xs">
          <span class="truncate text-ink-muted">{{ goal.name }}</span>
          <span class="tabular shrink-0 pl-2 font-semibold text-ink">
            {{ goal.percentage.toFixed(0) }}%
          </span>
        </div>
        <BudgetProgress
          class="mt-1"
          height="sm"
          :percentage="goal.percentage"
          status="safe"
          :label="`${goal.name}: ${goal.percentage.toFixed(0)}% of target`"
        />
      </div>
    </div>
  </RouterLink>
</template>
