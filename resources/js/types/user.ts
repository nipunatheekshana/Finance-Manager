import type { Money, ThemePreference } from './common'

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
  profile?: FinancialProfile
}

export interface OnboardingStatus {
  completed: boolean
  has_salary: boolean
  has_recurring: boolean
  has_debts: boolean
  has_savings_goals: boolean
}
