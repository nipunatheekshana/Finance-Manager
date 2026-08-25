<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Bar } from 'vue-chartjs'
import { baseOptions, cssColor } from './chartSetup'

const props = defineProps<{
  labels: string[]
  datasets: Array<{ label: string; values: number[]; token: string }>
  height?: number
  stacked?: boolean
}>()

const ready = ref(false)
onMounted(() => {
  ready.value = true
})

const data = computed(() => ({
  labels: props.labels,
  datasets: props.datasets.map((dataset) => ({
    label: dataset.label,
    data: dataset.values,
    backgroundColor: cssColor(dataset.token, 0.85),
    borderRadius: 5,
    borderSkipped: false,
    maxBarThickness: 34,
  })),
}))

const options = computed(() => {
  const base = baseOptions()
  return {
    ...base,
    plugins: {
      ...base.plugins,
      legend: {
        display: props.datasets.length > 1,
        position: 'bottom' as const,
        labels: {
          color: cssColor('--color-ink-muted'),
          boxWidth: 10,
          boxHeight: 10,
          usePointStyle: true,
          pointStyle: 'circle',
          padding: 16,
          font: { size: 11 },
        },
      },
    },
    scales: {
      x: { ...base.scales.x, stacked: props.stacked ?? false },
      y: { ...base.scales.y, stacked: props.stacked ?? false },
    },
  }
})
</script>

<template>
  <div :style="{ height: `${height ?? 260}px` }">
    <Bar v-if="ready" :data="data" :options="options as never" />
  </div>
</template>
