<script setup lang="ts">
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import { relativeDay } from '@/composables/useDates'
import { useUiStore } from '@/stores/ui'
import type { DashboardExpense } from '@/types'

defineProps<{ expenses: DashboardExpense[] }>()

const ui = useUiStore()
</script>

<template>
  <ul class="divide-y divide-line">
    <li v-for="expense in expenses" :key="expense.id">
      <button
        type="button"
        class="flex w-full items-center gap-3 py-3 text-left transition hover:opacity-80"
        @click="ui.openExpenseSheet(expense.id)"
      >
        <CategoryIcon :icon="expense.category.icon" :color="expense.category.color" size="sm" />

        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-ink">
            {{ expense.description || expense.category.name }}
          </p>
          <p class="truncate text-xs text-ink-subtle">
            {{ expense.category.name }} · {{ expense.payment_method.name }} ·
            {{ relativeDay(expense.expense_date) }}
          </p>
        </div>

        <MoneyText :amount="expense.amount" size="sm" class="shrink-0 font-semibold" />
      </button>
    </li>
  </ul>
</template>
