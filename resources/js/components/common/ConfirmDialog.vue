<script setup lang="ts">
import BottomSheet from './BottomSheet.vue'

withDefaults(
  defineProps<{
    open: boolean
    title: string
    message: string
    confirmLabel?: string
    cancelLabel?: string
    destructive?: boolean
    busy?: boolean
  }>(),
  { confirmLabel: 'Confirm', cancelLabel: 'Cancel', destructive: false, busy: false },
)

defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <BottomSheet :open="open" :title="title" :busy="busy" @close="$emit('cancel')">
    <p class="pb-4 text-sm text-ink-muted">{{ message }}</p>

    <template #footer>
      <div class="flex gap-3">
        <button type="button" class="btn btn-secondary flex-1" :disabled="busy" @click="$emit('cancel')">
          {{ cancelLabel }}
        </button>
        <button
          type="button"
          class="btn flex-1"
          :class="destructive ? 'btn-danger' : 'btn-primary'"
          :disabled="busy"
          data-autofocus
          @click="$emit('confirm')"
        >
          {{ busy ? 'Working…' : confirmLabel }}
        </button>
      </div>
    </template>
  </BottomSheet>
</template>
