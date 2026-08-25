import type { Money } from './common'

export type IncomeSourceKind = 'salary' | 'client' | 'project' | 'business' | 'other'
export type IncomeCadence = 'monthly' | 'weekly' | 'daily' | 'per_project' | 'irregular'
export type IncomeStatus = 'expected' | 'invoiced' | 'received'

export interface IncomeSource {
  id: number
  name: string
  type: string
  kind: IncomeSourceKind
  kind_label: string
  cadence: IncomeCadence
  client_name: string | null
  expected_amount: Money
  active: boolean
  archived: boolean
}

export interface IncomeTransaction {
  id: number
  amount: Money
  received_date: string | null
  due_date: string | null
  status: IncomeStatus
  is_received: boolean
  is_overdue: boolean
  type: 'base' | 'extra'
  description: string | null
  reference: string | null
  income_source_id: number | null
  monthly_plan_id: number | null
  source?: { id: number | null; name: string | null; kind: IncomeSourceKind | null }
}

/** What funds a cycle's plan, and the reasoning behind the figure. */
export interface Funding {
  method: import('./user').FundingMethod
  method_label: string
  amount: Money
  drawn_amount: Money
  uses_holding_pot: boolean
  is_progressive: boolean
  explanation?: string
  salary?: Money
  draw?: Money
  average?: Money
  factor?: number
  has_history?: boolean
}

/** Income banked but not yet drawn, and how long it would last. */
export interface HoldingPot {
  received: Money
  drawn: Money
  balance: Money
  draw: Money
  months: number | null
  covered_until: string | null
  is_low: boolean
  is_negative: boolean
}

export interface IncomeHistory {
  months: number
  cycles: Array<{ label: string; amount: Money }>
  cycles_with_income: number
  average: Money
  lowest: Money
  highest: Money
}

export interface DrawSuggestion {
  suggested: Money
  average: Money
  lowest: Money
  factor: number
  has_history: boolean
  explanation: string
}

export interface IncomeSummary {
  received: Money
  outstanding: Money
  overdue: Money
}

export interface IncomeModeOption {
  value: import('./user').IncomeMode
  label: string
  description: string
  default_cycle_anchor: import('./user').CycleAnchor
  default_funding_method: import('./user').FundingMethod
  has_salary: boolean
  has_irregular_income: boolean
}

export interface FundingMethodOption {
  value: import('./user').FundingMethod
  label: string
  description: string
  recommended: boolean
  uses_holding_pot: boolean
}

/** What switching mode would change, before it changes it. */
export interface IncomeModePreview {
  mode: import('./user').IncomeMode
  mode_label: string
  from: { mode: string; cycle_anchor: string; funding_method: string }
  to: {
    cycle_anchor: string
    cycle_anchor_label: string
    funding_method: string
    funding_method_label: string
  }
  cycle_anchor_changes: boolean
  takes_effect_on: string
  deferred: boolean
  active_plan_label: string | null
  needs_salary: boolean
  needs_draw: boolean
  suggested_draw: DrawSuggestion | null
  history_preserved: boolean
}

export type Frequency = 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom'

export interface RecurringTransaction {
  id: number
  name: string
  amount: Money
  minimum_amount: Money | null
  maximum_amount: Money | null
  amount_type: 'fixed' | 'estimated' | 'range'
  is_variable: boolean
  category_id: number | null
  payment_method_id: number | null
  frequency: Frequency
  due_day: number | null
  day_of_week: number | null
  interval_days: number | null
  start_date: string
  end_date: string | null
  active: boolean
}

export interface RecurringProjection {
  occurrences: number
  projected_total: Money
}
