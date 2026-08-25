<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowDownLeft, ArrowUpRight, Pencil, PiggyBank, Plus, Trash2 } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import ProgressRing from '@/components/common/ProgressRing.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import SavingsGoalFormSheet from '@/components/savings/SavingsGoalFormSheet.vue'
import SavingsTransactionSheet from '@/components/savings/SavingsTransactionSheet.vue'
import { useSavingsStore } from '@/stores/savings'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/composables/useDates'

const route = useRoute()
const router = useRouter()
const savings = useSavingsStore()
const ui = useUiStore()

const editing = ref(false)
const transacting = ref(false)
const confirmingDelete = ref(false)
const deleting = ref(false)

const goal = computed(() => savings.selected)

const TYPE_LABELS = {
  deposit: 'Deposit',
  withdrawal: 'Withdrawal',
  transfer_in: 'Transferred in',
  transfer_out: 'Transferred out',
} as const

async function load(): Promise<void> {
  await savings.load(Number(route.params.id))
}

async function confirmDelete(): Promise<void> {
  if (!goal.value) return
  deleting.value = true
  try {
    await savings.remove(goal.value.id)
    ui.success('Goal removed')
    void router.push('/savings')
  } finally {
    deleting.value = false
    confirmingDelete.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="savings.loading && !goal" :rows="3" />

    <div v-else-if="goal">
      <PageHeader :title="goal.name" back-to="/savings">
        <template #actions>
          <div class="flex gap-2">
            <button
              type="button"
              class="btn btn-secondary !min-h-11 !w-11 !p-0"
              aria-label="Edit goal"
              @click="editing = true"
            >
              <Pencil class="h-4 w-4" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="btn btn-secondary !min-h-11 !w-11 !p-0 text-over"
              aria-label="Delete goal"
              @click="confirmingDelete = true"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
        </template>
      </PageHeader>

      <div class="space-y-5">
        <section class="card flex flex-col items-center p-6 text-center">
          <ProgressRing
            :percentage="goal.percentage"
            :tone="goal.is_reached ? 'safe' : 'brand'"
            :size="132"
            :stroke="11"
          >
            <span class="tabular text-xl font-bold text-ink">{{ goal.percentage.toFixed(0) }}%</span>
            <span class="text-xs text-ink-subtle">saved</span>
          </ProgressRing>

          <p class="mt-4">
            <MoneyText :amount="goal.current_amount" size="2xl" class="font-bold" />
            <span class="text-base text-ink-subtle">
              / <MoneyText :amount="goal.target_amount" size="base" />
            </span>
          </p>

          <p class="mt-1 text-sm text-ink-muted">
            <MoneyText :amount="goal.remaining_amount" size="sm" class="font-semibold text-ink" /> still to go
          </p>

          <dl class="mt-5 grid w-full grid-cols-2 gap-4 border-t border-line pt-4 text-left">
            <div>
              <dt class="text-xs text-ink-subtle">Monthly target</dt>
              <dd class="mt-0.5"><MoneyText :amount="goal.monthly_target" size="sm" class="font-semibold" /></dd>
            </div>
            <div v-if="goal.target_date">
              <dt class="text-xs text-ink-subtle">Target date</dt>
              <dd class="mt-0.5 text-sm font-semibold text-ink">{{ formatDate(goal.target_date, true) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Priority</dt>
              <dd class="mt-0.5 text-sm font-semibold text-ink">{{ goal.priority }}</dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Status</dt>
              <dd class="mt-0.5 text-sm font-semibold capitalize text-ink">{{ goal.status }}</dd>
            </div>
          </dl>

          <button type="button" class="btn btn-primary mt-5 w-full !text-base" @click="transacting = true">
            <Plus class="h-4 w-4" aria-hidden="true" />
            Add or withdraw money
          </button>
        </section>

        <section>
          <h2 class="mb-3 text-base font-semibold text-ink">History</h2>

          <ul v-if="goal.transactions?.length" class="card divide-y divide-line px-5">
            <li
              v-for="transaction in goal.transactions"
              :key="transaction.id"
              class="flex items-center gap-3 py-3"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                :class="transaction.increases_balance ? 'bg-safe-soft' : 'bg-over-soft'"
              >
                <component
                  :is="transaction.increases_balance ? ArrowDownLeft : ArrowUpRight"
                  class="h-4 w-4"
                  :class="transaction.increases_balance ? 'text-safe' : 'text-over'"
                  aria-hidden="true"
                />
              </span>

              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-ink">{{ TYPE_LABELS[transaction.type] }}</p>
                <p class="truncate text-xs text-ink-subtle">
                  {{ formatDate(transaction.transaction_date, true) }}
                  <template v-if="transaction.description"> · {{ transaction.description }}</template>
                </p>
              </div>

              <MoneyText
                :amount="transaction.increases_balance ? transaction.amount : `-${transaction.amount}`"
                size="sm"
                class="shrink-0 font-semibold"
                colored
                signed
              />
            </li>
          </ul>

          <EmptyState
            v-else
            :icon="PiggyBank"
            title="Nothing saved here yet"
            description="Add your first contribution towards this goal."
            action-label="Add money"
            @action="transacting = true"
          />
        </section>
      </div>

      <SavingsGoalFormSheet :open="editing" :goal="goal" @close="editing = false" @saved="load" />

      <SavingsTransactionSheet
        :goal-id="transacting ? goal.id : null"
        @close="transacting = false"
        @saved="load"
      />

      <ConfirmDialog
        :open="confirmingDelete"
        title="Delete this goal?"
        message="Goals with a transaction history are archived rather than deleted, so your records stay intact."
        confirm-label="Delete"
        destructive
        :busy="deleting"
        @confirm="confirmDelete"
        @cancel="confirmingDelete = false"
      />
    </div>
  </div>
</template>
