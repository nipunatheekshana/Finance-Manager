<script setup lang="ts">
import { ref } from 'vue'
import AuthLayout from './AuthLayout.vue'
import TextField from '@/components/common/TextField.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/services/api'

const auth = useAuthStore()

const email = ref('')
const sending = ref(false)
const sent = ref(false)
const message = ref('')
const errors = ref<Record<string, string>>({})

async function submit(): Promise<void> {
  sending.value = true
  errors.value = {}

  try {
    message.value = await auth.forgotPassword(email.value)
    sent.value = true
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <AuthLayout
    title="Reset your password"
    subtitle="We will email you a link to choose a new one."
  >
    <div v-if="sent" class="text-center">
      <p class="rounded-[var(--radius-field)] bg-safe-soft p-3 text-sm text-safe" role="status">
        {{ message }}
      </p>
      <RouterLink to="/login" class="btn btn-secondary mt-4 w-full">Back to sign in</RouterLink>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <TextField
        v-model="email"
        label="Email"
        type="email"
        inputmode="email"
        autocomplete="email"
        required
        :error="errors.email"
      />

      <button type="submit" class="btn btn-primary w-full !text-base" :disabled="sending">
        {{ sending ? 'Sending…' : 'Send reset link' }}
      </button>
    </form>

    <template #footer>
      <RouterLink to="/login" class="text-sm font-semibold text-brand">Back to sign in</RouterLink>
    </template>
  </AuthLayout>
</template>
