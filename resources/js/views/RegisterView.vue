<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthLayout from './AuthLayout.vue'
import TextField from '@/components/common/TextField.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/services/api'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const errors = ref<Record<string, string>>({})
const generalError = ref('')

async function submit(): Promise<void> {
  errors.value = {}
  generalError.value = ''

  try {
    await auth.register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    // A fresh account always starts in the setup wizard.
    void router.push({ name: 'onboarding' })
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
      )
    } else if (error instanceof ApiError) {
      generalError.value = error.message
    }
  }
}
</script>

<template>
  <AuthLayout title="Create your account" subtitle="Take control of your salary in a few minutes.">
    <form class="space-y-4" @submit.prevent="submit">
      <p v-if="generalError" class="rounded-[var(--radius-field)] bg-over-soft p-3 text-sm text-over" role="alert">
        {{ generalError }}
      </p>

      <TextField v-model="name" label="Name" autocomplete="name" required :error="errors.name" />

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
        label="Password"
        type="password"
        autocomplete="new-password"
        required
        hint="At least 8 characters."
        :error="errors.password"
      />

      <TextField
        v-model="passwordConfirmation"
        label="Confirm password"
        type="password"
        autocomplete="new-password"
        required
      />

      <button type="submit" class="btn btn-primary w-full !text-base" :disabled="auth.loading">
        {{ auth.loading ? 'Creating account…' : 'Create account' }}
      </button>
    </form>

    <template #footer>
      <p class="text-sm text-ink-muted">
        Already have an account?
        <RouterLink to="/login" class="font-semibold text-brand">Sign in</RouterLink>
      </p>
    </template>
  </AuthLayout>
</template>
