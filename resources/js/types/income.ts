import type { Money } from './common'

export interface IncomeSource {
  id: number
  name: string
  type: string
  expected_amount: Money
  active: boolean
}

export interface IncomeTransaction {
  id: number
  amount: Money
  received_date: string
  type: 'base' | 'extra'
  description: string | null
  income_source_id: number | null
  monthly_plan_id: number | null
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
