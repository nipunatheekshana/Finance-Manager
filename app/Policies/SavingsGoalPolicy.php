<?php

namespace App\Policies;

use App\Models\SavingsGoal;
use App\Models\User;

/**
 * Ownership check for SavingsGoal. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class SavingsGoalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavingsGoal $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SavingsGoal $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, SavingsGoal $model): bool
    {
        return $user->id === $model->user_id;
    }
}
