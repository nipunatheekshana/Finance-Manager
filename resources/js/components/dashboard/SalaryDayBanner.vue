<script setup lang="ts">
import { computed } from 'vue'
import { CalendarClock, PartyPopper } from 'lucide-vue-next'
import MoneyText from '@/components/common/MoneyText.vue'
import { relativeDay } from '@/composables/useDates'
import type { SalarySection } from '@/types'

const props = defineProps<{ salary: SalarySection }>()

const heading = computed(() => {
  if (props.salary.is_salary_day) return 'Salary day'
  return props.salary.has_pay_day ? 'Time to plan this cycle' : 'Plan this cycle'
})

const body = computed(() => {
  if (props.salary.is_salary_day) {
    return 'Your salary is due today. Set out where it should go before you start spending.'
  }

  // Without a pay day there is no date to count down to, so lead with what
  // the plan will actually be funded by.
  if (!props.salary.has_pay_day) {
    return `Set out where this cycle's money should go. ${props.salary.funding_explanation ?? ''}`.trim()
  }

  return `Your next salary is ${relativeDay(props.salary.next_salary_date).toLowerCase()}. Get this cycle's plan ready.`
})
</script>

<template>
  <RouterLink
    to="/plan"
    class="block rounded-[var(--radius-card)] bg-brand-soft p-4 transition hover:shadow-[var(--shadow-card)]"
  >
    <div class="flex items-start gap-3">
      <component
        :is="salary.is_salary_day ? PartyPopper : CalendarClock"
        class="mt-0.5 h-5 w-5 shrink-0 text-brand"
        aria-hidden="true"
      />

      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold text-ink">{{ heading }}</p>
        <p class="mt-0.5 text-sm text-ink-muted">{{ body }}</p>

        <p class="mt-2 text-sm">
          <span class="text-ink-muted">{{ salary.has_pay_day ? 'Expected ' : 'Planned ' }}</span>
          <MoneyText :amount="salary.expected" size="sm" class="font-semibold text-ink" />
        </p>

        <span class="mt-2.5 inline-block text-sm font-semibold text-brand">
          Start planning →
        </span>
      </div>
    </div>
  </RouterLink>
</template>
