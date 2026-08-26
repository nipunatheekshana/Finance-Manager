import type { BudgetStatus, Money } from './common'

export interface Category {
  id: number
  name: string
  icon: string
  color: string
  monthly_budget: Money | null
  /** Reserved in the plan rather than only warned about. */
  is_allowance: boolean
  weekly_budget: Money | null
  warning_percentage: number
  is_default: boolean
  active: boolean
  sort_order: number
}

export interface PaymentMethod {
  id: number
  name: string
  type: 'cash' | 'bank' | 'debit_card' | 'credit_card' | 'bnpl' | 'other'
  icon: string
  debt_id: number | null
  /** Spending on this method raises the linked debt balance. */
  increases_debt: boolean
  is_default: boolean
  active: boolean
  sort_order: number
}

export interface Expense {
  id: number
  amount: Money
  expense_date: string
  description: string | null
  category_id: number
  payment_method_id: number
  debt_id: number | null
  recurring_transaction_id: number | null
  client_uuid: string | null
  category?: Category
  payment_method?: PaymentMethod
  created_at?: string
}

export interface ExpenseDraft {
  amount: string
  category_id: number | null
  payment_method_id: number | null
  expense_date: string
  description?: string
  debt_id?: number | null
  client_uuid?: string
}

export interface ExpenseFilters {
  search?: string
  category_id?: number | null
  payment_method_id?: number | null
  from?: string | null
  to?: string | null
  min_amount?: string | null
  max_amount?: string | null
  sort?: 'date_desc' | 'date_asc' | 'amount_desc' | 'amount_asc'
}

/** An expense captured while offline, waiting to be sent. */
export interface QueuedExpense extends ExpenseDraft {
  client_uuid: string
  queued_at: string
}

export interface BudgetProjection {
  budget: Money
  spent_before: Money
  spent_after: Money
  remaining_before: Money
  remaining_after: Money
  over_by_after: Money
  percentage_after: number
  status_before: BudgetStatus
  status_after: BudgetStatus
  days_remaining: number | null
  daily_limit_after: Money
  id?: number
  week_number?: number
}

export interface CategoryProjection {
  category_id: number
  name: string
  budget: Money
  spent_before: Money
  spent_after: Money
  remaining_after: Money
  over_by_after: Money
  percentage_after: number
  status_before: BudgetStatus
  status_after: BudgetStatus
}

/** What an expense would do, worked out before it is saved. */
export interface ExpenseImpact {
  amount: Money
  date?: string
  has_plan: boolean
  week: BudgetProjection | null
  month: BudgetProjection | null
  category: CategoryProjection | null
  buffer_remaining: Money
  /** This expense is what tips the week over. */
  will_exceed_week: boolean
  already_over_week: boolean
  will_exceed_category: boolean
  needs_decision: boolean
  headline: string
}

/** The week's state immediately after a save. */
export interface WeekStateAfterSave {
  weekly_budget_id: number
  week_number: number
  status: BudgetStatus
  over_by: Money
  remaining: Money
  is_over: boolean
}
