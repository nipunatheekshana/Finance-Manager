<?php

namespace App\Policies;

use App\Models\SavingsTransaction;
use App\Models\User;

/**
 * Ownership check for SavingsTransaction. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class SavingsTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavingsTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SavingsTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, SavingsTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }
}
