/**
 * Money is carried as a decimal string end to end ("280000.00").
 *
 * Amounts are never held as JavaScript numbers in application state: they come
 * from DECIMAL(15,2) columns and are only converted to a number at the moment
 * they are formatted or charted.
 */
export type Money = string

export type BudgetStatus = 'safe' | 'warning' | 'over'

export type AlertSeverity = 'info' | 'success' | 'warning' | 'critical'

export type ThemePreference = 'light' | 'dark' | 'system'

export interface Paginated<T> {
  data: T[]
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    from: number | null
    last_page: number
    per_page: number
    to: number | null
    total: number
    [key: string]: unknown
  }
}

export interface ApiCollection<T, M = Record<string, unknown>> {
  data: T[]
  meta?: M
}

export interface ApiItem<T> {
  data: T
}

/** Field-level errors as returned by Laravel's 422 responses. */
export type ValidationErrors = Record<string, string[]>

export interface ApiErrorShape {
  message: string
  errors?: ValidationErrors
  status: number
}
