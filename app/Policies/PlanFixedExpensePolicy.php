<?php

namespace App\Policies;

use App\Models\PlanFixedExpense;
use App\Models\User;

class PlanFixedExpensePolicy
{
    public function view(User $user, PlanFixedExpense $row): bool
    {
        return $user->id === $row->monthlyPlan->user_id;
    }

    public function update(User $user, PlanFixedExpense $row): bool
    {
        return $user->id === $row->monthlyPlan->user_id;
    }

    public function delete(User $user, PlanFixedExpense $row): bool
    {
        return $user->id === $row->monthlyPlan->user_id;
    }
}
