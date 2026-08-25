<?php

namespace App\Policies;

use App\Models\RecurringTransaction;
use App\Models\User;

/**
 * Ownership check for RecurringTransaction. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class RecurringTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecurringTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RecurringTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, RecurringTransaction $model): bool
    {
        return $user->id === $model->user_id;
    }
}
