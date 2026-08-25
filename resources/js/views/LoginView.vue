<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthLayout from './AuthLayout.vue'
import TextField from '@/components/common/TextField.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/services/api'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const remember = ref(true)
const errors = ref<Record<string, string>>({})
const generalError = ref('')

async function submit(): Promise<void> {
  errors.value = {}
  generalError.value = ''

  try {
    await auth.login(email.value, password.value, remember.value)
    const redirect = route.query.redirect
    void router.push(typeof redirect === 'string' ? redirect : { name: 'dashboard' })
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
  <AuthLayout title="Welcome back" subtitle="Sign in to pick up where you left off.">
    <form class="space-y-4" @submit.prevent="submit">
      <p v-if="generalError" class="rounded-[var(--radius-field)] bg-over-soft p-3 text-sm text-over" role="alert">
        {{ generalError }}
      </p>

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
        autocomplete="current-password"
        required
        :error="errors.password"
      />

      <div class="flex items-center justify-between">
        <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink-muted">
          <input v-model="remember" type="checkbox" class="h-4 w-4 rounded border-line accent-[rgb(var(--color-brand))]" />
          Stay signed in
        </label>

        <RouterLink to="/forgot-password" class="text-sm font-semibold text-brand">
          Forgot password?
        </RouterLink>
      </div>

      <button type="submit" class="btn btn-primary w-full !text-base" :disabled="auth.loading">
        {{ auth.loading ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>

    <template #footer>
      <p class="text-sm text-ink-muted">
        New here?
        <RouterLink to="/register" class="font-semibold text-brand">Create an account</RouterLink>
      </p>
    </template>
  </AuthLayout>
</template>
