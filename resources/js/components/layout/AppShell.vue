<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import AppSidebar from './AppSidebar.vue'
import BottomNav from './BottomNav.vue'
import InstallSheet from './InstallSheet.vue'
import ExpenseSheet from '@/components/expenses/ExpenseSheet.vue'
import AffordabilitySheet from '@/components/dashboard/AffordabilitySheet.vue'
import OverspendSheet from '@/components/budgets/OverspendSheet.vue'
import { useExpenseStore } from '@/stores/expenses'
import { useUiStore } from '@/stores/ui'
import { useDashboardStore } from '@/stores/dashboard'
import { useBudgetStore } from '@/stores/budget'

const expenses = useExpenseStore()
const ui = useUiStore()
const dashboard = useDashboardStore()
const budget = useBudgetStore()

async function onOverspendResolved(): Promise<void> {
  ui.overspendWeekId = null
  await Promise.allSettled([
    dashboard.refresh(),
    budget.plan ? budget.loadWeeks() : Promise.resolve(),
  ])
}

onMounted(() => {
  // Categories and payment methods are needed by the expense sheet, which can
  // be opened from anywhere, so they load once with the shell.
  void expenses.loadReference()
})
</script>

<template>
  <div class="min-h-full">
    <AppSidebar />

    <!-- The main column is offset for the sidebar from lg upward, and leaves
         room for the bottom nav below it. -->
    <div class="lg:pl-64">
      <main class="mx-auto w-full max-w-5xl px-4 pb-[calc(var(--spacing-nav)+1.5rem)] pt-4 sm:px-6 lg:pb-10 lg:pt-8">
        <RouterView v-slot="{ Component }">
          <component :is="Component" />
        </RouterView>
      </main>
    </div>

    <BottomNav />

    <!-- Global sheets, reachable from every screen. -->
    <ExpenseSheet />
    <InstallSheet :open="ui.installSheetOpen" @close="ui.installSheetOpen = false" />
    <AffordabilitySheet />

    <!-- Opens the moment an expense tips a week over budget. -->
    <OverspendSheet
      :week-id="ui.overspendWeekId"
      @close="ui.overspendWeekId = null"
      @applied="onOverspendResolved"
    />
  </div>
</template>
