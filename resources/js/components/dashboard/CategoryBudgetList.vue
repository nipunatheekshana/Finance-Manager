<script setup lang="ts">
import { computed } from 'vue'
import CategoryIcon from '@/components/common/CategoryIcon.vue'
import MoneyText from '@/components/common/MoneyText.vue'
import BudgetProgress from '@/components/common/BudgetProgress.vue'
import { AlertTriangle, XCircle } from 'lucide-vue-next'
import type { CategorySummary } from '@/types'

const props = withDefaults(
  defineProps<{
    categories: CategorySummary[]
    /** Only show categories the user has actually set a limit on. */
    budgetedOnly?: boolean
    limit?: number
  }>(),
  { budgetedOnly: true },
)

const rows = computed(() => {
  const filtered = props.budgetedOnly
    ? props.categories.filter((category) => category.has_budget)
    : props.categories

  // Closest to the limit first — that is what needs attention.
  const sorted = [...filtered].sort((a, b) => b.percentage_used - a.percentage_used)

  return props.limit ? sorted.slice(0, props.limit) : sorted
})
</script>

<template>
  <ul v-if="rows.length" class="space-y-3.5">
    <li v-for="category in rows" :key="category.category_id">
      <div class="flex items-center gap-3">
        <CategoryIcon :icon="category.icon" :color="category.color" size="sm" />

        <div class="min-w-0 flex-1">
          <div class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium text-ink">{{ category.name }}</span>
            <span class="tabular shrink-0 text-sm text-ink-muted">
              <MoneyText :amount="category.spent" size="sm" class="font-semibold text-ink" compact />
              /
              <MoneyText :amount="category.budget" size="sm" compact />
            </span>
          </div>

          <BudgetProgress
            class="mt-1.5"
            height="sm"
            :percentage="category.percentage_used"
            :status="category.status"
            :marker-at="category.warning_percentage"
            show-marker
            :label="`${category.name}: ${category.percentage_used.toFixed(0)}% of budget used`"
          />

          <!-- Status is spelled out, not left to colour alone. -->
          <p
            v-if="category.status === 'over'"
            class="mt-1 flex items-center gap-1 text-xs font-medium text-over"
          >
            <XCircle class="h-3 w-3" aria-hidden="true" />
            Over by <MoneyText :amount="category.remaining" size="xs" class="font-semibold" />
          </p>
          <p
            v-else-if="category.status === 'warning'"
            class="mt-1 flex items-center gap-1 text-xs font-medium text-warn"
          >
            <AlertTriangle class="h-3 w-3" aria-hidden="true" />
            {{ category.percentage_used.toFixed(0) }}% used
          </p>
        </div>
      </div>
    </li>
  </ul>
</template>
