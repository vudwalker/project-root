<?php

namespace App\Services\Auth;

use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminDraftShiftReadService;

final class AuthenticatedRedirectService
{
    public function __construct(
        private readonly AdminDraftShiftReadService $adminReadService,
    ) {}

    /**
     * 複数ロール時は、システム管理者、シフト管理者、スタッフの順で優先します。
     */
    public function destination(User $user): string
    {
        $user->loadMissing('roles');

        if ($user->hasRole('system_admin')) {
            return route('admin.top');
        }

        if ($user->hasRole('shift_manager')) {
            $store = $this->adminReadService->accessibleStores($user)->first();

            return $store instanceof Store
                ? route('admin.shifts.stores.show', ['store' => $store->code])
                : route('admin.top');
        }

        if ($user->hasRole('staff')) {
            return route('staff.top');
        }

        return route('auth.access-unavailable');
    }
}
