<?php

namespace App\Policies;

use App\Models\DebtPayment;
use App\Models\User;

class DebtPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DebtPayment $payment): bool
    {
        return $user->id === $payment->debt->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DebtPayment $payment): bool
    {
        return $user->id === $payment->debt->user_id;
    }

    public function delete(User $user, DebtPayment $payment): bool
    {
        return $user->id === $payment->debt->user_id;
    }
}
