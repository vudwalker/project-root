<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;

final class StorePolicy
{
    /**
     * 管理者用シフト画面で対象店舗を閲覧できるか判定します。
     */
    public function viewAdminShifts(User $user, Store $store): bool
    {
        if (! $user->canAccessAdmin()) {
            return false;
        }

        if ((int) $user->organization_id !== (int) $store->organization_id) {
            return false;
        }

        if ($user->hasRole('system_admin')) {
            return true;
        }

        if (! $store->isActive() || ! $user->hasRole('shift_manager')) {
            return false;
        }

        $today = CarbonImmutable::now((string) config('app.timezone', 'Asia/Tokyo'))
            ->toDateString();

        return $user->managedStores()
            ->whereKey($store->getKey())
            ->wherePivot('is_active', true)
            ->where(function ($query) use ($today): void {
                $query
                    ->whereNull('store_shift_manager.started_on')
                    ->orWhereDate('store_shift_manager.started_on', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query
                    ->whereNull('store_shift_manager.ended_on')
                    ->orWhereDate('store_shift_manager.ended_on', '>=', $today);
            })
            ->exists();
    }
}
