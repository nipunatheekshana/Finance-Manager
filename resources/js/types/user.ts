import type { BudgetStatus, Money, ThemePreference } from './common'

export type IncomeMode = 'salaried' | 'self_employed' | 'business' | 'hybrid'
export type CycleAnchor = 'pay_day' | 'calendar_month'
export type FundingMethod = 'fixed' | 'draw' | 'forecast' | 'actual' | 'salary_plus_draw'

export interface FinancialProfile {
  id: number
  income_mode: IncomeMode
  income_mode_label: string
  cycle_anchor: CycleAnchor
  funding_method: FundingMethod
  funding_method_label: string
  has_salary: boolean
  has_irregular_income: boolean
  base_salary: Money
  target_draw: Money
  forecast_months: number
  forecast_factor: number
  cycle_start_day: number
  has_extra_income: boolean
  default_buffer: Money
  extra_debt_percentage: number
  extra_savings_percentage: number
  extra_spending_percentage: number
  theme: ThemePreference
  notification_settings: Record<NotificationType, boolean>
  onboarding_completed: boolean
}

export type NotificationType =
  | 'salary_day'
  | 'upcoming_bills'
  | 'debt_payments'
  | 'budget_warnings'
  | 'budget_exceeded'
  | 'savings_goals'
  | 'weekly_review'
  | 'cycle_surplus'
  | 'income_health'

export interface User {
  id: number
  name: string
  email: string
  /** Unique, lowercase, stable enough to live in a URL. */
  handle: string | null
  avatar_url: string | null
  /** Shown when there is no picture. */
  initials: string
  member_since: string | null
  profile?: FinancialProfile
}

export interface OnboardingStatus {
  completed: boolean
  has_salary: boolean
  has_recurring: boolean
  has_debts: boolean
  has_savings_goals: boolean
}

/** The account's own history, gathered from every part of the app. */
export interface ProfileLifetime {
  cycles_planned: number
  cycles_completed: number
  expenses_logged: number
  total_spent: Money
  total_income: Money
  debt_paid: Money
  debts_cleared: number
  saved_net: Money
  currently_saved: Money
  tracking_since: string | null
}

export interface ProfileMonth {
  plan_id: number
  label: string
  status: string
  cycle_start: string
  cycle_end: string
  income: Money
  spending_budget: Money
  spent: Money
  remaining: Money
  percentage_used: number
  status_label: BudgetStatus
  debt_paid: Money
  saved: Money
  total_spent: Money
}

export interface ProfileDebt {
  debt_id: number
  name: string
  type_label: string
  status: string
  original_amount: Money
  current_balance: Money
  paid_total: Money
  payments_count: number
  progress_percentage: number
  cleared_on: string | null
}

export interface ProfileDebtPayment {
  id: number
  debt_name: string
  amount: Money
  payment_date: string
}

export interface ProfileGoal {
  savings_goal_id: number
  name: string
  target_amount: Money
  current_amount: Money
  percentage: number
  status: string
}

export interface ProfileOverview {
  lifetime: ProfileLifetime
  months: ProfileMonth[]
  debts: { items: ProfileDebt[]; recent_payments: ProfileDebtPayment[] }
  savings: { goals: ProfileGoal[] }
}

export interface ActivityEntry {
  id: number
  action: string
  label: string
  subject: string
  note: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  happened_at: string | null
}

export interface ActivityPage {
  items: ActivityEntry[]
  current_page: number
  last_page: number
  total: number
}
