<?php

namespace App\Enums;

enum AlertType: string
{
    case SalaryReceived = 'salary_received';
    case SalaryTomorrow = 'salary_tomorrow';
    case BillDueSoon = 'bill_due_soon';
    case DebtPaymentDue = 'debt_payment_due';
    case BudgetWarning = 'budget_warning';
    case BudgetExceeded = 'budget_exceeded';
    case CategoryBudgetWarning = 'category_budget_warning';
    case CategoryBudgetExceeded = 'category_budget_exceeded';
    case SavingsTargetReached = 'savings_target_reached';
    case CreditCardIncreased = 'credit_card_increased';
    case WeeklyReview = 'weekly_review';
    case CycleSurplus = 'cycle_surplus';
    case LowRunway = 'low_runway';
    case InvoiceOverdue = 'invoice_overdue';
    case IncomeBehindPlan = 'income_behind_plan';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
