<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Line } from 'vue-chartjs'
import { baseOptions, cssColor } from './chartSetup'

const props = defineProps<{
  labels: string[]
  values: number[]
  label?: string
  tone?: 'brand' | 'over' | 'safe'
  height?: number
  fill?: boolean
}>()

const ready = ref(false)
onMounted(() => {
  ready.value = true
})

const token = computed(
  () =>
    ({ brand: '--color-brand', over: '--color-over', safe: '--color-safe' })[props.tone ?? 'brand'],
)

const data = computed(() => ({
  labels: props.labels,
  datasets: [
    {
      label: props.label ?? '',
      data: props.values,
      borderColor: cssColor(token.value),
      backgroundColor: cssColor(token.value, 0.14),
      borderWidth: 2.5,
      pointRadius: props.values.length > 20 ? 0 : 3,
      pointHoverRadius: 5,
      pointBackgroundColor: cssColor(token.value),
      tension: 0.32,
      fill: props.fill ?? true,
    },
  ],
}))

const options = computed(() => baseOptions())
</script>

<template>
  <div :style="{ height: `${height ?? 240}px` }">
    <Line v-if="ready" :data="data" :options="options as never" />
  </div>
</template>
