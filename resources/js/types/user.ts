import type { Money, ThemePreference } from './common'

export interface FinancialProfile {
  id: number
  base_salary: Money
  salary_day: number
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
