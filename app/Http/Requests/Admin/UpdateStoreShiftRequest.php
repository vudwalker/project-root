<?php

namespace App\Http\Requests\Admin;

use App\Rules\SelectableTargetMonth;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateStoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'target_month' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m',
                new SelectableTargetMonth(app(TargetMonthService::class)),
            ],
            'expected_draft_version' => [
                'bail',
                'required',
                'integer',
                'min:0',
            ],
            'shift_pattern_id' => [
                'bail',
                'required',
                'integer',
                'exists:store_shift_patterns,id',
            ],
            ...collect([
                'organization_id',
                'store_id',
                'shift_schedule_id',
                'user_id',
                'work_date',
                'entry_uuid',
                'sequence',
                'pattern_code',
                'work_minutes',
                'created_by',
                'updated_by',
                'start_time',
                'end_time',
                'break_minutes',
                'memo',
            ])->mapWithKeys(
                fn (string $field): array => [$field => ['prohibited']],
            )->all(),
        ];
    }

    public function targetMonth(): ?CarbonImmutable
    {
        return app(TargetMonthService::class)->parseSelectableMonth(
            (string) $this->input('target_month'),
        );
    }
}
