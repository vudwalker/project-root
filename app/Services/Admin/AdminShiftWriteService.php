<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理者用店舗別シフト編集画面から行う下書き変更を担当します。
 */
final class AdminShiftWriteService
{
    /**
     * @return array<string, mixed>
     */
    public function create(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        int $userId,
        CarbonImmutable $workDate,
        int $patternId,
        string $entryUuid,
    ): array {
        try {
            return DB::transaction(function () use (
                $store,
                $actor,
                $targetMonth,
                $userId,
                $workDate,
                $patternId,
                $entryUuid,
            ): array {
                $existing = Shift::query()
                    ->with('schedule:id,store_id,target_month,draft_version,shift_updated_at')
                    ->where('entry_uuid', $entryUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof Shift) {
                    $this->assertIdempotentIdentity(
                        $existing,
                        $store,
                        $targetMonth,
                        $userId,
                        $workDate,
                        $patternId,
                    );

                    return $this->savedPayload($existing, $existing->schedule, false);
                }

                $staff = $this->resolveEligibleStaff($store, $userId, $workDate);
                $pattern = $this->resolveActivePattern($store, $patternId);
                $schedule = $this->lockOrCreateSchedule($store, $actor, $targetMonth);

                // 同じ店舗・対象月の追加要求を直列化した後でもう一度UUIDを確認します。
                $existing = Shift::query()
                    ->where('entry_uuid', $entryUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof Shift) {
                    $existing->load(
                        'schedule:id,store_id,target_month,draft_version,shift_updated_at',
                    );
                    $this->assertIdempotentIdentity(
                        $existing,
                        $store,
                        $targetMonth,
                        $userId,
                        $workDate,
                        $patternId,
                    );

                    return $this->savedPayload($existing, $schedule, false);
                }

                $nextSequence = (int) Shift::query()
                    ->where('shift_schedule_id', $schedule->getKey())
                    ->where('user_id', $staff->getKey())
                    ->whereDate('work_date', $workDate->toDateString())
                    ->max('sequence') + 1;

                $shift = Shift::query()->create([
                    'shift_schedule_id' => $schedule->getKey(),
                    'user_id' => $staff->getKey(),
                    'work_date' => $workDate,
                    'store_shift_pattern_id' => $pattern->getKey(),
                    'sequence' => $nextSequence,
                    'entry_uuid' => $entryUuid,
                    'pattern_code' => $pattern->code,
                    'work_minutes' => $pattern->work_minutes,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
                $schedule = $this->markScheduleChanged($schedule, $actor);

                return $this->savedPayload($shift, $schedule, true);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            // 別トランザクションが同じUUIDを先に保存した場合も再送として解決します。
            $existing = Shift::query()
                ->with('schedule:id,store_id,target_month,draft_version,shift_updated_at')
                ->where('entry_uuid', $entryUuid)
                ->first();

            if (! $existing instanceof Shift) {
                throw $exception;
            }

            $this->assertIdempotentIdentity(
                $existing,
                $store,
                $targetMonth,
                $userId,
                $workDate,
                $patternId,
            );

            return $this->savedPayload($existing, $existing->schedule, false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function update(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        int $shiftId,
        int $patternId,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $shiftId,
            $patternId,
        ): array {
            [$shift, $schedule] = $this->lockShift(
                $store,
                $targetMonth,
                $shiftId,
            );
            $this->resolveEligibleStaff(
                $store,
                (int) $shift->user_id,
                CarbonImmutable::parse(
                    $shift->work_date->toDateString(),
                    (string) config('app.timezone', 'Asia/Tokyo'),
                ),
            );
            $pattern = $this->resolveActivePattern($store, $patternId);

            $shift->forceFill([
                'store_shift_pattern_id' => $pattern->getKey(),
                'pattern_code' => $pattern->code,
                'work_minutes' => $pattern->work_minutes,
                'updated_by' => $actor->getKey(),
            ])->save();
            $schedule = $this->markScheduleChanged($schedule, $actor);

            return $this->savedPayload($shift, $schedule, false);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        int $shiftId,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $shiftId,
        ): array {
            [$shift, $schedule] = $this->lockShift(
                $store,
                $targetMonth,
                $shiftId,
            );
            $deletedShiftId = (int) $shift->getKey();
            $entryUuid = (string) $shift->entry_uuid;
            $userId = (int) $shift->user_id;
            $workDate = $shift->work_date->toDateString();

            $shift->delete();

            $remaining = Shift::query()
                ->where('shift_schedule_id', $schedule->getKey())
                ->where('user_id', $userId)
                ->whereDate('work_date', $workDate)
                ->orderBy('sequence')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($remaining as $index => $remainingShift) {
                $sequence = $index + 1;

                if ((int) $remainingShift->sequence === $sequence) {
                    continue;
                }

                $remainingShift->forceFill([
                    'sequence' => $sequence,
                    'updated_by' => $actor->getKey(),
                ])->save();
            }

            $schedule = $this->markScheduleChanged($schedule, $actor);

            return [
                'deleted_shift_id' => $deletedShiftId,
                'entry_uuid' => $entryUuid,
                'remaining_shifts' => $remaining
                    ->map(fn (Shift $remainingShift): array => $this->normalizeShift(
                        $remainingShift,
                    ))
                    ->all(),
                ...$this->schedulePayload($schedule),
            ];
        }, 3);
    }

    private function resolveEligibleStaff(
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

    private function resolveActivePattern(Store $store, int $patternId): StoreShiftPattern
    {
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

    private function lockOrCreateSchedule(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
    ): ShiftSchedule {
        $schedule = ShiftSchedule::query()
            ->where('store_id', $store->getKey())
            ->whereDate('target_month', $targetMonth->toDateString())
            ->lockForUpdate()
            ->first();

        if ($schedule instanceof ShiftSchedule) {
            return $schedule;
        }

        $timestamp = now((string) config('app.timezone', 'Asia/Tokyo'));

        ShiftSchedule::query()->insertOrIgnore([
            'store_id' => $store->getKey(),
            'target_month' => $targetMonth->toDateString(),
            'draft_version' => 0,
            'published_version' => null,
            'shift_updated_at' => null,
            'published_at' => null,
            'published_by' => null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return ShiftSchedule::query()
            ->where('store_id', $store->getKey())
            ->whereDate('target_month', $targetMonth->toDateString())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{0: Shift, 1: ShiftSchedule}
     */
    private function lockShift(
        Store $store,
        CarbonImmutable $targetMonth,
        int $shiftId,
    ): array {
        $reference = Shift::query()
            ->select(['id', 'shift_schedule_id'])
            ->find($shiftId);

        if (! $reference instanceof Shift) {
            $this->throwShiftNotFound($shiftId);
        }

        $schedule = ShiftSchedule::query()
            ->whereKey($reference->shift_schedule_id)
            ->lockForUpdate()
            ->first();

        if (
            ! $schedule instanceof ShiftSchedule
            || (int) $schedule->store_id !== (int) $store->getKey()
            || $schedule->target_month->toDateString() !== $targetMonth->toDateString()
        ) {
            $this->throwShiftNotFound($shiftId);
        }

        $shift = Shift::query()
            ->whereKey($shiftId)
            ->where('shift_schedule_id', $schedule->getKey())
            ->lockForUpdate()
            ->first();

        if (! $shift instanceof Shift) {
            $this->throwShiftNotFound($shiftId);
        }

        return [$shift, $schedule];
    }

    private function assertIdempotentIdentity(
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

    private function markScheduleChanged(
        ShiftSchedule $schedule,
        User $actor,
    ): ShiftSchedule {
        $schedule->forceFill([
            'draft_version' => (int) $schedule->draft_version + 1,
            'shift_updated_at' => now((string) config('app.timezone', 'Asia/Tokyo')),
            'updated_by' => $actor->getKey(),
        ])->save();

        return $schedule->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function savedPayload(
        Shift $shift,
        ShiftSchedule $schedule,
        bool $created,
    ): array {
        return [
            ...$this->normalizeShift($shift),
            'created' => $created,
            ...$this->schedulePayload($schedule),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function normalizeShift(Shift $shift): array
    {
        return [
            'shift_id' => (int) $shift->getKey(),
            'entry_uuid' => (string) $shift->entry_uuid,
            'sequence' => (int) $shift->sequence,
            'user_id' => (int) $shift->user_id,
            'shift_date' => $shift->work_date->toDateString(),
            'shift_pattern_id' => (int) $shift->store_shift_pattern_id,
            'pattern_code' => (string) $shift->pattern_code,
            'work_minutes' => (int) $shift->work_minutes,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function schedulePayload(ShiftSchedule $schedule): array
    {
        return [
            'shift_schedule_id' => (int) $schedule->getKey(),
            'draft_version' => (int) $schedule->draft_version,
            'saved_at' => $schedule->shift_updated_at?->toIso8601String(),
            'save_status' => '保存済み',
        ];
    }

    private function throwShiftNotFound(int $shiftId): never
    {
        throw (new ModelNotFoundException)->setModel(Shift::class, [$shiftId]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
