<?php

namespace App\Policies;

use App\Models\Debt;
use App\Models\User;

/**
 * Ownership check for Debt. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class DebtPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Debt $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Debt $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, Debt $model): bool
    {
        return $user->id === $model->user_id;
    }
}
