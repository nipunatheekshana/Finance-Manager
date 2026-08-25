<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import TextField from '@/components/common/TextField.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/services/api'

const auth = useAuthStore()
const ui = useUiStore()

const name = ref('')
const email = ref('')
const savingAccount = ref(false)
const accountErrors = ref<Record<string, string>>({})

const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const savingPassword = ref(false)
const passwordErrors = ref<Record<string, string>>({})

function toFieldErrors(error: unknown): Record<string, string> {
  if (error instanceof ApiError && error.isValidation) {
    return Object.fromEntries(
      Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? '']),
    )
  }
  return {}
}

async function saveAccount(): Promise<void> {
  savingAccount.value = true
  accountErrors.value = {}

  try {
    await auth.updateAccount({ name: name.value, email: email.value })
    ui.success('Account updated')
  } catch (error) {
    accountErrors.value = toFieldErrors(error)
    if (Object.keys(accountErrors.value).length === 0 && error instanceof ApiError) {
      ui.error('Could not update your account', error.message)
    }
  } finally {
    savingAccount.value = false
  }
}

async function savePassword(): Promise<void> {
  savingPassword.value = true
  passwordErrors.value = {}

  try {
    await auth.updatePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    ui.success('Password updated')
    currentPassword.value = ''
    password.value = ''
    passwordConfirmation.value = ''
  } catch (error) {
    passwordErrors.value = toFieldErrors(error)
    if (Object.keys(passwordErrors.value).length === 0 && error instanceof ApiError) {
      ui.error('Could not update your password', error.message)
    }
  } finally {
    savingPassword.value = false
  }
}

onMounted(() => {
  name.value = auth.user?.name ?? ''
  email.value = auth.user?.email ?? ''
})
</script>

<template>
  <div>
    <PageHeader title="Security" back-to="/settings" />

    <div class="space-y-6">
      <section class="card space-y-4 p-4">
        <h2 class="text-base font-semibold text-ink">Account details</h2>

        <TextField v-model="name" label="Name" autocomplete="name" :error="accountErrors.name" />
        <TextField
          v-model="email"
          label="Email"
          type="email"
          inputmode="email"
          autocomplete="email"
          :error="accountErrors.email"
        />

        <button type="button" class="btn btn-primary w-full" :disabled="savingAccount" @click="saveAccount">
          {{ savingAccount ? 'Saving…' : 'Save details' }}
        </button>
      </section>

      <section class="card space-y-4 p-4">
        <h2 class="text-base font-semibold text-ink">Change password</h2>

        <TextField
          v-model="currentPassword"
          label="Current password"
          type="password"
          autocomplete="current-password"
          :error="passwordErrors.current_password"
        />
        <TextField
          v-model="password"
          label="New password"
          type="password"
          autocomplete="new-password"
          hint="At least 8 characters."
          :error="passwordErrors.password"
        />
        <TextField
          v-model="passwordConfirmation"
          label="Confirm new password"
          type="password"
          autocomplete="new-password"
        />

        <button
          type="button"
          class="btn btn-primary w-full"
          :disabled="savingPassword || currentPassword === '' || password === ''"
          @click="savePassword"
        >
          {{ savingPassword ? 'Saving…' : 'Change password' }}
        </button>
      </section>

      <p class="pb-4 text-center text-xs text-ink-subtle">
        Your financial data is only ever visible to you.
      </p>
    </div>
  </div>
</template>
