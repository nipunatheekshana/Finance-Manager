<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Banknote, ChevronLeft, ChevronRight, CreditCard, PiggyBank, Receipt, Wallet } from 'lucide-vue-next'
import PageHeader from '@/components/layout/PageHeader.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import { api } from '@/services/api'
import { formatLongDate, todayIso } from '@/composables/useDates'
import type { CalendarEvent } from '@/types'

const today = new Date()
const year = ref(today.getFullYear())
const month = ref(today.getMonth() + 1)
const events = ref<CalendarEvent[]>([])
const loading = ref(true)
const selectedDate = ref<string | null>(todayIso())

const ICONS = {
  salary: Banknote,
  bill: Receipt,
  debt: CreditCard,
  expense: Wallet,
  savings: PiggyBank,
} as const

const TINTS = {
  salary: 'bg-safe',
  bill: 'bg-info',
  debt: 'bg-over',
  expense: 'bg-warn',
  savings: 'bg-brand',
} as const

const monthLabel = computed(() =>
  new Date(year.value, month.value - 1, 1).toLocaleDateString('en-GB', {
    month: 'long',
    year: 'numeric',
  }),
)

/** Events keyed by date, so a day cell can render its markers cheaply. */
const byDate = computed(() => {
  const map = new Map<string, CalendarEvent[]>()
  for (const event of events.value) {
    const bucket = map.get(event.date) ?? []
    bucket.push(event)
    map.set(event.date, bucket)
  }
  return map
})

/** A 6-week grid starting on Monday, with leading and trailing blanks. */
const grid = computed(() => {
  const first = new Date(year.value, month.value - 1, 1)
  const daysInMonth = new Date(year.value, month.value, 0).getDate()

  // getDay() is Sunday-based; shift so Monday is index 0.
  const leading = (first.getDay() + 6) % 7

  const cells: Array<{ date: string; day: number } | null> = Array.from({ length: leading }, () => null)

  for (let day = 1; day <= daysInMonth; day++) {
    const iso = `${year.value}-${String(month.value).padStart(2, '0')}-${String(day).padStart(2, '0')}`
    cells.push({ date: iso, day })
  }

  while (cells.length % 7 !== 0) cells.push(null)

  return cells
})

const selectedEvents = computed(() =>
  selectedDate.value ? (byDate.value.get(selectedDate.value) ?? []) : [],
)

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

async function load(): Promise<void> {
  loading.value = true
  try {
    const response = await api.get<{ data: CalendarEvent[] }>('/calendar', {
      params: { year: year.value, month: month.value },
    })
    events.value = response.data
  } finally {
    loading.value = false
  }
}

function shiftMonth(delta: number): void {
  const next = new Date(year.value, month.value - 1 + delta, 1)
  year.value = next.getFullYear()
  month.value = next.getMonth() + 1
  selectedDate.value = null
}

watch([year, month], load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Calendar" subtitle="Every dated event in your finances." />

    <div class="mb-4 flex items-center justify-between">
      <button
        type="button"
        class="btn btn-secondary !min-h-11 !w-11 !p-0"
        aria-label="Previous month"
        @click="shiftMonth(-1)"
      >
        <ChevronLeft class="h-5 w-5" aria-hidden="true" />
      </button>

      <h2 class="text-base font-semibold text-ink">{{ monthLabel }}</h2>

      <button
        type="button"
        class="btn btn-secondary !min-h-11 !w-11 !p-0"
        aria-label="Next month"
        @click="shiftMonth(1)"
      >
        <ChevronRight class="h-5 w-5" aria-hidden="true" />
      </button>
    </div>

    <LoadingState v-if="loading" :rows="2" />

    <div v-else class="space-y-5">
      <div class="card p-3">
        <div class="grid grid-cols-7 gap-1">
          <div
            v-for="weekday in WEEKDAYS"
            :key="weekday"
            class="pb-1 text-center text-[0.625rem] font-bold uppercase tracking-wide text-ink-subtle"
          >
            {{ weekday }}
          </div>

          <template v-for="(cell, index) in grid" :key="index">
            <div v-if="cell === null" aria-hidden="true" />

            <button
              v-else
              type="button"
              class="relative flex aspect-square min-h-11 flex-col items-center justify-center rounded-[var(--radius-field)] transition"
              :class="[
                selectedDate === cell.date ? 'bg-brand text-on-brand' : 'hover:bg-sunken',
                cell.date === todayIso() && selectedDate !== cell.date ? 'ring-1 ring-brand' : '',
              ]"
              :aria-label="`${formatLongDate(cell.date)}, ${(byDate.get(cell.date) ?? []).length} events`"
              :aria-pressed="selectedDate === cell.date"
              @click="selectedDate = cell.date"
            >
              <span class="tabular text-sm font-medium">{{ cell.day }}</span>

              <!-- Up to three markers, one per event kind. -->
              <span class="mt-0.5 flex h-1.5 gap-0.5">
                <span
                  v-for="(event, dot) in (byDate.get(cell.date) ?? []).slice(0, 3)"
                  :key="dot"
                  class="h-1.5 w-1.5 rounded-full"
                  :class="selectedDate === cell.date ? 'bg-white/70' : TINTS[event.kind]"
                  aria-hidden="true"
                />
              </span>
            </button>
          </template>
        </div>
      </div>

      <!-- Legend, so the dots are not colour-only information. -->
      <ul class="flex flex-wrap gap-x-4 gap-y-2">
        <li v-for="(tint, kind) in TINTS" :key="kind" class="flex items-center gap-1.5">
          <span class="h-2 w-2 rounded-full" :class="tint" aria-hidden="true" />
          <span class="text-xs capitalize text-ink-muted">{{ kind }}</span>
        </li>
      </ul>

      <section v-if="selectedDate">
        <h2 class="mb-3 text-base font-semibold text-ink">{{ formatLongDate(selectedDate) }}</h2>

        <ul v-if="selectedEvents.length" class="card divide-y divide-line px-4">
          <li v-for="(event, index) in selectedEvents" :key="index" class="flex items-center gap-3 py-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sunken">
              <component :is="ICONS[event.kind]" class="h-4 w-4 text-ink-muted" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">{{ event.name }}</p>
              <p class="text-xs capitalize text-ink-subtle">{{ event.kind }}</p>
            </div>

            <MoneyText
              :amount="event.direction === 'in' ? event.amount : `-${event.amount}`"
              size="sm"
              class="shrink-0 font-semibold"
              colored
              signed
            />
          </li>
        </ul>

        <p v-else class="rounded-[var(--radius-card)] border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted">
          Nothing scheduled on this day.
        </p>
      </section>
    </div>
  </div>
</template>
