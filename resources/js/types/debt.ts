import type { Money } from './common'

export type DebtType = 'credit_card' | 'installment' | 'loan' | 'personal' | 'other'

export interface Debt {
  id: number
  name: string
  type: DebtType
  type_label: string
  original_amount: Money
  current_balance: Money
  credit_limit: Money | null
  interest_rate: string | null
  minimum_payment: Money
  planned_payment: Money
  due_day: number | null
  remaining_installments: number | null
  installment_amount: Money | null
  /** Schedule total, not a settlement quote. */
  scheduled_remaining: Money | null
  early_settlement_amount: Money | null
  progress_percentage: number
  utilisation_percentage: number | null
  status: 'active' | 'paid_off' | 'closed'
  /**
   * What the active plan asked for this cycle. Not the same as
   * planned_payment: the planner can change it for a single month, and part
   * of it may already be paid. Only sent by the single-debt endpoint.
   */
  cycle?: { planned: Money; paid: Money; outstanding: Money } | null
  payments?: DebtPayment[]
}

export interface DebtPayment {
  id: number
  debt_id: number
  amount: Money
  payment_date: string
  interest_amount: Money | null
  principal_amount: Money | null
  balance_after: Money | null
  notes: string | null
}

export interface PayoffScheduleRow {
  month_number: number
  date: string
  label: string
  payment: Money
  interest: Money
  principal: Money
  remaining_balance: Money
}

export interface PayoffProjection {
  /** Always true — every payoff figure in this app is an estimate. */
  is_estimate: boolean
  has_interest: boolean
  interest_rate: string | null
  monthly_payment: Money
  will_be_paid_off: boolean
  estimated_months: number | null
  estimated_payoff_date: string | null
  estimated_payoff_label: string | null
  estimated_total_interest: Money
  estimated_total_paid: Money
  remaining_after_projection: Money
  schedule: PayoffScheduleRow[]
  note: string
  warning: string | null
}

export interface DebtTotals {
  total_balance: Money
  total_planned_payment: Money
  total_minimum_payment: Money
  payoff: {
    total_balance: Money
    total_monthly_payment: Money
    debt_free_in_months: number | null
    projections: Array<PayoffProjection & { debt_id: number; name: string }>
  }
}
