<script setup lang="ts">
import { computed, ref } from 'vue'
import { Camera, Trash2 } from 'lucide-vue-next'
import { api, ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { User } from '@/types'

const props = defineProps<{ user: User }>()
const emit = defineEmits<{ updated: [User] }>()

const auth = useAuthStore()
const ui = useUiStore()

const input = ref<HTMLInputElement | null>(null)
const busy = ref(false)

const hasPicture = computed(() => props.user.avatar_url !== null)

async function choose(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  const body = new FormData()
  body.append('avatar', file)

  busy.value = true
  try {
    const response = await api.post<{ user: User }>('/me/avatar', body, { timeout: 60_000 })
    emit('updated', response.user)
    auth.user = response.user
    ui.success('Picture updated')
  } catch (error) {
    if (error instanceof ApiError) {
      ui.error(
        'Could not use that picture',
        error.isValidation
          ? (Object.values(error.errors)[0]?.[0] ?? error.message)
          : error.message,
      )
    }
  } finally {
    busy.value = false
    // Let the same file be picked again after a failure.
    if (input.value) input.value.value = ''
  }
}

async function remove(): Promise<void> {
  busy.value = true
  try {
    const response = await api.delete<{ user: User }>('/me/avatar')
    emit('updated', response.user)
    auth.user = response.user
  } catch (error) {
    if (error instanceof ApiError) ui.error('Could not remove it', error.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex items-center gap-4">
    <div class="relative">
      <img
        v-if="hasPicture"
        :src="user.avatar_url!"
        :alt="`${user.name}'s picture`"
        class="h-20 w-20 rounded-full object-cover"
      />
      <span
        v-else
        class="flex h-20 w-20 items-center justify-center rounded-full bg-brand-soft text-xl font-bold text-brand"
        aria-hidden="true"
      >
        {{ user.initials }}
      </span>

      <button
        type="button"
        class="absolute -bottom-1 -right-1 flex h-9 w-9 items-center justify-center rounded-full bg-brand text-on-brand shadow-[var(--shadow-card)] transition active:scale-95"
        :disabled="busy"
        :aria-label="hasPicture ? 'Change your picture' : 'Add a picture'"
        @click="input?.click()"
      >
        <Camera class="h-4 w-4" aria-hidden="true" />
      </button>

      <input
        ref="input"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        class="sr-only"
        @change="choose"
      />
    </div>

    <div class="min-w-0">
      <p class="truncate text-lg font-bold text-ink">{{ user.name }}</p>
      <p v-if="user.handle" class="truncate text-sm text-ink-muted">@{{ user.handle }}</p>

      <button
        v-if="hasPicture"
        type="button"
        class="mt-1.5 flex min-h-9 items-center gap-1.5 text-sm font-medium text-ink-subtle transition hover:text-over"
        :disabled="busy"
        @click="remove"
      >
        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
        Remove picture
      </button>
    </div>
  </div>
</template>
