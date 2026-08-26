<script setup lang="ts">
import { computed } from 'vue'
import { Download, MoreVertical, Plus, Share, SquarePlus } from 'lucide-vue-next'
import BottomSheet from '@/components/common/BottomSheet.vue'
import { useInstallPrompt } from '@/composables/useInstallPrompt'
import { useUiStore } from '@/stores/ui'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const ui = useUiStore()
const { canPromptNatively, isIos, promptInstall } = useInstallPrompt()

const description = computed(() =>
  canPromptNatively.value
    ? 'It opens full screen, keeps working offline and sits on your home screen like any other app.'
    : 'Your browser does not offer a one-tap install here, so add it by hand — it takes a moment.',
)

async function install(): Promise<void> {
  const outcome = await promptInstall()

  if (outcome === 'accepted') {
    ui.success('Installing', 'Finance Manager will appear with your other apps.')
    emit('close')
  } else if (outcome === 'dismissed') {
    emit('close')
  }
}
</script>

<template>
  <BottomSheet
    :open="props.open"
    title="Install Finance Manager"
    :description="description"
    @close="emit('close')"
  >
    <div class="space-y-4 pb-2">
      <!-- Chrome and Edge, on Android and on a computer. -->
      <button
        v-if="canPromptNatively"
        type="button"
        class="btn btn-primary w-full !text-base"
        data-autofocus
        @click="install"
      >
        <Download class="h-5 w-5" aria-hidden="true" />
        Install
      </button>

      <!-- iPhone and iPad: Safari has no install API, only the Share menu. -->
      <ol v-else-if="isIos" class="space-y-3">
        <li class="flex items-start gap-3">
          <span class="tabular flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-bold text-brand">1</span>
          <p class="pt-0.5 text-sm text-ink">
            Open this page in <span class="font-semibold">Safari</span> — Chrome on an iPhone
            cannot add to the home screen.
          </p>
        </li>
        <li class="flex items-start gap-3">
          <span class="tabular flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-bold text-brand">2</span>
          <p class="flex flex-wrap items-center gap-1.5 pt-0.5 text-sm text-ink">
            Tap
            <Share class="h-4 w-4 text-brand" aria-hidden="true" />
            <span class="font-semibold">Share</span>, at the bottom of the screen.
          </p>
        </li>
        <li class="flex items-start gap-3">
          <span class="tabular flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-bold text-brand">3</span>
          <p class="flex flex-wrap items-center gap-1.5 pt-0.5 text-sm text-ink">
            Choose
            <SquarePlus class="h-4 w-4 text-brand" aria-hidden="true" />
            <span class="font-semibold">Add to Home Screen</span>, then
            <span class="font-semibold">Add</span>.
          </p>
        </li>
      </ol>

      <!-- Anything else: Firefox, Safari on a Mac, a browser that has already
           been asked and declined. -->
      <ol v-else class="space-y-3">
        <li class="flex items-start gap-3">
          <span class="tabular flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-bold text-brand">1</span>
          <p class="flex flex-wrap items-center gap-1.5 pt-0.5 text-sm text-ink">
            Open your browser menu —
            <MoreVertical class="h-4 w-4 text-brand" aria-hidden="true" />
            or
            <Share class="h-4 w-4 text-brand" aria-hidden="true" />
            in the toolbar.
          </p>
        </li>
        <li class="flex items-start gap-3">
          <span class="tabular flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-bold text-brand">2</span>
          <p class="flex flex-wrap items-center gap-1.5 pt-0.5 text-sm text-ink">
            Choose
            <Plus class="h-4 w-4 text-brand" aria-hidden="true" />
            <span class="font-semibold">Install</span> or
            <span class="font-semibold">Add to Home Screen</span>.
          </p>
        </li>
      </ol>

      <p class="text-xs text-ink-subtle">
        Installing changes nothing about your data — it is the same account, on the same server.
      </p>
    </div>
  </BottomSheet>
</template>
