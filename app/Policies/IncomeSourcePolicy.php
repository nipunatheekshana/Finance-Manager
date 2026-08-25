<?php

namespace App\Policies;

use App\Models\IncomeSource;
use App\Models\User;

/**
 * Ownership check for IncomeSource. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class IncomeSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IncomeSource $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, IncomeSource $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, IncomeSource $model): bool
    {
        return $user->id === $model->user_id;
    }
}
