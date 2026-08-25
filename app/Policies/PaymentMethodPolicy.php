<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Ownership check for PaymentMethod. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class PaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PaymentMethod $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PaymentMethod $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, PaymentMethod $model): bool
    {
        return $user->id === $model->user_id;
    }
}
