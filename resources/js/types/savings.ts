import type { Money } from './common'

export type AllocationType = 'fixed' | 'salary_percentage' | 'extra_percentage' | 'custom'

export type SavingsTransactionType = 'deposit' | 'withdrawal' | 'transfer_in' | 'transfer_out'

export interface SavingsGoal {
  id: number
  name: string
  icon: string
  target_amount: Money
  current_amount: Money
  remaining_amount: Money
  monthly_target: Money
  allocation_type: AllocationType
  allocation_value: Money
  target_date: string | null
  priority: number
  status: 'active' | 'reached' | 'paused' | 'archived'
  percentage: number
  is_reached: boolean
  transactions?: SavingsTransaction[]
}

export interface SavingsTransaction {
  id: number
  savings_goal_id: number
  type: SavingsTransactionType
  amount: Money
  transaction_date: string
  description: string | null
  related_goal_id: number | null
  increases_balance: boolean
}

export interface SavingsTotals {
  total_saved: Money
  total_target: Money
  total_monthly_target: Money
}
