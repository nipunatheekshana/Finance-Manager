<?php

namespace App\Policies;

use App\Models\FinancialAlert;
use App\Models\User;

/**
 * Ownership check for FinancialAlert. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class FinancialAlertPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FinancialAlert $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FinancialAlert $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, FinancialAlert $model): bool
    {
        return $user->id === $model->user_id;
    }
}
