import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/services/api'
import { newClientUuid, offlineQueue } from '@/services/offlineQueue'
import type {
  ApiCollection,
  Category,
  Expense,
  ExpenseDraft,
  ExpenseFilters,
  ExpenseImpact,
  Paginated,
  PaymentMethod,
  WeekStateAfterSave,
} from '@/types'

export const useExpenseStore = defineStore('expenses', () => {
  const items = ref<Expense[]>([])
  const categories = ref<Category[]>([])
  const paymentMethods = ref<PaymentMethod[]>([])

  const loading = ref(false)
  const loadingMore = ref(false)
  const saving = ref(false)
  const referenceLoaded = ref(false)

  const page = ref(1)
  const lastPage = ref(1)
  const total = ref(0)
  const filteredTotal = ref('0.00')

  const filters = ref<ExpenseFilters>({ sort: 'date_desc' })
  const pendingCount = ref(offlineQueue.count())

  const hasMore = computed(() => page.value < lastPage.value)
  const activeCategories = computed(() => categories.value.filter((category) => category.active))
  const activePaymentMethods = computed(() => paymentMethods.value.filter((method) => method.active))

  const defaultCategory = computed(() => activeCategories.value[0] ?? null)
  const defaultPaymentMethod = computed(
    () => activePaymentMethods.value.find((method) => method.is_default) ?? activePaymentMethods.value[0] ?? null,
  )

  /** Group the loaded list by date for the history view's day headings. */
  const grouped = computed(() => {
    const groups = new Map<string, Expense[]>()
    for (const expense of items.value) {
      const bucket = groups.get(expense.expense_date) ?? []
      bucket.push(expense)
      groups.set(expense.expense_date, bucket)
    }
    return Array.from(groups, ([date, expenses]) => ({ date, expenses }))
  })

  function queryParams(targetPage: number): Record<string, unknown> {
    const active = filters.value
    return {
      page: targetPage,
      per_page: 25,
      search: active.search || undefined,
      category_id: active.category_id || undefined,
      payment_method_id: active.payment_method_id || undefined,
      from: active.from || undefined,
      to: active.to || undefined,
      min_amount: active.min_amount || undefined,
      max_amount: active.max_amount || undefined,
      sort: active.sort,
    }
  }

  async function loadReference(force = false): Promise<void> {
    if (referenceLoaded.value && !force) return

    const [categoryResponse, methodResponse] = await Promise.all([
      api.get<ApiCollection<Category>>('/categories'),
      api.get<ApiCollection<PaymentMethod>>('/payment-methods'),
    ])

    categories.value = categoryResponse.data
    paymentMethods.value = methodResponse.data
    referenceLoaded.value = true
  }

  async function fetch(reset = true): Promise<void> {
    if (reset) {
      loading.value = true
      page.value = 1
    } else {
      loadingMore.value = true
    }

    try {
      const targetPage = reset ? 1 : page.value + 1
      const response = await api.get<Paginated<Expense> & { meta: { filtered_total: string } }>(
        '/expenses',
        { params: queryParams(targetPage) },
      )

      items.value = reset ? response.data : [...items.value, ...response.data]
      page.value = response.meta.current_page
      lastPage.value = response.meta.last_page
      total.value = response.meta.total
      filteredTotal.value = response.meta.filtered_total ?? '0.00'
    } finally {
      loading.value = false
      loadingMore.value = false
    }
  }

  async function loadMore(): Promise<void> {
    if (!hasMore.value || loadingMore.value) return
    await fetch(false)
  }

  function setFilters(next: Partial<ExpenseFilters>): void {
    filters.value = { ...filters.value, ...next }
  }

  function resetFilters(): void {
    filters.value = { sort: 'date_desc' }
  }

  /**
   * Create an expense. When offline the entry is queued locally and reported
   * back so the UI can tell the user it will sync later.
   */
  async function create(
    draft: ExpenseDraft,
  ): Promise<{ expense: Expense | null; queued: boolean; week?: WeekStateAfterSave | null }> {
    const payload: ExpenseDraft = { ...draft, client_uuid: draft.client_uuid ?? newClientUuid() }

    if (!navigator.onLine) {
      offlineQueue.enqueue(payload)
      pendingCount.value = offlineQueue.count()
      return { expense: null, queued: true }
    }

    saving.value = true
    try {
      const response = await api.post<{ data: Expense; week: WeekStateAfterSave | null }>(
        '/expenses',
        payload,
      )
      items.value = [response.data, ...items.value]
      return { expense: response.data, queued: false, week: response.week }
    } catch (error) {
      // A network failure mid-request still deserves the offline path.
      if (!navigator.onLine) {
        offlineQueue.enqueue(payload)
        pendingCount.value = offlineQueue.count()
        return { expense: null, queued: true }
      }
      throw error
    } finally {
      saving.value = false
    }
  }

  async function update(
    id: number,
    draft: Partial<ExpenseDraft>,
  ): Promise<{ expense: Expense; week: WeekStateAfterSave | null }> {
    saving.value = true
    try {
      const response = await api.put<{ data: Expense; week: WeekStateAfterSave | null }>(
        `/expenses/${id}`,
        draft,
      )
      items.value = items.value.map((expense) => (expense.id === id ? response.data : expense))
      return { expense: response.data, week: response.week }
    } finally {
      saving.value = false
    }
  }

  /**
   * What an expense would do to the budgets, without saving it. Used to warn
   * before the money is committed rather than only after.
   */
  async function previewImpact(draft: {
    amount: string
    expense_date?: string
    category_id?: number | null
    expense_id?: number | null
  }): Promise<ExpenseImpact> {
    const response = await api.post<{ data: ExpenseImpact }>('/expenses/preview', draft)
    return response.data
  }

  async function remove(id: number): Promise<void> {
    await api.delete(`/expenses/${id}`)
    items.value = items.value.filter((expense) => expense.id !== id)
  }

  function find(id: number): Expense | undefined {
    return items.value.find((expense) => expense.id === id)
  }

  /**
   * Send everything captured offline. Entries the server accepts are dropped
   * from the queue; the uuid makes a repeated attempt harmless.
   */
  async function syncPending(): Promise<number> {
    const queued = offlineQueue.all()
    if (queued.length === 0) return 0

    const response = await api.post<{
      synced: Expense[]
      failed: Array<{ client_uuid: string | null; message: string }>
    }>('/expenses/sync', {
      expenses: queued.map((entry) => ({
        amount: entry.amount,
        category_id: entry.category_id,
        payment_method_id: entry.payment_method_id,
        expense_date: entry.expense_date,
        description: entry.description ?? null,
        client_uuid: entry.client_uuid,
      })),
    })

    const syncedUuids = response.synced
      .map((expense) => expense.client_uuid)
      .filter((uuid): uuid is string => uuid !== null)

    offlineQueue.remove(syncedUuids)
    pendingCount.value = offlineQueue.count()

    return response.synced.length
  }

  function categoryById(id: number | null | undefined): Category | undefined {
    if (id === null || id === undefined) return undefined
    return categories.value.find((category) => category.id === id)
  }

  function paymentMethodById(id: number | null | undefined): PaymentMethod | undefined {
    if (id === null || id === undefined) return undefined
    return paymentMethods.value.find((method) => method.id === id)
  }

  return {
    items,
    categories,
    paymentMethods,
    loading,
    loadingMore,
    saving,
    page,
    lastPage,
    total,
    filteredTotal,
    filters,
    pendingCount,
    hasMore,
    grouped,
    activeCategories,
    activePaymentMethods,
    defaultCategory,
    defaultPaymentMethod,
    loadReference,
    fetch,
    loadMore,
    setFilters,
    resetFilters,
    create,
    update,
    previewImpact,
    remove,
    find,
    syncPending,
    categoryById,
    paymentMethodById,
  }
})
