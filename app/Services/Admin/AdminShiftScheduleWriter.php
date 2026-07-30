<?php

namespace App\Services\Admin;

use App\Exceptions\DraftVersionConflictException;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 下書きスケジュールのロック、作成、バージョン更新を担当します。
 */
final class AdminShiftScheduleWriter
{
    public function lockOrCreateSchedule(
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
    public function lockShift(
        Store $store,
        CarbonImmutable $targetMonth,
        int $shiftId,
        int $expectedDraftVersion,
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

        $this->assertExpectedVersion($schedule, $expectedDraftVersion);

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

    public function assertExpectedVersion(
        ShiftSchedule $schedule,
        int $expectedDraftVersion,
    ): void {
        $currentDraftVersion = (int) $schedule->draft_version;

        if ($currentDraftVersion !== $expectedDraftVersion) {
            throw new DraftVersionConflictException(
                $expectedDraftVersion,
                $currentDraftVersion,
            );
        }
    }

    public function markScheduleChanged(
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

    private function throwShiftNotFound(int $shiftId): never
    {
        throw (new ModelNotFoundException)->setModel(Shift::class, [$shiftId]);
    }
}
