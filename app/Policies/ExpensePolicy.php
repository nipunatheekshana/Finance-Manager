<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Ownership check for Expense. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expense $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Expense $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, Expense $model): bool
    {
        return $user->id === $model->user_id;
    }
}
