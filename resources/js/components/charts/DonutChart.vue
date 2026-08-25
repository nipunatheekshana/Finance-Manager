<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { baseOptions, cssColor, seriesPalette } from './chartSetup'

const props = defineProps<{
  labels: string[]
  values: number[]
  height?: number
}>()

// Re-read the palette after mount so the CSS variables are resolved.
const ready = ref(false)
onMounted(() => {
  ready.value = true
})

const data = computed(() => ({
  labels: props.labels,
  datasets: [
    {
      data: props.values,
      backgroundColor: seriesPalette(props.values.length),
      borderColor: cssColor('--color-raised'),
      borderWidth: 2,
      hoverOffset: 6,
    },
  ],
}))

const options = computed(() => {
  const base = baseOptions()
  return {
    ...base,
    cutout: '62%',
    scales: undefined,
    plugins: { ...base.plugins, legend: { display: false } },
  }
})
</script>

<template>
  <div :style="{ height: `${height ?? 220}px` }">
    <Doughnut v-if="ready" :data="data" :options="options as never" />
  </div>
</template>
