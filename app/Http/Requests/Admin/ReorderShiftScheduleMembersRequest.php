<?php

namespace App\Http\Requests\Admin;

use App\Rules\SelectableTargetMonth;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ReorderShiftScheduleMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['bail', 'integer', 'distinct'],
            'expected_monthly_members_version' => [
                'bail',
                'required',
                'integer',
                'min:0',
            ],
        ];
    }

    public function targetMonth(): ?CarbonImmutable
    {
        return app(TargetMonthService::class)->parseSelectableMonth(
            (string) $this->input('target_month'),
        );
    }
}
