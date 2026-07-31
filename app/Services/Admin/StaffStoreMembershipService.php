<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * スタッフと店舗の一つの勤務可能所属だけを更新します。
 *
 * トランザクション境界と、店舗軸・スタッフ軸の一括同期は呼出元が管理します。
 */
final class StaffStoreMembershipService
{
    public function activate(Store $store, User $staff, string $startedOn): void
    {
        $this->assertSameOrganization($store, $staff);
        $staff->loadMissing('roles');

        if ($staff->status !== 'active' || ! $staff->hasRole('staff')) {
            throw ValidationException::withMessages([
                'store_ids' => '勤務可能店舗を追加できるのは、同一組織の在籍中のstaffだけです。',
            ]);
        }

        $this->lockStore($store);
        $membership = DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->where('user_id', $staff->getKey())
            ->lockForUpdate()
            ->first();

        if ($membership !== null && $this->isCurrent($membership, $startedOn)) {
            return;
        }

        $now = now();

        if ($membership !== null) {
            DB::table('store_user')
                ->where('id', $membership->id)
                ->update([
                    'is_active' => true,
                    'started_on' => $startedOn,
                    'ended_on' => null,
                    'updated_at' => $now,
                ]);

            return;
        }

        $displayOrder = (int) DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->max('display_order') + 1;

        try {
            DB::table('store_user')->insert([
                'store_id' => $store->getKey(),
                'user_id' => $staff->getKey(),
                'display_order' => $displayOrder,
                'is_active' => true,
                'started_on' => $startedOn,
                'ended_on' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'store_ids' => 'このスタッフは既に対象店舗へ所属しています。',
            ]);
        }
    }

    public function deactivate(Store $store, User $staff, string $endedOn): void
    {
        $this->assertSameOrganization($store, $staff);
        $this->lockStore($store);
        $membership = DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->where('user_id', $staff->getKey())
            ->lockForUpdate()
            ->first();

        if ($membership === null || ! (bool) $membership->is_active) {
            return;
        }

        DB::table('store_user')
            ->where('id', $membership->id)
            ->update([
                'is_active' => false,
                'ended_on' => $this->safeEndedOn($membership, $endedOn),
                'updated_at' => now(),
            ]);
    }

    public function assertSameOrganization(Store $store, User $staff): void
    {
        if ((int) $store->organization_id !== (int) $staff->organization_id) {
            throw ValidationException::withMessages([
                'store_ids' => '同一組織の店舗だけを勤務可能店舗として指定してください。',
            ]);
        }
    }

    private function lockStore(Store $store): void
    {
        $lockedStore = Store::query()
            ->whereKey($store->getKey())
            ->where('organization_id', $store->organization_id)
            ->lockForUpdate()
            ->first(['id']);

        if (! $lockedStore instanceof Store) {
            throw ValidationException::withMessages([
                'store_ids' => '指定された店舗を利用できません。',
            ]);
        }
    }

    private function isCurrent(object $membership, string $date): bool
    {
        return (bool) $membership->is_active
            && ($membership->started_on === null || $membership->started_on <= $date)
            && ($membership->ended_on === null || $membership->ended_on >= $date);
    }

    private function safeEndedOn(object $membership, string $endedOn): string
    {
        return $membership->started_on !== null
            && $membership->started_on > $endedOn
                ? $membership->started_on
                : $endedOn;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array(
            (string) ($exception->errorInfo[0] ?? $exception->getCode()),
            ['23000', '23505'],
            true,
        );
    }
}
