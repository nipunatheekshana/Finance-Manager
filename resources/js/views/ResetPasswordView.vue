<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthLayout from './AuthLayout.vue'
import TextField from '@/components/common/TextField.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const errors = ref<Record<string, string>>({})

async function submit(): Promise<void> {
  submitting.value = true
  errors.value = {}

  try {
    await auth.resetPassword({
      token: String(route.params.token),
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    ui.success('Password updated', 'You can sign in with your new password.')
    void router.push({ name: 'login' })
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthLayout title="Choose a new password">
    <form class="space-y-4" @submit.prevent="submit">
      <TextField
        v-model="email"
        label="Email"
        type="email"
        inputmode="email"
        autocomplete="email"
        required
        :error="errors.email"
      />

      <TextField
        v-model="password"
        label="New password"
        type="password"
        autocomplete="new-password"
        required
        hint="At least 8 characters."
        :error="errors.password"
      />

      <TextField
        v-model="passwordConfirmation"
        label="Confirm new password"
        type="password"
        autocomplete="new-password"
        required
      />

      <button type="submit" class="btn btn-primary w-full !text-base" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Reset password' }}
      </button>
    </form>
  </AuthLayout>
</template>
