<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 管理者用・店舗別シフト編集画面から行う下書き変更を担当します。
 */
final class AdminShiftWriteService
{
    public function __construct(
        private readonly AdminShiftWriteTargetResolver $targetResolver,
        private readonly AdminShiftScheduleWriter $scheduleWriter,
        private readonly AdminShiftWritePayloadFactory $payloadFactory,
    ) {}

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
        int $expectedDraftVersion,
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
                $expectedDraftVersion,
            ): array {
                $existing = Shift::query()
                    ->with('schedule:id,store_id,target_month,draft_version,shift_updated_at')
                    ->where('entry_uuid', $entryUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof Shift) {
                    $this->targetResolver->assertIdempotentIdentity(
                        $existing,
                        $store,
                        $targetMonth,
                        $userId,
                        $workDate,
                        $patternId,
                    );

                    return $this->payloadFactory->savedPayload(
                        $existing,
                        $existing->schedule,
                        false,
                    );
                }

                $staff = $this->targetResolver->resolveEligibleStaff(
                    $store,
                    $userId,
                    $workDate,
                );
                $pattern = $this->targetResolver->resolveActivePattern(
                    $store,
                    $patternId,
                );
                $schedule = $this->scheduleWriter->lockOrCreateSchedule(
                    $store,
                    $actor,
                    $targetMonth,
                );

                // 同じ店舗・対象月の追加要求を直列化した後でもう一度UUIDを確認します。
                $existing = Shift::query()
                    ->where('entry_uuid', $entryUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof Shift) {
                    $existing->load(
                        'schedule:id,store_id,target_month,draft_version,shift_updated_at',
                    );
                    $this->targetResolver->assertIdempotentIdentity(
                        $existing,
                        $store,
                        $targetMonth,
                        $userId,
                        $workDate,
                        $patternId,
                    );

                    return $this->payloadFactory->savedPayload(
                        $existing,
                        $schedule,
                        false,
                    );
                }

                $this->scheduleWriter->assertExpectedVersion(
                    $schedule,
                    $expectedDraftVersion,
                );

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
                $schedule = $this->scheduleWriter->markScheduleChanged(
                    $schedule,
                    $actor,
                );

                return $this->payloadFactory->savedPayload($shift, $schedule, true);
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

            $this->targetResolver->assertIdempotentIdentity(
                $existing,
                $store,
                $targetMonth,
                $userId,
                $workDate,
                $patternId,
            );

            return $this->payloadFactory->savedPayload(
                $existing,
                $existing->schedule,
                false,
            );
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
        int $expectedDraftVersion,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $shiftId,
            $patternId,
            $expectedDraftVersion,
        ): array {
            [$shift, $schedule] = $this->scheduleWriter->lockShift(
                $store,
                $targetMonth,
                $shiftId,
                $expectedDraftVersion,
            );
            $this->targetResolver->resolveEligibleStaff(
                $store,
                (int) $shift->user_id,
                CarbonImmutable::parse(
                    $shift->work_date->toDateString(),
                    (string) config('app.timezone', 'Asia/Tokyo'),
                ),
            );
            $pattern = $this->targetResolver->resolveActivePattern(
                $store,
                $patternId,
            );

            $shift->forceFill([
                'store_shift_pattern_id' => $pattern->getKey(),
                'pattern_code' => $pattern->code,
                'work_minutes' => $pattern->work_minutes,
                'updated_by' => $actor->getKey(),
            ])->save();
            $schedule = $this->scheduleWriter->markScheduleChanged(
                $schedule,
                $actor,
            );

            return $this->payloadFactory->savedPayload($shift, $schedule, false);
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
        int $expectedDraftVersion,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $shiftId,
            $expectedDraftVersion,
        ): array {
            [$shift, $schedule] = $this->scheduleWriter->lockShift(
                $store,
                $targetMonth,
                $shiftId,
                $expectedDraftVersion,
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

            $schedule = $this->scheduleWriter->markScheduleChanged(
                $schedule,
                $actor,
            );

            return [
                'deleted_shift_id' => $deletedShiftId,
                'entry_uuid' => $entryUuid,
                'remaining_shifts' => $remaining
                    ->map(fn (Shift $remainingShift): array => $this->payloadFactory
                        ->normalizeShift($remainingShift))
                    ->all(),
                ...$this->payloadFactory->schedulePayload($schedule),
            ];
        }, 3);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
