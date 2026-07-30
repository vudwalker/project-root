<?php

namespace App\Services\Admin;

use App\Exceptions\ShiftPublicationBlockedException;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AdminShiftPublishService
{
    public function __construct(
        private readonly AdminShiftScheduleWriter $scheduleWriter,
        private readonly DraftShiftWarningService $warningService,
        private readonly PublishedShiftSnapshotWriter $snapshotWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publish(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        int $expectedDraftVersion,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $expectedDraftVersion,
        ): array {
            $schedule = $this->scheduleWriter->lockOrCreateSchedule(
                $store,
                $actor,
                $targetMonth,
            );
            $this->scheduleWriter->assertExpectedVersion(
                $schedule,
                $expectedDraftVersion,
            );

            $warningResult = $this->warningService->evaluate(
                $store,
                $targetMonth,
            );

            if (! $warningResult['can_publish']) {
                throw new ShiftPublicationBlockedException($warningResult);
            }

            $draftVersion = (int) $schedule->draft_version;

            if (
                $schedule->published_draft_version !== null
                && (int) $schedule->published_draft_version === $draftVersion
            ) {
                return $this->payload($schedule, $warningResult, true);
            }

            $publishedAt = CarbonImmutable::now(
                (string) config('app.timezone', 'Asia/Tokyo'),
            );
            $rows = Shift::query()
                ->where('shift_schedule_id', $schedule->getKey())
                ->orderBy('user_id')
                ->orderBy('work_date')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get([
                    'user_id',
                    'work_date',
                    'sequence',
                    'pattern_code',
                    'work_hours',
                ])
                ->map(fn (Shift $shift): array => [
                    'shift_schedule_id' => (int) $schedule->getKey(),
                    'user_id' => (int) $shift->user_id,
                    'work_date' => $shift->work_date->toDateString(),
                    'sequence' => (int) $shift->sequence,
                    'pattern_code' => $shift->pattern_code,
                    'work_hours' => $shift->work_hours,
                    'published_at' => $publishedAt,
                    'created_at' => $publishedAt,
                    'updated_at' => $publishedAt,
                ])
                ->all();
            $nextPublishedVersion = (int) ($schedule->published_version ?? 0) + 1;

            $this->snapshotWriter->replace(
                (int) $schedule->getKey(),
                $rows,
            );

            $schedule->forceFill([
                'published_version' => $nextPublishedVersion,
                'published_draft_version' => $draftVersion,
                'published_at' => $publishedAt,
                'published_by_user_id' => $actor->getKey(),
            ])->save();

            return $this->payload(
                $schedule->refresh(),
                $warningResult,
                false,
                count($rows),
            );
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $warningResult
     * @return array<string, mixed>
     */
    private function payload(
        ShiftSchedule $schedule,
        array $warningResult,
        bool $idempotent,
        ?int $publishedShiftCount = null,
    ): array {
        return [
            'published' => true,
            'idempotent' => $idempotent,
            'shift_schedule_id' => (int) $schedule->getKey(),
            'draft_version' => (int) $schedule->draft_version,
            'published_version' => (int) $schedule->published_version,
            'published_draft_version' => (int) $schedule->published_draft_version,
            'published_at' => $schedule->published_at?->toIso8601String(),
            'published_by_user_id' => $schedule->published_by_user_id === null
                ? null
                : (int) $schedule->published_by_user_id,
            'published_shift_count' => $publishedShiftCount
                ?? $schedule->publishedShifts()->count(),
            'warning_result' => $warningResult,
        ];
    }
}
