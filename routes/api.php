<?php

use App\Http\Controllers\Api\AffordabilityController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashFlowController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CycleProgressController;
use App\Http\Controllers\Api\DebtController;
use App\Http\Controllers\Api\PlanCommitmentController;
use App\Http\Controllers\Api\DebtPaymentController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FinancialHealthController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\IncomeModeController;
use App\Http\Controllers\Api\MonthlyPlanController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\RecurringTransactionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\SavingsTransactionController;
use App\Http\Controllers\Api\WeeklyBudgetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| Credential endpoints are rate limited by both IP and email address.
*/

Route::middleware('throttle:auth')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
| Every route below resolves records through the signed-in user or a policy,
| so an id from the client can never reach another account's data.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/user', [AuthController::class, 'user']);

    // Onboarding
    Route::get('onboarding', [OnboardingController::class, 'status']);
    Route::post('onboarding', [OnboardingController::class, 'store']);
    Route::post('onboarding/skip', [OnboardingController::class, 'skip']);

    // Dashboard — one request powers the whole home screen.
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('cycle-surplus', [DashboardController::class, 'pendingSurplus']);

    // Profile & settings
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::put('profile/account', [ProfileController::class, 'updateAccount']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);

    // How the user earns, and switching between modes.
    Route::get('income-modes', [IncomeModeController::class, 'index']);
    Route::post('income-modes/preview', [IncomeModeController::class, 'preview']);
    Route::put('income-modes', [IncomeModeController::class, 'update']);

    // Income ledger
    Route::get('income/forecast', [IncomeController::class, 'forecast']);
    Route::get('income/sources', [IncomeController::class, 'sources']);
    Route::post('income/sources', [IncomeController::class, 'storeSource']);
    Route::put('income/sources/{incomeSource}', [IncomeController::class, 'updateSource']);
    Route::delete('income/sources/{incomeSource}', [IncomeController::class, 'destroySource']);

    Route::get('income', [IncomeController::class, 'index']);
    Route::post('income', [IncomeController::class, 'store']);
    Route::put('income/{income}', [IncomeController::class, 'update']);
    Route::post('income/{income}/received', [IncomeController::class, 'markReceived']);
    Route::delete('income/{income}', [IncomeController::class, 'destroy']);

    // Expenses
    Route::post('expenses/preview', [ExpenseController::class, 'preview']);
    Route::post('expenses/sync', [ExpenseController::class, 'sync']);
    Route::apiResource('expenses', ExpenseController::class);

    // Reference data
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('payment-methods', PaymentMethodController::class)
        ->parameters(['payment-methods' => 'paymentMethod']);
    Route::apiResource('recurring-transactions', RecurringTransactionController::class)
        ->parameters(['recurring-transactions' => 'recurringTransaction']);

    /*
    | Monthly plans — the salary-day flow.
    */
    Route::get('monthly-plans/current', [MonthlyPlanController::class, 'current']);
    Route::get('monthly-plans', [MonthlyPlanController::class, 'index']);
    Route::post('monthly-plans', [MonthlyPlanController::class, 'store']);

    Route::prefix('monthly-plans/{monthlyPlan}')->group(function () {
        Route::get('/', [MonthlyPlanController::class, 'show']);
        Route::put('/', [MonthlyPlanController::class, 'update']);
        Route::get('summary', [MonthlyPlanController::class, 'summary']);

        // Step 1 — income
        Route::post('income', [MonthlyPlanController::class, 'recordIncome']);

        // Step 2 — fixed expenses
        Route::post('fixed-expenses', [MonthlyPlanController::class, 'addFixedExpense']);
        Route::put('fixed-expenses/{fixedExpense}', [MonthlyPlanController::class, 'updateFixedExpense']);

        // Money set aside for gradual spending (fuel, groceries, eating out)
        Route::get('allowances', [MonthlyPlanController::class, 'allowances']);
        Route::put('allowances', [MonthlyPlanController::class, 'updateAllowances']);

        // Steps 3 & 4 — debt and savings allocations
        Route::put('allocations', [MonthlyPlanController::class, 'updateAllocations']);

        // A debt taken on after the plan was finalised. updateAllocations can
        // only change what is already there; this adds it and rebalances.
        Route::get('pending-debts', [PlanCommitmentController::class, 'index']);
        Route::get('pending-debts/{debt}/options', [PlanCommitmentController::class, 'options']);
        Route::post('pending-debts/{debt}', [PlanCommitmentController::class, 'store']);

        // Step 6 — weekly budgets
        Route::get('weeks', [MonthlyPlanController::class, 'weeks']);
        Route::put('weeks', [MonthlyPlanController::class, 'updateWeeks']);

        // Month-end: what the cycle left over, and where it should go.
        Route::get('surplus', [MonthlyPlanController::class, 'surplus']);
        Route::post('surplus', [MonthlyPlanController::class, 'resolveSurplus']);

        Route::post('finalize', [MonthlyPlanController::class, 'finalize']);
        Route::post('complete', [MonthlyPlanController::class, 'complete']);
        Route::post('reopen', [MonthlyPlanController::class, 'reopen']);
    });

    /*
    | Weekly budgets and the overspend flow.
    */
    Route::get('weekly-budgets/{weeklyBudget}', [WeeklyBudgetController::class, 'show']);
    Route::get('weekly-budgets/{weeklyBudget}/review', [WeeklyBudgetController::class, 'review']);
    Route::get('weekly-budgets/{weeklyBudget}/adjustment-options', [WeeklyBudgetController::class, 'adjustmentOptions']);
    Route::post('weekly-budgets/{weeklyBudget}/adjustments', [WeeklyBudgetController::class, 'applyAdjustment']);

    /*
    | Debts
    */
    Route::get('debts/{debt}/payoff', [DebtController::class, 'payoff']);
    Route::get('debts/{debt}/payments', [DebtPaymentController::class, 'index']);
    Route::post('debts/{debt}/payments', [DebtPaymentController::class, 'store']);
    Route::delete('debt-payments/{payment}', [DebtPaymentController::class, 'destroy']);
    Route::apiResource('debts', DebtController::class);

    /*
    | Savings
    */
    Route::get('savings-goals/{savingsGoal}/transactions', [SavingsTransactionController::class, 'index']);
    Route::post('savings-goals/{savingsGoal}/transactions', [SavingsTransactionController::class, 'store']);
    Route::delete('savings-transactions/{savingsTransaction}', [SavingsTransactionController::class, 'destroy']);
    Route::apiResource('savings-goals', SavingsGoalController::class)
        ->parameters(['savings-goals' => 'savingsGoal']);

    /*
    | Insight
    */
    // The account's own page: identity and the whole history behind it.
    // "profile" is already the financial profile, so this lives under /me.
    Route::get('me', [UserProfileController::class, 'show']);
    Route::get('me/activity', [UserProfileController::class, 'activity']);
    Route::put('me', [UserProfileController::class, 'update']);
    Route::post('me/avatar', [UserProfileController::class, 'uploadAvatar']);
    Route::delete('me/avatar', [UserProfileController::class, 'deleteAvatar']);

    Route::get('cash-flow', [CashFlowController::class, 'show']);

    // One board for the whole cycle: what was planned against what has
    // actually happened, entity by entity.
    Route::get('cycle-progress', [CycleProgressController::class, 'show']);
    Route::get('calendar', [CashFlowController::class, 'calendar']);
    Route::get('financial-health', [FinancialHealthController::class, 'show']);
    Route::post('affordability-check', [AffordabilityController::class, 'check']);

    Route::prefix('reports')->group(function () {
        Route::get('monthly', [ReportController::class, 'monthly']);
        Route::get('spending', [ReportController::class, 'spending']);
        Route::get('trend', [ReportController::class, 'trend']);
        Route::get('debt', [ReportController::class, 'debt']);
        Route::get('savings', [ReportController::class, 'savings']);
        Route::get('income-vs-expenses', [ReportController::class, 'incomeVsExpenses']);
        Route::get('compare', [ReportController::class, 'compare']);
    });

    /*
    | Alerts
    */
    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/{alert}/read', [AlertController::class, 'markRead']);
    Route::delete('alerts/{alert}', [AlertController::class, 'dismiss']);
});
