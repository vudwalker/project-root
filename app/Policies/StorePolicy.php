<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;

final class StorePolicy
{
    /**
     * 管理者用店舗一覧を利用できるか判定します。
     */
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    /**
     * 管理者用店舗管理で対象店舗を閲覧できるか判定します。
     */
    public function viewAdminStoreManagement(User $user, Store $store): bool
    {
        if (
            ! $user->canAccessAdmin()
            || (int) $user->organization_id !== (int) $store->organization_id
        ) {
            return false;
        }

        if ($user->hasRole('system_admin')) {
            return true;
        }

        return $store->isActive()
            && $user->hasRole('shift_manager')
            && $this->hasCurrentManagerAssignment($user, $store);
    }

    /**
     * 管理者用店舗管理で対象店舗を更新できるか判定します。
     */
    public function updateAdminStoreManagement(User $user, Store $store): bool
    {
        return $this->viewAdminStoreManagement($user, $store);
    }

    /**
     * 店舗状態を変更できるのは、同一組織のシステム管理者だけです。
     */
    public function changeAdminStoreStatus(User $user, Store $store): bool
    {
        return $user->canAccessAdmin()
            && $user->hasRole('system_admin')
            && (int) $user->organization_id === (int) $store->organization_id;
    }

    /**
     * 管理者用シフト画面で対象店舗を閲覧できるか判定します。
     */
    public function viewAdminShifts(User $user, Store $store): bool
    {
        return $this->viewAdminStoreManagement($user, $store);
    }

    /**
     * 管理者用店舗別シフト編集画面から下書きを変更できるか判定します。
     */
    public function editAdminShifts(User $user, Store $store): bool
    {
        if (! $store->isActive() || ! $this->viewAdminShifts($user, $store)) {
            return false;
        }

        return $user->hasRole('system_admin', 'shift_manager');
    }

    /**
     * 管理者用店舗別シフト編集画面から公開版を配布できるか判定します。
     */
    public function publishAdminShifts(User $user, Store $store): bool
    {
        return $this->editAdminShifts($user, $store);
    }

    private function hasCurrentManagerAssignment(User $user, Store $store): bool
    {
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
