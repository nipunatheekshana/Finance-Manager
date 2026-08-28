import type { BudgetStatus, Money } from './common'

export type PlanStatus = 'draft' | 'active' | 'completed'

export interface WeeklyBudget {
  id: number
  monthly_plan_id: number
  week_number: number
  start_date: string
  end_date: string
  budget_amount: Money
  adjusted_amount: Money | null
  effective_budget: Money
  spent_amount: Money
}

export interface PlanFixedExpense {
  id: number
  recurring_transaction_id: number | null
  category_id: number | null
  payment_method_id: number | null
  name: string
  amount: Money
  actual_amount: Money | null
  effective_amount: Money
  /** How many times this bill falls inside the cycle. */
  occurrences: number
  due_date: string | null
  postponed_to: string | null
  status: 'planned' | 'paid' | 'skipped' | 'postponed'
  counts_toward_plan: boolean
  paid_at: string | null
}

export interface PlanDebtAllocation {
  id: number
  debt_id: number
  minimum_payment: Money
  recommended_payment: Money
  planned_amount: Money
  paid_amount: Money
  outstanding: Money
  debt?: import('./debt').Debt
}

export interface PlanSavingsAllocation {
  id: number
  savings_goal_id: number
  recommended_amount: Money
  planned_amount: Money
  saved_amount: Money
  outstanding: Money
  savings_goal?: import('./savings').SavingsGoal
}

export interface MonthlyPlan {
  id: number
  year: number
  month: number
  label: string
  status: PlanStatus
  funding_method: import('./user').FundingMethod
  drawn_amount: Money
  expected_income: Money
  actual_income: Money | null
  extra_income: Money
  opening_balance: Money
  carried_forward: Money
  surplus_amount: Money | null
  surplus_resolved_at: string | null
  total_income: Money
  fixed_expenses: Money
  allowances: Money
  debt_payment: Money
  savings: Money
  spending_budget: Money
  buffer: Money
  buffer_used: Money
  buffer_remaining: Money
  cycle_start_date: string
  cycle_end_date: string
  allow_deficit: boolean
  finalized_at: string | null
  completed_at: string | null
  reopened_at: string | null
  weekly_budgets?: WeeklyBudget[]
  fixed_expense_items?: PlanFixedExpense[]
  debt_allocations?: PlanDebtAllocation[]
  savings_allocations?: PlanSavingsAllocation[]
}

export interface AllocationBreakdownRow {
  key: string
  label: string
  amount: Money
  percentage: number
}

export type SurplusActionType = 'debt' | 'savings' | 'carry_forward' | 'leave_in_bank'

export interface SurplusAllocation {
  type: SurplusActionType
  amount: Money
  debt_id?: number
  savings_goal_id?: number
}

export interface CycleSurplus {
  plan_id: number
  plan_label: string
  cycle_end: string
  spending_budget: Money
  spent: Money
  unspent_budget: Money
  allowances: Money
  /** Set aside for a category and never spent. */
  unused_allowances: Money
  buffer: Money
  buffer_used: Money
  unused_buffer: Money
  total: Money
  has_surplus: boolean
  cycle_ended: boolean
  /** A finished, unsettled cycle with money left in it. */
  needs_decision: boolean
  resolved: boolean
  resolved_at: string | null
  resolved_amount: Money | null
  carried_forward: Money
}

export interface CycleSurplusOptions extends CycleSurplus {
  debts: Array<{
    debt_id: number
    name: string
    type: string
    balance: Money
    applicable: Money
    resulting_balance: Money
  }>
  savings_goals: Array<{
    savings_goal_id: number
    name: string
    current_amount: Money
    target_amount: Money
    applicable: Money
    resulting_amount: Money
  }>
  carry_forward: {
    next_label: string
    current_opening_balance: Money
    resulting_opening_balance: Money
  }
}

export interface SurplusResult {
  summary: CycleSurplus
  applied: Array<{ type: SurplusActionType; amount: Money; label: string }>
  allocated: Money
  left_in_bank: Money
}

/** Money set aside for a category and drawn down through the cycle. */
export interface AllowanceSummary {
  category_id: number
  name: string
  icon: string
  color: string
  allocated: Money
  spent: Money
  remaining: Money
  percentage_used: number
  status: BudgetStatus
  over_by: Money
  days_remaining: number
  /** What is left, spread over the days that are left. */
  daily_allowance: Money
  /** What an even spend would have used by now. */
  expected_by_now: Money
  ahead_of_pace: boolean
  pace_difference: Money
}

export interface AllocationSummary {
  total_income: Money
  /** Salary only, before anything carried in from last cycle. */
  salary_income: Money
  /** Leftover handed forward by the previous cycle. */
  opening_balance: Money
  expected_income: Money
  actual_income: Money | null
  extra_income: Money
  fixed_expenses: Money
  allowances: Money
  debt_payment: Money
  savings: Money
  buffer: Money
  spending_budget: Money
  total_allocated: Money
  is_over_allocated: boolean
  over_allocated_by: Money
  allow_deficit: boolean
  can_finalize: boolean
  breakdown: AllocationBreakdownRow[]
}

export interface SuggestedWeek {
  week_number: number
  start_date: string
  end_date: string
  days: number
  budget_amount: Money
}

export interface MonthlySummary {
  label: string
  budget: Money
  spent: Money
  remaining: Money
  percentage_used: number
  status: BudgetStatus
  over_by: Money
  days_remaining: number
  cycle_start: string
  cycle_end: string
  buffer: Money
  buffer_used: Money
  buffer_remaining: Money
}

export interface WeeklySummary {
  id: number
  week_number: number
  start_date: string
  end_date: string
  budget: Money
  original_budget: Money
  was_adjusted: boolean
  spent: Money
  remaining: Money
  percentage_used: number
  status: BudgetStatus
  over_by: Money
  days_total: number
  days_remaining: number
  recommended_daily: Money
  is_current: boolean
  is_past: boolean
}

export interface DailySummary {
  date: string
  spent: Money
  recommended: Money
  remaining: Money
  percentage_used: number
  status: BudgetStatus
  over_by: Money
  /** What each remaining day is worth once today is closed out. */
  next_day_recommended: Money
  days_remaining_in_week: number
}

export interface CategorySummary {
  category_id: number
  name: string
  icon: string
  color: string
  has_budget: boolean
  budget: Money
  spent: Money
  remaining: Money
  percentage_used: number
  warning_percentage: number
  status: BudgetStatus
}

export type AdjustmentType = 'next_week' | 'buffer' | 'category' | 'ignore'

export interface AdjustmentOption {
  type: AdjustmentType
  label: string
  description: string
  available: boolean
  amount: Money
  target_week_number?: number | null
  original_amount?: Money | null
  resulting_amount?: Money | null
  buffer_remaining?: Money
  resulting_buffer?: Money
}

export interface AdjustmentOptions {
  week: WeeklySummary
  over_by: Money
  is_over_budget: boolean
  options: AdjustmentOption[]
}

export interface BudgetAdjustment {
  id: number
  monthly_plan_id: number
  weekly_budget_id: number | null
  source_weekly_budget_id: number | null
  category_id: number | null
  type: AdjustmentType
  amount: Money
  original_amount: Money | null
  adjusted_amount: Money | null
  reason: string | null
  created_at: string | null
}

/** One line of a cycle-progress section: planned against what happened. */
export type ProgressStatus = 'done' | 'partial' | 'pending'

export interface CycleProgressSection {
  planned: Money
  settled: Money
  outstanding: Money
  count: number
  settled_count: number
  percentage: number
  status: ProgressStatus
}

export interface ProgressBill {
  id: number
  name: string
  amount: Money
  planned_amount: Money
  due_date: string | null
  paid_at: string | null
  status: 'planned' | 'paid' | 'skipped' | 'postponed'
  /** Skipped and postponed bills are out of this cycle's total. */
  counts: boolean
}

export interface ProgressDebt {
  id: number
  debt_id: number
  name: string
  planned: Money
  paid: Money
  outstanding: Money
  balance: Money
  due_day: number | null
  percentage: number
  status: ProgressStatus
}

export interface ProgressSaving {
  id: number
  savings_goal_id: number
  name: string
  planned: Money
  saved: Money
  outstanding: Money
  goal_balance: Money
  percentage: number
  status: ProgressStatus
}

export interface CycleProgress {
  plan: {
    id: number
    label: string
    status: string
    cycle_start: string
    cycle_end: string
    days_total: number
    days_elapsed: number
    days_remaining: number
    elapsed_percentage: number
    is_current: boolean
  }
  overall: {
    committed: Money
    settled: Money
    outstanding: Money
    percentage: number
    on_track: boolean
  }
  income: {
    expected: Money
    received: Money
    extra: Money
    opening_balance: Money
    total: Money
    shortfall: Money
    is_recorded: boolean
    percentage: number
    status: ProgressStatus
  }
  bills: CycleProgressSection & { items: ProgressBill[] }
  allowances: {
    items: AllowanceSummary[]
    allocated: Money
    spent: Money
    remaining: Money
    percentage: number
    over_count: number
    ahead_of_pace_count: number
  }
  debts: CycleProgressSection & { items: ProgressDebt[] }
  savings: CycleProgressSection & { items: ProgressSaving[] }
  spending: {
    budget: Money
    spent: Money
    remaining: Money
    percentage: number
    status: BudgetStatus
    over_by: Money
    weeks: WeeklySummary[]
    weeks_over: number
  }
  buffer: {
    total: Money
    used: Money
    remaining: Money
    percentage: number
    is_intact: boolean
  }
}

/** A debt that exists but is not in the current plan. */
export interface PendingDebt {
  debt_id: number
  name: string
  type_label: string
  current_balance: Money
  due_day: number | null
  suggested_amount: Money
  minimum_payment: Money
  created_at: string | null
}

export type CommitmentSource =
  | 'spending'
  | 'buffer'
  /** Plan to save less this cycle: only touches money not yet moved. */
  | 'savings'
  /** Take money already in a goal back out again. */
  | 'savings_withdrawal'
  | 'debts'

export interface CommitmentOption {
  source: CommitmentSource
  label: string
  description: string
  available: boolean
  unavailable_reason: string | null
  current: Money
  resulting_spending_budget?: Money
  resulting_buffer?: Money
  resulting_savings?: Money
  resulting_debt_payment?: Money
  weeks_affected?: number
}

export interface CommitmentOptions {
  plan_label: string
  debt: { debt_id: number; name: string; current_balance: Money }
  amount: Money
  options: CommitmentOption[]
}
