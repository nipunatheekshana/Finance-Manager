<?php

namespace App\Policies;

use App\Models\IncomeTransaction;
use App\Models\User;

/**
 * Ownership check for IncomeTransaction. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class IncomeTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IncomeTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, IncomeTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, IncomeTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }
}
