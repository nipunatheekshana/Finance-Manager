<?php

namespace App\Enums;

/**
 * The three semantic states used consistently across budgets, affordability
 * checks and alerts. Always paired with a label and icon in the UI so colour is
 * never the only signal.
 */
enum BudgetStatus: string
{
    case Safe = 'safe';
    case Warning = 'warning';
    case Over = 'over';
}
