<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import ToastHost from '@/components/common/ToastHost.vue'
import UpdatePrompt from '@/components/layout/UpdatePrompt.vue'
import OfflineBanner from '@/components/layout/OfflineBanner.vue'
import { useExpenseStore } from '@/stores/expenses'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const expenses = useExpenseStore()

onMounted(() => {
  // Anything captured offline in a previous session goes out on first load.
  if (navigator.onLine && expenses.pendingCount > 0) {
    void expenses.syncPending().catch(() => {
      /* Retried on the next reconnect. */
    })
  }
})
</script>

<template>
  <div class="min-h-full">
    <OfflineBanner v-if="!ui.isOnline" />
    <RouterView v-slot="{ Component }">
      <component :is="Component" />
    </RouterView>
    <ToastHost />
    <UpdatePrompt />
  </div>
</template>
