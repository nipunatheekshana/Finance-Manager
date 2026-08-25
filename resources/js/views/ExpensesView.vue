<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Filter, PlusCircle, Receipt, Search, X } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import SelectField from '@/components/common/SelectField.vue'
import TextField from '@/components/common/TextField.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import BottomSheet from '@/components/common/BottomSheet.vue'
import { useExpenseStore } from '@/stores/expenses'
import { useUiStore } from '@/stores/ui'
import { relativeDay } from '@/composables/useDates'
import type { ExpenseFilters } from '@/types'

/**
 * The filter sheet binds straight to inputs, so every field is present and
 * nullable rather than optional — `undefined` has no meaning in a form.
 */
interface DraftFilters {
  category_id: number | null
  payment_method_id: number | null
  from: string
  to: string
  min_amount: string
  max_amount: string
  sort: NonNullable<ExpenseFilters['sort']>
}

const EMPTY_DRAFT: DraftFilters = {
  category_id: null,
  payment_method_id: null,
  from: '',
  to: '',
  min_amount: '',
  max_amount: '',
  sort: 'date_desc',
}

const expenses = useExpenseStore()
const ui = useUiStore()

const search = ref('')
const filtersOpen = ref(false)
const draftFilters = ref<DraftFilters>({ ...EMPTY_DRAFT })

const SORT_OPTIONS = [
  { value: 'date_desc', label: 'Newest first' },
  { value: 'date_asc', label: 'Oldest first' },
  { value: 'amount_desc', label: 'Largest first' },
  { value: 'amount_asc', label: 'Smallest first' },
]

const activeFilterCount = computed(() => {
  const active = expenses.filters
  return [
    active.category_id,
    active.payment_method_id,
    active.from,
    active.to,
    active.min_amount,
    active.max_amount,
  ].filter((value) => value !== null && value !== undefined && value !== '').length
})

let searchTimer: ReturnType<typeof setTimeout> | undefined

/** Debounced so typing does not fire a request per keystroke. */
watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    expenses.setFilters({ search: value })
    void expenses.fetch()
  }, 350)
})

function openFilters(): void {
  const active = expenses.filters
  draftFilters.value = {
    category_id: active.category_id ?? null,
    payment_method_id: active.payment_method_id ?? null,
    from: active.from ?? '',
    to: active.to ?? '',
    min_amount: active.min_amount ?? '',
    max_amount: active.max_amount ?? '',
    sort: active.sort ?? 'date_desc',
  }
  filtersOpen.value = true
}

async function applyFilters(): Promise<void> {
  const draft = draftFilters.value

  // A blank string means "no filter", not a value to send.
  expenses.setFilters({
    category_id: draft.category_id,
    payment_method_id: draft.payment_method_id,
    from: draft.from || null,
    to: draft.to || null,
    min_amount: draft.min_amount || null,
    max_amount: draft.max_amount || null,
    sort: draft.sort,
  })

  filtersOpen.value = false
  await expenses.fetch()
}

async function clearFilters(): Promise<void> {
  search.value = ''
  draftFilters.value = { ...EMPTY_DRAFT }
  expenses.resetFilters()
  filtersOpen.value = false
  await expenses.fetch()
}

function dayTotal(items: Array<{ amount: string }>): string {
  return items.reduce((total, item) => total + Number.parseFloat(item.amount), 0).toFixed(2)
}

onMounted(async () => {
  await expenses.loadReference()
  await expenses.fetch()
})
</script>

<template>
  <div>
    <PageHeader title="Expenses">
      <template #actions>
        <button type="button" class="btn btn-primary !px-3" @click="ui.openExpenseSheet()">
          <PlusCircle class="h-4 w-4" aria-hidden="true" />
          <span class="hidden sm:inline">Add</span>
          <span class="sr-only sm:hidden">Add expense</span>
        </button>
      </template>
    </PageHeader>

    <!-- Search and filters -->
    <div class="mb-4 flex gap-2">
      <div class="relative flex-1">
        <Search
          class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle"
          aria-hidden="true"
        />
        <input
          v-model="search"
          type="search"
          inputmode="search"
          placeholder="Search expenses"
          aria-label="Search expenses"
          class="input pl-10"
        />
      </div>

      <button
        type="button"
        class="btn btn-secondary shrink-0 !px-3"
        :class="activeFilterCount > 0 ? 'text-brand' : ''"
        @click="openFilters"
      >
        <Filter class="h-4 w-4" aria-hidden="true" />
        <span class="sr-only">Filters</span>
        <span
          v-if="activeFilterCount > 0"
          class="tabular ml-0.5 rounded-full bg-brand px-1.5 text-xs font-bold text-on-brand"
        >
          {{ activeFilterCount }}
        </span>
      </button>
    </div>

    <div
      v-if="activeFilterCount > 0 || search"
      class="mb-4 flex items-center justify-between rounded-[var(--radius-field)] bg-sunken px-3.5 py-2.5"
    >
      <p class="text-sm text-ink-muted">
        <span class="tabular font-semibold text-ink">{{ expenses.total }}</span>
        {{ expenses.total === 1 ? 'expense' : 'expenses' }} ·
        <MoneyText :amount="expenses.filteredTotal" size="sm" class="font-semibold text-ink" />
      </p>
      <button type="button" class="flex items-center gap-1 text-sm font-semibold text-brand" @click="clearFilters">
        <X class="h-3.5 w-3.5" aria-hidden="true" />
        Clear
      </button>
    </div>

    <LoadingState v-if="expenses.loading" variant="list" :rows="6" />

    <EmptyState
      v-else-if="expenses.items.length === 0"
      :icon="Receipt"
      :title="activeFilterCount > 0 || search ? 'No matching expenses' : 'No expenses yet'"
      :description="
        activeFilterCount > 0 || search
          ? 'Try widening your search or clearing the filters.'
          : 'Start tracking your spending to see where your money goes.'
      "
      :action-label="activeFilterCount > 0 || search ? 'Clear filters' : 'Add expense'"
      @action="activeFilterCount > 0 || search ? clearFilters() : ui.openExpenseSheet()"
    />

    <div v-else class="space-y-5">
      <section v-for="group in expenses.grouped" :key="group.date">
        <div class="mb-2 flex items-baseline justify-between">
          <h2 class="eyebrow">{{ relativeDay(group.date) }}</h2>
          <MoneyText :amount="dayTotal(group.expenses)" size="sm" class="font-semibold text-ink-muted" />
        </div>

        <ul class="card divide-y divide-line px-4">
          <li v-for="expense in group.expenses" :key="expense.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 py-3 text-left transition hover:opacity-80"
              @click="ui.openExpenseSheet(expense.id)"
            >
              <CategoryIcon
                :icon="expense.category?.icon ?? 'circle'"
                :color="expense.category?.color ?? 'slate'"
                size="sm"
              />

              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">
                  {{ expense.description || expense.category?.name }}
                </p>
                <p class="truncate text-xs text-ink-subtle">
                  {{ expense.category?.name }} · {{ expense.payment_method?.name }}
                </p>
              </div>

              <MoneyText :amount="expense.amount" size="sm" class="shrink-0 font-semibold" />
            </button>
          </li>
        </ul>
      </section>

      <button
        v-if="expenses.hasMore"
        type="button"
        class="btn btn-secondary w-full"
        :disabled="expenses.loadingMore"
        @click="expenses.loadMore()"
      >
        {{ expenses.loadingMore ? 'Loading…' : 'Load more' }}
      </button>

      <p v-else class="text-center text-sm text-ink-subtle">
        That is all {{ expenses.total }} {{ expenses.total === 1 ? 'expense' : 'expenses' }}.
      </p>
    </div>

    <!-- Filter sheet -->
    <BottomSheet :open="filtersOpen" title="Filter expenses" @close="filtersOpen = false">
      <div class="space-y-4 pb-2">
        <SelectField
          v-model="draftFilters.category_id"
          :options="expenses.activeCategories.map((c) => ({ value: c.id, label: c.name }))"
          label="Category"
          placeholder="All categories"
        />

        <SelectField
          v-model="draftFilters.payment_method_id"
          :options="expenses.activePaymentMethods.map((m) => ({ value: m.id, label: m.name }))"
          label="Payment method"
          placeholder="All payment methods"
        />

        <div class="grid grid-cols-2 gap-3">
          <TextField v-model="draftFilters.from" label="From" type="date" />
          <TextField v-model="draftFilters.to" label="To" type="date" />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <TextField
            v-model="draftFilters.min_amount"
            label="Min amount"
            type="number"
            inputmode="decimal"
          />
          <TextField
            v-model="draftFilters.max_amount"
            label="Max amount"
            type="number"
            inputmode="decimal"
          />
        </div>

        <SelectField v-model="draftFilters.sort" :options="SORT_OPTIONS" label="Sort by" />
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button type="button" class="btn btn-secondary flex-1" @click="clearFilters">Clear all</button>
          <button type="button" class="btn btn-primary flex-1" @click="applyFilters">Apply</button>
        </div>
      </template>
    </BottomSheet>
  </div>
</template>
