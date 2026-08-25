<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { AlertTriangle, Banknote, Info, Pencil, Trash2 } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import MoneyInput from '@/components/common/MoneyInput.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DebtFormSheet from '@/components/debts/DebtFormSheet.vue'
import DebtPaymentSheet from '@/components/debts/DebtPaymentSheet.vue'
import LineChart from '@/components/charts/LineChart.vue'
import { useDebtStore } from '@/stores/debts'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/composables/useDates'
import { amountToNumber } from '@/composables/useCurrency'
import type { PayoffProjection } from '@/types'

const route = useRoute()
const router = useRouter()
const debts = useDebtStore()
const ui = useUiStore()

const editing = ref(false)
const paying = ref(false)
const confirmingDelete = ref(false)
const deleting = ref(false)

const whatIfPayment = ref('')
const whatIf = ref<PayoffProjection | null>(null)

const debt = computed(() => debts.selected)
const payoff = computed(() => whatIf.value ?? debts.selectedPayoff)

const scheduleChart = computed(() => {
  const schedule = payoff.value?.schedule ?? []
  if (schedule.length === 0) return null

  return {
    labels: [
      'Now',
      ...schedule.map((row) => row.label),
    ],
    values: [
      amountToNumber(debt.value?.current_balance ?? '0'),
      ...schedule.map((row) => amountToNumber(row.remaining_balance)),
    ],
  }
})

async function load(): Promise<void> {
  await debts.load(Number(route.params.id))
  whatIf.value = null
  whatIfPayment.value = debt.value ? String(Number.parseFloat(debt.value.planned_payment)) : ''
}

let whatIfTimer: ReturnType<typeof setTimeout> | undefined

/** Recalculate the projection as the user tries a different payment. */
watch(whatIfPayment, (value) => {
  clearTimeout(whatIfTimer)

  const amount = Number.parseFloat(value)
  const planned = Number.parseFloat(debt.value?.planned_payment ?? '0')

  if (!Number.isFinite(amount) || amount <= 0 || amount === planned) {
    whatIf.value = null
    return
  }

  whatIfTimer = setTimeout(() => {
    if (!debt.value) return
    void debts
      .projectPayoff(debt.value.id, amount.toFixed(2))
      .then((result) => {
        whatIf.value = result
      })
      .catch(() => {
        whatIf.value = null
      })
  }, 400)
})

async function confirmDelete(): Promise<void> {
  if (!debt.value) return
  deleting.value = true
  try {
    await debts.remove(debt.value.id)
    ui.success('Debt deleted')
    void router.push('/debts')
  } finally {
    deleting.value = false
    confirmingDelete.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="debts.loading && !debt" :rows="3" />

    <div v-else-if="debt">
      <PageHeader :title="debt.name" :subtitle="debt.type_label" back-to="/debts">
        <template #actions>
          <div class="flex gap-2">
            <button
              type="button"
              class="btn btn-secondary !min-h-11 !w-11 !p-0"
              aria-label="Edit debt"
              @click="editing = true"
            >
              <Pencil class="h-4 w-4" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="btn btn-secondary !min-h-11 !w-11 !p-0 text-over"
              aria-label="Delete debt"
              @click="confirmingDelete = true"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
        </template>
      </PageHeader>

      <div class="space-y-5">
        <!-- Balance and progress -->
        <section class="card p-5">
          <p class="eyebrow">Current balance</p>
          <MoneyText :amount="debt.current_balance" size="3xl" class="mt-1 block font-bold" />

          <BudgetProgress
            class="mt-4"
            :percentage="debt.progress_percentage"
            status="safe"
            height="lg"
            :label="`${debt.progress_percentage.toFixed(0)}% paid off`"
          />

          <div class="mt-2 flex items-center justify-between text-sm">
            <span class="text-ink-muted">{{ debt.progress_percentage.toFixed(0) }}% paid off</span>
            <span class="text-ink-muted">
              from <MoneyText :amount="debt.original_amount" size="sm" class="font-semibold" />
            </span>
          </div>

          <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-line pt-4">
            <div>
              <dt class="text-xs text-ink-subtle">Minimum payment</dt>
              <dd class="mt-0.5"><MoneyText :amount="debt.minimum_payment" size="sm" class="font-semibold" /></dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Planned payment</dt>
              <dd class="mt-0.5"><MoneyText :amount="debt.planned_payment" size="sm" class="font-semibold" /></dd>
            </div>
            <div v-if="debt.credit_limit">
              <dt class="text-xs text-ink-subtle">Credit limit</dt>
              <dd class="mt-0.5"><MoneyText :amount="debt.credit_limit" size="sm" class="font-semibold" /></dd>
            </div>
            <div v-if="debt.utilisation_percentage !== null">
              <dt class="text-xs text-ink-subtle">Utilisation</dt>
              <dd class="tabular mt-0.5 text-sm font-semibold text-ink">
                {{ debt.utilisation_percentage.toFixed(0) }}%
              </dd>
            </div>
            <div v-if="debt.interest_rate">
              <dt class="text-xs text-ink-subtle">Interest rate</dt>
              <dd class="tabular mt-0.5 text-sm font-semibold text-ink">
                {{ Number.parseFloat(debt.interest_rate).toFixed(2) }}% a year
              </dd>
            </div>
            <div v-if="debt.due_day">
              <dt class="text-xs text-ink-subtle">Due day</dt>
              <dd class="tabular mt-0.5 text-sm font-semibold text-ink">{{ debt.due_day }}</dd>
            </div>
            <div v-if="debt.remaining_installments !== null">
              <dt class="text-xs text-ink-subtle">Installments left</dt>
              <dd class="tabular mt-0.5 text-sm font-semibold text-ink">{{ debt.remaining_installments }}</dd>
            </div>
            <div v-if="debt.scheduled_remaining">
              <dt class="text-xs text-ink-subtle">Scheduled remaining</dt>
              <dd class="mt-0.5"><MoneyText :amount="debt.scheduled_remaining" size="sm" class="font-semibold" /></dd>
            </div>
          </dl>

          <!-- Never present the schedule total as a settlement figure. -->
          <p
            v-if="debt.type === 'installment'"
            class="mt-4 flex items-start gap-1.5 rounded-[var(--radius-field)] bg-info-soft p-3 text-xs text-ink-muted"
          >
            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0 text-info" aria-hidden="true" />
            <span>
              The scheduled remaining amount is what you would pay by following the schedule.
              <template v-if="debt.early_settlement_amount">
                Your quoted early settlement is
                <MoneyText :amount="debt.early_settlement_amount" size="xs" class="font-bold text-ink" />.
              </template>
              <template v-else>
                Settling early usually costs less — ask your provider for a figure.
              </template>
            </span>
          </p>

          <button type="button" class="btn btn-primary mt-5 w-full !text-base" @click="paying = true">
            <Banknote class="h-4 w-4" aria-hidden="true" />
            Record a payment
          </button>
        </section>

        <!-- Payoff projection -->
        <section v-if="payoff" class="card p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h2 class="eyebrow">Payoff estimate</h2>
              <p v-if="payoff.will_be_paid_off && payoff.estimated_months" class="mt-1 text-lg font-bold text-ink">
                About {{ payoff.estimated_months }}
                {{ payoff.estimated_months === 1 ? 'month' : 'months' }}
              </p>
              <p v-else-if="payoff.estimated_months === 0" class="mt-1 text-lg font-bold text-safe">
                Cleared
              </p>
              <p v-else class="mt-1 text-sm font-semibold text-warn">Cannot be estimated yet</p>

              <p v-if="payoff.estimated_payoff_label" class="text-sm text-ink-muted">
                Around {{ payoff.estimated_payoff_label }}
              </p>
            </div>

            <span v-if="whatIf" class="badge bg-info-soft text-info">What if</span>
          </div>

          <div
            v-if="payoff.warning"
            class="mt-3 flex items-start gap-2 rounded-[var(--radius-field)] bg-warn-soft p-3 text-sm text-warn"
            role="alert"
          >
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{{ payoff.warning }}</span>
          </div>

          <dl v-else class="mt-4 grid grid-cols-2 gap-4">
            <div>
              <dt class="text-xs text-ink-subtle">Monthly payment</dt>
              <dd class="mt-0.5"><MoneyText :amount="payoff.monthly_payment" size="sm" class="font-semibold" /></dd>
            </div>
            <div v-if="payoff.has_interest">
              <dt class="text-xs text-ink-subtle">Estimated interest</dt>
              <dd class="mt-0.5">
                <MoneyText :amount="payoff.estimated_total_interest" size="sm" class="font-semibold text-warn" />
              </dd>
            </div>
            <div>
              <dt class="text-xs text-ink-subtle">Total to pay</dt>
              <dd class="mt-0.5"><MoneyText :amount="payoff.estimated_total_paid" size="sm" class="font-semibold" /></dd>
            </div>
          </dl>

          <div v-if="scheduleChart" class="mt-5">
            <LineChart
              :labels="scheduleChart.labels"
              :values="scheduleChart.values"
              label="Remaining balance"
              tone="over"
              :height="200"
            />
          </div>

          <div class="mt-5 border-t border-line pt-4">
            <MoneyInput
              v-model="whatIfPayment"
              label="What if I paid this each month?"
              hint="Change the amount to see how the estimate moves. Nothing is saved."
            />
          </div>

          <p class="mt-3 flex items-start gap-1.5 text-xs text-ink-subtle">
            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ payoff.note }} New spending on this account will change it.</span>
          </p>
        </section>

        <!-- Payment schedule -->
        <section v-if="payoff?.schedule.length" class="card overflow-hidden">
          <h2 class="eyebrow px-5 pt-5">Estimated schedule</h2>
          <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-y border-line bg-sunken text-left">
                  <th scope="col" class="px-5 py-2 font-semibold text-ink-muted">Month</th>
                  <th scope="col" class="px-3 py-2 text-right font-semibold text-ink-muted">Payment</th>
                  <th v-if="payoff.has_interest" scope="col" class="px-3 py-2 text-right font-semibold text-ink-muted">
                    Interest
                  </th>
                  <th scope="col" class="px-5 py-2 text-right font-semibold text-ink-muted">Remaining</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="row in payoff.schedule" :key="row.month_number">
                  <td class="whitespace-nowrap px-5 py-2.5 text-ink">{{ row.label }}</td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right">
                    <MoneyText :amount="row.payment" size="sm" />
                  </td>
                  <td v-if="payoff.has_interest" class="whitespace-nowrap px-3 py-2.5 text-right">
                    <MoneyText :amount="row.interest" size="sm" class="text-warn" />
                  </td>
                  <td class="whitespace-nowrap px-5 py-2.5 text-right font-semibold">
                    <MoneyText :amount="row.remaining_balance" size="sm" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Payment history -->
        <section>
          <h2 class="mb-3 text-base font-semibold text-ink">Payment history</h2>

          <ul v-if="debt.payments?.length" class="card divide-y divide-line px-5">
            <li v-for="payment in debt.payments" :key="payment.id" class="flex items-center justify-between gap-3 py-3">
              <div class="min-w-0">
                <MoneyText :amount="payment.amount" size="sm" class="font-semibold" />
                <p class="text-xs text-ink-subtle">
                  {{ formatDate(payment.payment_date, true) }}
                  <template v-if="payment.notes"> · {{ payment.notes }}</template>
                </p>
              </div>
              <div class="shrink-0 text-right">
                <p class="text-xs text-ink-subtle">Balance after</p>
                <MoneyText :amount="payment.balance_after ?? '0'" size="sm" class="font-semibold" />
              </div>
            </li>
          </ul>

          <EmptyState
            v-else
            :icon="Banknote"
            title="No payments recorded"
            description="Record a payment to start tracking this debt coming down."
            action-label="Record a payment"
            @action="paying = true"
          />
        </section>
      </div>

      <DebtFormSheet :open="editing" :debt="debt" @close="editing = false" @saved="load" />
      <DebtPaymentSheet :open="paying" :debt="debt" @close="paying = false" @saved="load" />

      <ConfirmDialog
        :open="confirmingDelete"
        title="Delete this debt?"
        message="The debt and its payment history will be removed. This cannot be undone."
        confirm-label="Delete"
        destructive
        :busy="deleting"
        @confirm="confirmDelete"
        @cancel="confirmingDelete = false"
      />
    </div>
  </div>
</template>
