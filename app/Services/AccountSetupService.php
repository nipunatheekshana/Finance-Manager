<?php

namespace App\Services;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new account the defaults it needs to be usable immediately:
 * a financial profile, the standard categories and the standard payment
 * methods. Safe to call repeatedly — nothing is duplicated.
 */
class AccountSetupService
{
    public function prepare(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->ensureProfile($user);
            $this->ensureCategories($user);
            $this->ensurePaymentMethods($user);
        });
    }

    private function ensureProfile(User $user): void
    {
        $user->financialProfile()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'base_salary' => 0,
                'salary_day' => 25,
                'default_buffer' => 0,
                'extra_debt_percentage' => 50,
                'extra_savings_percentage' => 30,
                'extra_spending_percentage' => 20,
            ]
        );
    }

    private function ensureCategories(User $user): void
    {
        if ($user->categories()->exists()) {
            return;
        }

        foreach (Category::DEFAULTS as $index => $default) {
            $user->categories()->create($default + [
                'is_default' => true,
                'active' => true,
                'sort_order' => $index,
            ]);
        }
    }

    private function ensurePaymentMethods(User $user): void
    {
        if ($user->paymentMethods()->exists()) {
            return;
        }

        foreach (PaymentMethod::DEFAULTS as $index => $default) {
            $user->paymentMethods()->create($default + [
                'is_default' => $index === 0,
                'active' => true,
                'sort_order' => $index,
            ]);
        }
    }
}
