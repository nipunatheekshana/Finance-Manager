<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import LoadingState from '@/components/common/LoadingState.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'
import type { NotificationType } from '@/types'

const auth = useAuthStore()
const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const settings = ref<Record<NotificationType, boolean>>({
  salary_day: true,
  upcoming_bills: true,
  debt_payments: true,
  budget_warnings: true,
  budget_exceeded: true,
  savings_goals: true,
  weekly_review: true,
  cycle_surplus: true,
})

const TYPES: Array<{ key: NotificationType; label: string; description: string }> = [
  { key: 'salary_day', label: 'Salary day', description: 'When your salary is due or has arrived.' },
  { key: 'upcoming_bills', label: 'Upcoming bills', description: 'A few days before a recurring bill is due.' },
  { key: 'debt_payments', label: 'Debt payments', description: 'Payment due dates and balance changes.' },
  { key: 'budget_warnings', label: 'Budget warnings', description: 'When a budget is close to its limit.' },
  { key: 'budget_exceeded', label: 'Budget exceeded', description: 'When you go over a weekly or category budget.' },
  { key: 'savings_goals', label: 'Savings goals', description: 'When you reach a savings target.' },
  { key: 'weekly_review', label: 'Weekly review', description: 'A prompt to review the week just finished.' },
  { key: 'cycle_surplus', label: 'Leftover money', description: 'When a finished cycle leaves money unspent.' },
]

async function save(): Promise<void> {
  saving.value = true
  try {
    await auth.updateProfile({ notification_settings: settings.value } as never)
    ui.success('Notification settings saved')
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not save your settings', error.message)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await auth.refreshProfile()
  if (auth.profile?.notification_settings) {
    settings.value = { ...settings.value, ...auth.profile.notification_settings }
  }
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader
      title="Notifications"
      subtitle="Choose which reminders appear on your dashboard."
      back-to="/settings"
    />

    <LoadingState v-if="loading" variant="list" :rows="6" />

    <div v-else class="space-y-5">
      <ul class="card divide-y divide-line">
        <li v-for="type in TYPES" :key="type.key">
          <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 px-4 py-3">
            <span class="min-w-0">
              <span class="block text-sm font-medium text-ink">{{ type.label }}</span>
              <span class="block text-xs text-ink-subtle">{{ type.description }}</span>
            </span>
            <input
              v-model="settings[type.key]"
              type="checkbox"
              class="h-5 w-5 shrink-0 rounded border-line accent-[rgb(var(--color-brand))]"
            />
          </label>
        </li>
      </ul>

      <button type="button" class="btn btn-primary w-full !text-base" :disabled="saving" @click="save">
        {{ saving ? 'Saving…' : 'Save preferences' }}
      </button>
    </div>
  </div>
</template>
