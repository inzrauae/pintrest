<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user data isolation: a user may only touch records they own.
 */
abstract class OwnedResourcePolicy
{
    public function view(User $user, Model $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function update(User $user, Model $model): bool
    {
        return $model->user_id === $user->id;
    }

    public function delete(User $user, Model $model): bool
    {
        return $model->user_id === $user->id;
    }
}
