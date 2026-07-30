<?php

namespace App\Http\Requests\Admin;

use App\Rules\SelectableTargetMonth;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class PublishStoreShiftRequest extends FormRequest
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
            ...collect([
                'organization_id',
                'store_id',
                'shift_schedule_id',
                'published_version',
                'published_draft_version',
                'published_at',
                'published_by_user_id',
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
