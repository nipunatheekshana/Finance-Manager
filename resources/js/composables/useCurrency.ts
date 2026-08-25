import type { Money } from '@/types'

/**
 * The application handles Sri Lankan Rupees only. There is no currency
 * selection, conversion or exchange rate anywhere in the product.
 */
const LOCALE = 'en-LK'

const wholeFormatter = new Intl.NumberFormat(LOCALE, {
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

const preciseFormatter = new Intl.NumberFormat(LOCALE, {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

/** Convert a decimal string to a number, only ever for display. */
function toNumber(amount: Money | number | null | undefined): number {
  if (amount === null || amount === undefined || amount === '') return 0
  const value = typeof amount === 'number' ? amount : Number.parseFloat(amount)
  return Number.isFinite(value) ? value : 0
}

/**
 * Format an amount as LKR.
 *
 *   formatLKR('280000.00')            -> 'LKR 280,000'
 *   formatLKR('1250.50', { cents })   -> 'LKR 1,250.50'
 *
 * Cents are hidden by default because most amounts in this app are whole
 * rupees, but they are shown automatically when the value actually has them.
 */
export function formatLKR(
  amount: Money | number | null | undefined,
  options: { cents?: boolean; signed?: boolean; symbol?: boolean } = {},
): string {
  const { signed = false, symbol = true } = options
  const value = toNumber(amount)
  const absolute = Math.abs(value)

  // Show decimals when they carry information, or when explicitly requested.
  const showCents = options.cents ?? Math.round(absolute * 100) % 100 !== 0
  const formatted = showCents ? preciseFormatter.format(absolute) : wholeFormatter.format(absolute)

  const sign = value < 0 ? '-' : signed && value > 0 ? '+' : ''
  const prefix = symbol ? 'LKR ' : ''

  return `${sign}${prefix}${formatted}`
}

/** A compact form for tight spaces: LKR 377k, LKR 1.2m. */
export function formatLKRCompact(amount: Money | number | null | undefined): string {
  const value = toNumber(amount)
  const absolute = Math.abs(value)
  const sign = value < 0 ? '-' : ''

  if (absolute >= 1_000_000) {
    return `${sign}LKR ${(absolute / 1_000_000).toFixed(absolute >= 10_000_000 ? 0 : 1)}m`
  }

  if (absolute >= 1_000) {
    return `${sign}LKR ${(absolute / 1_000).toFixed(absolute >= 100_000 ? 0 : 1)}k`
  }

  return formatLKR(value)
}

/** Just the number, for inputs and chart axes. */
export function formatAmount(amount: Money | number | null | undefined, cents = false): string {
  return formatLKR(amount, { cents, symbol: false })
}

/** Parse whatever the user typed into a canonical decimal string. */
export function parseAmount(input: string | number | null | undefined): Money {
  if (input === null || input === undefined || input === '') return '0.00'

  const cleaned = String(input).replace(/[^0-9.-]/g, '')
  const value = Number.parseFloat(cleaned)

  if (!Number.isFinite(value)) return '0.00'

  return value.toFixed(2)
}

export function formatPercent(value: number | null | undefined, decimals = 0): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return '0%'
  return `${value.toFixed(decimals)}%`
}

export function isNegative(amount: Money | number | null | undefined): boolean {
  return toNumber(amount) < 0
}

export function isPositive(amount: Money | number | null | undefined): boolean {
  return toNumber(amount) > 0
}

export function isZero(amount: Money | number | null | undefined): boolean {
  return Math.abs(toNumber(amount)) < 0.005
}

export function amountToNumber(amount: Money | number | null | undefined): number {
  return toNumber(amount)
}

export function useCurrency() {
  return {
    formatLKR,
    formatLKRCompact,
    formatAmount,
    parseAmount,
    formatPercent,
    isNegative,
    isPositive,
    isZero,
    amountToNumber,
  }
}
