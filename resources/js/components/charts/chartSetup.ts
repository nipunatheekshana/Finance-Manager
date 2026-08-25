import {
  ArcElement, BarElement, CategoryScale, Chart, Filler, Legend,
  LineElement, LinearScale, PointElement, Tooltip,
} from 'chart.js'
import { formatLKR, formatLKRCompact } from '@/composables/useCurrency'

Chart.register(
  ArcElement, BarElement, CategoryScale, Filler, Legend,
  LineElement, LinearScale, PointElement, Tooltip,
)

/**
 * Charts read their colours from the same CSS custom properties as the rest of
 * the app, so they follow light and dark mode without a second palette.
 */
export function cssColor(token: string, alpha = 1): string {
  const value = getComputedStyle(document.documentElement).getPropertyValue(token).trim()
  return alpha === 1 ? `rgb(${value})` : `rgb(${value} / ${alpha})`
}

/** A categorical sequence that stays distinguishable in both themes. */
export function seriesPalette(count: number): string[] {
  const base = [
    '--color-brand',
    '--color-info',
    '--color-warn',
    '--color-over',
    '--color-safe',
  ]

  const colors: string[] = []
  for (let index = 0; index < count; index++) {
    const token = base[index % base.length]!
    // Step the opacity down on each wrap so repeats stay tellable apart.
    const cycle = Math.floor(index / base.length)
    colors.push(cssColor(token, cycle === 0 ? 1 : Math.max(0.35, 1 - cycle * 0.25)))
  }
  return colors
}

export function baseOptions() {
  const ink = cssColor('--color-ink-muted')
  const line = cssColor('--color-line')
  const raised = cssColor('--color-raised')
  const inkStrong = cssColor('--color-ink')

  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: raised,
        titleColor: inkStrong,
        bodyColor: ink,
        borderColor: line,
        borderWidth: 1,
        padding: 10,
        cornerRadius: 8,
        displayColors: true,
        callbacks: {
          label: (context: { dataset: { label?: string }; parsed: { y?: number } | number }) => {
            const value =
              typeof context.parsed === 'number' ? context.parsed : (context.parsed.y ?? 0)
            const label = context.dataset.label
            return `${label ? `${label}: ` : ''}${formatLKR(value)}`
          },
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        border: { color: line },
        ticks: { color: ink, font: { size: 11 }, maxRotation: 0, autoSkipPadding: 12 },
      },
      y: {
        beginAtZero: true,
        grid: { color: line },
        border: { display: false },
        ticks: {
          color: ink,
          font: { size: 11 },
          callback: (value: string | number) => formatLKRCompact(Number(value)),
        },
      },
    },
  }
}
