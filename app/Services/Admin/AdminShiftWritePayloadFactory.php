<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\ShiftSchedule;

/**
 * 下書きシフト保存APIのレスポンス配列を生成します。
 */
final class AdminShiftWritePayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function savedPayload(
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
    public function normalizeShift(Shift $shift): array
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
    public function schedulePayload(ShiftSchedule $schedule): array
    {
        return [
            'shift_schedule_id' => (int) $schedule->getKey(),
            'draft_version' => (int) $schedule->draft_version,
            'saved_at' => $schedule->shift_updated_at?->toIso8601String(),
            'save_status' => '保存済み',
        ];
    }
}
