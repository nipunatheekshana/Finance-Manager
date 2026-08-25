<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Ownership check for Category. Every record belongs to exactly one user and is
 * only ever reachable by that user.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Category $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, Category $model): bool
    {
        return $user->id === $model->user_id;
    }
}
