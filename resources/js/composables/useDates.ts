/**
 * Date helpers shared across the app. All dates travel as plain YYYY-MM-DD
 * strings, so nothing here depends on a timezone conversion.
 */

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

/** Parse YYYY-MM-DD as a local date, avoiding the UTC shift `new Date(s)` causes. */
export function parseDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1)
}

export function todayIso(): string {
  return toIso(new Date())
}

export function toIso(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** "12 Aug" or "12 Aug 2026" when the year differs from today's. */
export function formatDate(value: string | null | undefined, withYear?: boolean): string {
  if (!value) return '—'
  const date = parseDate(value)
  const showYear = withYear ?? date.getFullYear() !== new Date().getFullYear()

  return date.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    ...(showYear ? { year: 'numeric' } : {}),
  })
}

export function formatLongDate(value: string | null | undefined): string {
  if (!value) return '—'
  return parseDate(value).toLocaleDateString('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

export function formatMonth(value: string | null | undefined): string {
  if (!value) return '—'
  return parseDate(value).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
}

export function weekdayName(index: number): string {
  return WEEKDAYS[index] ?? ''
}

/** "Today", "Yesterday", "Tomorrow", or a formatted date. */
export function relativeDay(value: string | null | undefined): string {
  if (!value) return '—'

  const target = parseDate(value)
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const diff = Math.round((target.getTime() - today.getTime()) / 86_400_000)

  if (diff === 0) return 'Today'
  if (diff === 1) return 'Tomorrow'
  if (diff === -1) return 'Yesterday'
  if (diff > 1 && diff <= 6) return `In ${diff} days`
  if (diff < -1 && diff >= -6) return `${Math.abs(diff)} days ago`

  return formatDate(value)
}

export function daysBetween(from: string, to: string): number {
  return Math.round((parseDate(to).getTime() - parseDate(from).getTime()) / 86_400_000)
}

/** "25 Jul – 24 Aug" */
export function formatDateRange(start: string, end: string): string {
  const startDate = parseDate(start)
  const endDate = parseDate(end)
  const sameMonth = startDate.getMonth() === endDate.getMonth()
    && startDate.getFullYear() === endDate.getFullYear()

  const startLabel = sameMonth
    ? String(startDate.getDate())
    : startDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })

  const endLabel = endDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })

  return `${startLabel} – ${endLabel}`
}

export function useDates() {
  return {
    parseDate,
    todayIso,
    toIso,
    formatDate,
    formatLongDate,
    formatMonth,
    relativeDay,
    daysBetween,
    formatDateRange,
    weekdayName,
  }
}
