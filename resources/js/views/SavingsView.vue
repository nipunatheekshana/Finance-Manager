<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { PiggyBank, Plus } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import SavingsGoalCard from '@/components/savings/SavingsGoalCard.vue'
import SavingsGoalFormSheet from '@/components/savings/SavingsGoalFormSheet.vue'
import SavingsTransactionSheet from '@/components/savings/SavingsTransactionSheet.vue'
import { useSavingsStore } from '@/stores/savings'

const savings = useSavingsStore()

const formOpen = ref(false)
const transactionGoalId = ref<number | null>(null)

onMounted(() => {
  void savings.fetch()
})
</script>

<template>
  <div>
    <PageHeader title="Savings">
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="formOpen = true">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span class="hidden sm:inline">New goal</span>
          <span class="sr-only sm:hidden">New goal</span>
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="savings.loading && savings.goals.length === 0" :rows="3" />

    <EmptyState
      v-else-if="savings.isEmpty"
      :icon="PiggyBank"
      title="No savings goals yet"
      description="Create a goal to start saving towards something."
      action-label="Create goal"
      @action="formOpen = true"
    />

    <div v-else class="space-y-5">
      <section class="card overflow-hidden">
        <div class="bg-brand px-5 py-6 text-on-brand">
          <p class="text-xs font-bold uppercase tracking-[0.08em] opacity-80">Total saved</p>
          <MoneyText :amount="savings.totalSaved" size="3xl" class="mt-1 block font-bold" />
          <p class="mt-1.5 text-sm opacity-90">
            of <MoneyText :amount="savings.totals?.total_target ?? '0'" size="sm" class="font-semibold" />
            across {{ savings.activeGoals.length }}
            {{ savings.activeGoals.length === 1 ? 'goal' : 'goals' }}
          </p>
        </div>

        <div class="px-5 py-3.5">
          <p class="text-xs font-semibold text-ink-subtle">Planned each month</p>
          <MoneyText
            :amount="savings.totals?.total_monthly_target ?? '0'"
            size="base"
            class="mt-0.5 block font-semibold"
          />
        </div>
      </section>

      <ul class="space-y-3">
        <li v-for="goal in savings.activeGoals" :key="goal.id">
          <SavingsGoalCard :goal="goal" @add-money="transactionGoalId = goal.id" />
        </li>
      </ul>
    </div>

    <SavingsGoalFormSheet :open="formOpen" @close="formOpen = false" @saved="savings.fetch()" />

    <SavingsTransactionSheet
      :goal-id="transactionGoalId"
      @close="transactionGoalId = null"
      @saved="savings.fetch()"
    />
  </div>
</template>
