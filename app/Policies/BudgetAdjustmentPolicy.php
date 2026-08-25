<?php

namespace App\Policies;

use App\Models\BudgetAdjustment;
use App\Models\User;

/**
 * Ownership check for BudgetAdjustment. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class BudgetAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BudgetAdjustment $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BudgetAdjustment $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, BudgetAdjustment $model): bool
    {
        return $user->id === $model->user_id;
    }
}
