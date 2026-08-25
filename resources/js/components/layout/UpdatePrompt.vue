<script setup lang="ts">
import { ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import { useUiStore } from '@/stores/ui'
import { applyUpdate } from '@/registerServiceWorker'

const ui = useUiStore()
const updating = ref(false)

async function reload(): Promise<void> {
  updating.value = true
  await applyUpdate()
}
</script>

<template>
  <div
    v-if="ui.updateAvailable"
    class="fixed inset-x-3 bottom-24 z-[55] mx-auto max-w-md sm:bottom-6"
    role="status"
  >
    <div class="card flex items-center gap-3 p-3.5 shadow-[var(--shadow-raised)]">
      <RefreshCw class="h-5 w-5 shrink-0 text-brand" aria-hidden="true" />
      <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-ink">A new version is ready</p>
        <p class="text-sm text-ink-muted">Reload to get the latest changes.</p>
      </div>
      <button type="button" class="btn btn-primary !min-h-10 !px-4 !text-sm" :disabled="updating" @click="reload">
        {{ updating ? 'Reloading…' : 'Reload' }}
      </button>
    </div>
  </div>
</template>
