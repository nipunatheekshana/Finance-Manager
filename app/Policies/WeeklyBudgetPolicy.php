<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WeeklyBudget;

class WeeklyBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WeeklyBudget $week): bool
    {
        return $user->id === $week->monthlyPlan->user_id;
    }

    public function update(User $user, WeeklyBudget $week): bool
    {
        return $user->id === $week->monthlyPlan->user_id;
    }

    public function delete(User $user, WeeklyBudget $week): bool
    {
        return $user->id === $week->monthlyPlan->user_id;
    }
}
