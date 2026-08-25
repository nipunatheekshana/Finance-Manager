<script setup lang="ts">
import { CloudOff } from 'lucide-vue-next'
import { useExpenseStore } from '@/stores/expenses'

const expenses = useExpenseStore()
</script>

<template>
  <div
    class="sticky top-0 z-40 flex items-center justify-center gap-2 bg-warn-soft px-4 py-2 text-sm font-medium text-warn"
    role="status"
  >
    <CloudOff class="h-4 w-4 shrink-0" aria-hidden="true" />
    <span>
      You are offline.
      <template v-if="expenses.pendingCount > 0">
        {{ expenses.pendingCount }} {{ expenses.pendingCount === 1 ? 'expense' : 'expenses' }} will sync
        when you reconnect.
      </template>
      <template v-else>Expenses you add will sync automatically.</template>
    </span>
  </div>
</template>
