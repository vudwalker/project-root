<?php

namespace App\Http\Requests\Admin;

use App\Rules\SelectableTargetMonth;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteStoreShiftRequest extends FormRequest
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
        ];
    }

    public function targetMonth(): ?CarbonImmutable
    {
        return app(TargetMonthService::class)->parseSelectableMonth(
            (string) $this->input('target_month'),
        );
    }
}
