import type { Money } from './common'

export interface CategorySpendRow {
  category_id: number
  name: string
  icon: string
  color: string
  amount: Money
  entries: number
  percentage: number
}

export interface SpendingByCategory {
  start_date: string
  end_date: string
  total: Money
  categories: CategorySpendRow[]
}

export interface TrendPoint {
  label: string
  bucket?: string
  date?: string
  amount: Money
}

export interface SpendingTrend {
  view: 'daily' | 'weekly' | 'monthly'
  start_date: string
  end_date: string
  points: TrendPoint[]
}

export interface DebtTrend {
  current_total: Money
  points: TrendPoint[]
}

export interface SavingsTrend {
  current_total: Money
  points: TrendPoint[]
  goals: Array<{
    id: number
    name: string
    current_amount: Money
    target_amount: Money
    monthly_target: Money
    percentage: number
  }>
}

export interface IncomeVsExpensesPoint {
  label: string
  plan_id: number
  income: Money
  expenses: Money
  debt_payments: Money
  savings: Money
}

export interface PlanSummary {
  plan_id: number
  label: string
  year: number
  month: number
  status: string
  income: Money
  expenses: Money
  discretionary_spend: Money
  fixed_expenses: Money
  spending_budget: Money
  debt_payments: Money
  savings: Money
  buffer: Money
  buffer_used: Money
  budget_adherence: number | null
  credit_card_reduction: Money
}

export interface MonthlyReview {
  plan: PlanSummary
  previous: PlanSummary | null
  top_categories: CategorySpendRow[]
  weeks: import('./budget').WeeklySummary[]
}

export interface WeeklyReview {
  week: import('./budget').WeeklySummary
  top_category: CategorySpendRow | null
  categories: CategorySpendRow[]
  savings: Money
  debt_payments: Money
  is_over_budget: boolean
  over_by: Money
}
