<?php

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAccessAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->canAccessAdmin();
    }

    public function update(User $actor, User $target): bool
    {
        if (
            ! $actor->canAccessAdmin()
            || (int) $actor->organization_id !== (int) $target->organization_id
            || $target->trashed()
        ) {
            return false;
        }

        $target->loadMissing('roles');

        if (! $target->hasRole('staff', 'shift_manager', 'system_admin')) {
            return false;
        }

        return $actor->hasRole('system_admin')
            || ! $target->hasRole('system_admin');
    }

    public function manageShiftManagerRole(User $actor, User $target): bool
    {
        return $this->update($actor, $target)
            && $actor->hasRole('system_admin');
    }
}
