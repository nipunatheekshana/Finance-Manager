<script setup lang="ts">
import { CheckCircle2, Plus } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import ProgressRing from '@/components/common/ProgressRing.vue'
import { formatDate } from '@/composables/useDates'
import type { SavingsGoal } from '@/types'

defineProps<{ goal: SavingsGoal }>()
defineEmits<{ addMoney: [] }>()
</script>

<template>
  <div class="card p-4">
    <div class="flex items-start gap-4">
      <ProgressRing
        :percentage="goal.percentage"
        :tone="goal.is_reached ? 'safe' : 'brand'"
        :size="64"
      />

      <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2">
          <RouterLink :to="`/savings/${goal.id}`" class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink">{{ goal.name }}</p>
          </RouterLink>

          <span v-if="goal.is_reached" class="badge shrink-0 bg-safe-soft text-safe">
            <CheckCircle2 class="h-3 w-3" aria-hidden="true" />
            Reached
          </span>
        </div>

        <p class="mt-1">
          <MoneyText :amount="goal.current_amount" size="lg" class="font-bold" />
          <span class="text-sm text-ink-subtle">
            / <MoneyText :amount="goal.target_amount" size="sm" />
          </span>
        </p>

        <p class="mt-1 text-xs text-ink-subtle">
          <MoneyText :amount="goal.monthly_target" size="xs" class="font-semibold" /> a month
          <template v-if="goal.target_date"> · by {{ formatDate(goal.target_date, true) }}</template>
        </p>
      </div>
    </div>

    <div class="mt-3 flex gap-2">
      <button type="button" class="btn btn-primary flex-1 !min-h-10 !text-sm" @click="$emit('addMoney')">
        <Plus class="h-4 w-4" aria-hidden="true" />
        Add money
      </button>
      <RouterLink :to="`/savings/${goal.id}`" class="btn btn-secondary !min-h-10 !text-sm">
        Details
      </RouterLink>
    </div>
  </div>
</template>
