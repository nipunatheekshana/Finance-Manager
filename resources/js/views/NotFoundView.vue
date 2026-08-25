<script setup lang="ts">
import { Compass } from 'lucide-vue-next'
</script>

<template>
  <div class="flex min-h-dvh flex-col items-center justify-center px-6 text-center">
    <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-sunken">
      <Compass class="h-7 w-7 text-ink-subtle" aria-hidden="true" />
    </span>
    <h1 class="text-2xl font-bold text-ink">Page not found</h1>
    <p class="mt-1.5 max-w-sm text-sm text-ink-muted">
      That page does not exist, or it has moved.
    </p>
    <RouterLink to="/" class="btn btn-primary mt-6">Back to the dashboard</RouterLink>
  </div>
</template>
