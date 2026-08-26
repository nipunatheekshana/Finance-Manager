import type { AlertSeverity, BudgetStatus, Money } from './common'
import type { CategorySummary, DailySummary, MonthlySummary, WeeklySummary } from './budget'
import type { Category, PaymentMethod } from './expense'
import type { PayoffProjection } from './debt'

export type AlertType =
  | 'salary_received'
  | 'salary_tomorrow'
  | 'bill_due_soon'
  | 'debt_payment_due'
  | 'budget_warning'
  | 'budget_exceeded'
  | 'category_budget_warning'
  | 'category_budget_exceeded'
  | 'savings_target_reached'
  | 'credit_card_increased'
  | 'weekly_review'
  | 'cycle_surplus'
  | 'allowance_running_out'
  | 'low_runway'
  | 'invoice_overdue'
  | 'income_behind_plan'

export interface FinancialAlert {
  id: number
  type: AlertType
  severity: AlertSeverity
  title: string
  message: string
  action_label: string | null
  action_route: string | null
  data: Record<string, unknown> | null
  triggered_on: string
  is_read: boolean
}

export interface SalarySection {
  income_mode: import('./user').IncomeMode
  funding_method: import('./user').FundingMethod
  funding_label: string
  funding_explanation: string | null
  /** Irregular accounts have no pay day; they have a pot and runway instead. */
  has_pay_day: boolean
  holding_pot: import('./income').HoldingPot | null
  received_this_cycle: import('./income').IncomeSummary | string
  expected: Money
  actual: Money | null
  extra: Money
  cycle_start_day: number
  next_salary_date: string | null
  days_until_salary: number | null
  is_salary_day: boolean
  needs_planning: boolean
  plan_period: { year: number; month: number }
}

export interface DashboardDebtItem {
  id: number
  name: string
  type: string
  type_label: string
  balance: Money
  planned_payment: Money
  progress_percentage: number
  remaining_installments: number | null
}

export interface DashboardCreditCard {
  id: number
  name: string
  balance: Money
  planned_payment: Money
  progress_percentage: number
  utilisation_percentage: number | null
}

export interface DashboardDebts {
  total_balance: Money
  total_planned_payment: Money
  count: number
  /** Every credit card the account holds, largest balance first. */
  credit_cards: {
    count: number
    total_balance: Money
    total_planned_payment: Money
    total_limit: Money
    items: DashboardCreditCard[]
  }
  credit_card: {
    id: number
    name: string
    balance: Money
    planned_payment: Money
    progress_percentage: number
    utilisation_percentage: number | null
    payoff: PayoffProjection
  } | null
  items: DashboardDebtItem[]
}

export interface DashboardSavingsGoal {
  id: number
  name: string
  icon: string
  current_amount: Money
  target_amount: Money
  monthly_target: Money
  percentage: number
  status: string
}

export interface DashboardSavings {
  total: Money
  this_month: Money
  target_total: Money
  count: number
  goals: DashboardSavingsGoal[]
}

export interface DashboardExpense {
  id: number
  amount: Money
  description: string | null
  expense_date: string
  category: Pick<Category, 'id' | 'name' | 'icon' | 'color'>
  payment_method: Pick<PaymentMethod, 'id' | 'name' | 'icon'>
}

export interface UpcomingBill {
  id: number
  kind: string
  name: string
  amount: Money
  date: string | null
  is_overdue: boolean
  category_id: number | null
}

/** A debt instalment planned for this cycle and not yet fully paid. */
export interface UpcomingDebtPayment {
  id: number
  kind: string
  debt_id: number
  name: string
  /** Still outstanding, not the full planned amount. */
  amount: Money
  planned: Money
  paid: Money
  date: string | null
}

export interface Dashboard {
  today: string
  has_plan: boolean
  onboarding_completed: boolean
  plan_id?: number
  plan_status?: string
  period_label: string
  cycle_start?: string
  cycle_end?: string
  salary: SalarySection
  available_to_spend: Money
  today_budget: DailySummary | null
  week_budget: WeeklySummary | null
  month_budget: MonthlySummary | null
  weeks?: WeeklySummary[]
  categories?: CategorySummary[]
  allowances?: import('./budget').AllowanceSummary[]
  debts: DashboardDebts
  savings: DashboardSavings
  recent_expenses: DashboardExpense[]
  upcoming_bills: { items: UpcomingBill[]; total: Money }
  upcoming_debt_payments: { items: UpcomingDebtPayment[]; total: Money }
  alerts: FinancialAlert[]
  empty_message?: string
}

export interface AffordabilityResult {
  amount: Money
  verdict: BudgetStatus
  headline: string
  message: string
  reasons: string[]
  factors: {
    month_remaining: Money
    month_remaining_after: Money
    week_remaining: Money
    week_remaining_after: Money
    today_remaining: Money
    upcoming_bills: Money
    upcoming_debt_payments: Money
    planned_savings: Money
    buffer_remaining: Money
    days_remaining: number
    new_daily_limit: Money
  }
  disclaimer: string
}

export interface HealthFactor {
  key: string
  label: string
  weight: number
  rating: number
  points: number
  detail: string
  data: Record<string, unknown>
}

export interface FinancialHealth {
  score: number | null
  has_data: boolean
  plan_label?: string
  change_from_last_month: number | null
  previous_score?: number | null
  factors: HealthFactor[]
  good: string[]
  needs_attention: string[]
  message?: string
  disclaimer: string
}

export interface CashFlowEvent {
  date: string | null
  kind: 'income' | 'bill' | 'debt' | 'savings'
  name: string
  amount: Money
  direction: 'in' | 'out'
}

export interface CashFlowForecast {
  plan_label: string
  cycle_start: string
  cycle_end: string
  total_income: Money
  available_to_spend: Money
  spending_budget: Money
  spent_so_far: Money
  upcoming_bills: { items: UpcomingBill[]; total: Money }
  upcoming_debt_payments: { items: UpcomingDebtPayment[]; total: Money }
  planned_savings: { items: Array<Record<string, unknown>>; total: Money }
  total_committed: Money
  buffer_remaining: Money
  projected_spending_balance: Money
  average_daily_spend: Money
  projected_further_spend: Money
  projected_month_end_balance: Money
  projection_is_estimate: boolean
  days_remaining: number
  timeline: CashFlowEvent[]
}

export interface CalendarEvent {
  date: string
  kind: 'salary' | 'bill' | 'debt' | 'expense' | 'savings'
  icon: string
  name: string
  amount: Money
  direction: 'in' | 'out'
}
