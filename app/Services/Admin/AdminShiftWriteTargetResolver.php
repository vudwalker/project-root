<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 下書きシフトの保存対象と追加操作の同一性を検証します。
 */
final class AdminShiftWriteTargetResolver
{
    public function resolveEligibleStaff(
        Store $store,
        int $userId,
        CarbonImmutable $workDate,
    ): User {
        $date = $workDate->toDateString();
        $staff = User::query()
            ->whereKey($userId)
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereHas(
                'roles',
                fn (Builder $builder): Builder => $builder->where(
                    'roles.code',
                    'staff',
                ),
            )
            ->whereHas('stores', function (Builder $builder) use ($store, $date): void {
                $builder
                    ->where('stores.id', $store->getKey())
                    ->where('store_user.is_active', true)
                    ->where(function (Builder $period) use ($date): void {
                        $period
                            ->whereNull('store_user.started_on')
                            ->orWhereDate('store_user.started_on', '<=', $date);
                    })
                    ->where(function (Builder $period) use ($date): void {
                        $period
                            ->whereNull('store_user.ended_on')
                            ->orWhereDate('store_user.ended_on', '>=', $date);
                    });
            })
            ->first();

        if (! $staff instanceof User) {
            throw ValidationException::withMessages([
                'user_id' => '対象日にこの店舗へシフトを登録できるスタッフではありません。',
            ]);
        }

        return $staff;
    }

    public function resolveActivePattern(
        Store $store,
        int $patternId,
    ): StoreShiftPattern {
        $pattern = StoreShiftPattern::query()
            ->whereKey($patternId)
            ->where('store_id', $store->getKey())
            ->where('is_active', true)
            ->first();

        if (! $pattern instanceof StoreShiftPattern) {
            throw ValidationException::withMessages([
                'shift_pattern_id' => 'この店舗で利用できるシフトパターンを指定してください。',
            ]);
        }

        return $pattern;
    }

    public function assertIdempotentIdentity(
        Shift $shift,
        Store $store,
        CarbonImmutable $targetMonth,
        int $userId,
        CarbonImmutable $workDate,
        int $patternId,
    ): void {
        $schedule = $shift->schedule;
        $matches = $schedule instanceof ShiftSchedule
            && (int) $schedule->store_id === (int) $store->getKey()
            && $schedule->target_month->toDateString() === $targetMonth->toDateString()
            && (int) $shift->user_id === $userId
            && $shift->work_date->toDateString() === $workDate->toDateString()
            && (int) $shift->store_shift_pattern_id === $patternId;

        if (! $matches) {
            throw ValidationException::withMessages([
                'entry_uuid' => 'この追加識別子は別のシフトで使用されています。',
            ]);
        }
    }
}
