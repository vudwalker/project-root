<?php

namespace App\Http\Requests\Admin;

use App\Rules\SelectableTargetMonth;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreShiftRequest extends FormRequest
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
            'user_id' => ['bail', 'required', 'integer', 'exists:users,id'],
            'work_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'shift_pattern_id' => [
                'bail',
                'required',
                'integer',
                'exists:store_shift_patterns,id',
            ],
            'entry_uuid' => ['bail', 'required', 'string', 'uuid'],
            ...$this->serverManagedRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('target_month')
                    || $validator->errors()->has('work_date')
                ) {
                    return;
                }

                $targetMonth = $this->targetMonth();
                $workDate = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    (string) $this->input('work_date'),
                    (string) config('app.timezone', 'Asia/Tokyo'),
                );

                if ($targetMonth === null || $workDate->format('Y-m') !== $targetMonth->format('Y-m')) {
                    $validator->errors()->add(
                        'work_date',
                        '勤務日は対象月の範囲内で指定してください。',
                    );
                }
            },
        ];
    }

    public function targetMonth(): ?CarbonImmutable
    {
        return app(TargetMonthService::class)->parseSelectableMonth(
            (string) $this->input('target_month'),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function serverManagedRules(): array
    {
        return collect([
            'organization_id',
            'store_id',
            'shift_schedule_id',
            'sequence',
            'pattern_code',
            'work_minutes',
            'work_hours',
            'created_by',
            'updated_by',
            'start_time',
            'end_time',
            'break_minutes',
            'memo',
        ])->mapWithKeys(
            fn (string $field): array => [$field => ['prohibited']],
        )->all();
    }
}
