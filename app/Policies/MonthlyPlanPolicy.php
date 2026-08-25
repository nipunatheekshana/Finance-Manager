<?php

namespace App\Policies;

use App\Models\MonthlyPlan;
use App\Models\User;

/**
 * Ownership check for MonthlyPlan. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class MonthlyPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MonthlyPlan $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MonthlyPlan $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, MonthlyPlan $model): bool
    {
        return $user->id === $model->user_id;
    }
}
