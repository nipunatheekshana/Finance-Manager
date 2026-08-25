<script setup lang="ts">
import { Wallet } from 'lucide-vue-next'

defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="flex min-h-dvh flex-col justify-center px-4 py-10 sm:px-6">
    <div class="mx-auto w-full max-w-sm">
      <div class="mb-8 text-center">
        <span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand text-on-brand">
          <Wallet class="h-6 w-6" aria-hidden="true" />
        </span>
        <h1 class="text-2xl font-bold tracking-tight text-ink">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1.5 text-sm text-ink-muted">{{ subtitle }}</p>
      </div>

      <div class="card p-6">
        <slot />
      </div>

      <div class="mt-5 text-center">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>
