<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CreditCard, Plus } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import DebtCard from '@/components/debts/DebtCard.vue'
import DebtFormSheet from '@/components/debts/DebtFormSheet.vue'
import { useDebtStore } from '@/stores/debts'

const debts = useDebtStore()
const formOpen = ref(false)

const payoff = computed(() => debts.totals?.payoff ?? null)

onMounted(() => {
  void debts.fetch()
})
</script>

<template>
  <div>
    <PageHeader title="Debts">
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="formOpen = true">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span class="hidden sm:inline">Add debt</span>
          <span class="sr-only sm:hidden">Add debt</span>
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="debts.loading && debts.items.length === 0" :rows="3" />

    <EmptyState
      v-else-if="debts.isEmpty"
      :icon="CreditCard"
      title="No debts added"
      description="Add your first debt to start tracking your payoff progress."
      action-label="Add debt"
      @action="formOpen = true"
    />

    <div v-else class="space-y-5">
      <section class="card overflow-hidden">
        <div class="bg-brand px-5 py-6 text-on-brand">
          <p class="text-xs font-bold uppercase tracking-[0.08em] opacity-80">Total debt</p>
          <MoneyText :amount="debts.totalBalance" size="3xl" class="mt-1 block font-bold" />
          <p v-if="payoff?.debt_free_in_months" class="mt-1.5 text-sm opacity-90">
            Debt free in about {{ payoff.debt_free_in_months }}
            {{ payoff.debt_free_in_months === 1 ? 'month' : 'months' }} at your current payments — an estimate.
          </p>
        </div>

        <dl class="grid grid-cols-2 divide-x divide-line">
          <div class="px-5 py-3.5">
            <dt class="text-xs font-semibold text-ink-subtle">Planned monthly</dt>
            <dd class="mt-0.5">
              <MoneyText :amount="debts.totals?.total_planned_payment ?? '0'" size="base" class="font-semibold" />
            </dd>
          </div>
          <div class="px-5 py-3.5">
            <dt class="text-xs font-semibold text-ink-subtle">Minimum required</dt>
            <dd class="mt-0.5">
              <MoneyText :amount="debts.totals?.total_minimum_payment ?? '0'" size="base" class="font-semibold" />
            </dd>
          </div>
        </dl>
      </section>

      <ul class="space-y-3">
        <li v-for="debt in debts.items" :key="debt.id">
          <DebtCard :debt="debt" />
        </li>
      </ul>
    </div>

    <DebtFormSheet :open="formOpen" @close="formOpen = false" @saved="debts.fetch()" />
  </div>
</template>
