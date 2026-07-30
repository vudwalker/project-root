<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminStoreDetailsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'area'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if ($this->input('area') === '') {
            $this->merge(['area' => null]);
        }

        $patterns = collect($this->input('patterns', []))
            ->map(function (mixed $pattern): mixed {
                if (! is_array($pattern)) {
                    return $pattern;
                }

                foreach (['code', 'start_time', 'end_time', 'work_hours'] as $field) {
                    if (isset($pattern[$field]) && is_string($pattern[$field])) {
                        $pattern[$field] = trim($pattern[$field]);
                    }
                }

                foreach (['start_time', 'end_time'] as $field) {
                    if (($pattern[$field] ?? null) === '') {
                        $pattern[$field] = null;
                    }
                }

                return $pattern;
            })
            ->values()
            ->all();

        $options = collect($this->input('staffing_options', []))
            ->map(function (mixed $option): mixed {
                if (! is_array($option)) {
                    return $option;
                }

                if (isset($option['code']) && is_string($option['code'])) {
                    $option['code'] = trim($option['code']);
                }

                return $option;
            })
            ->values()
            ->all();

        if ($this->input('required_staff_count') === '') {
            $this->merge(['required_staff_count' => null]);
        }

        $normalized = [
            'patterns' => $patterns,
            'staff_user_ids' => $this->normalizedIds('staff_user_ids'),
            'staffing_options' => $options,
        ];

        if ($this->user()?->hasRole('system_admin') === true) {
            $normalized['manager_user_ids'] = $this->normalizedIds(
                'manager_user_ids',
            );
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canAccessAdmin();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $canManageManagers = $this->user()?->hasRole('system_admin') === true;

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'staff_user_ids' => ['present', 'array'],
            'staff_user_ids.*' => ['integer', 'distinct'],
            'manager_user_ids' => $canManageManagers
                ? ['present', 'array']
                : ['prohibited'],
            'manager_user_ids.*' => ['integer', 'distinct'],
            'patterns' => ['present', 'array', 'max:50'],
            'patterns.*.id' => ['nullable', 'integer', 'distinct'],
            'patterns.*.code' => ['required', 'string', 'max:20'],
            'patterns.*.start_time' => ['nullable', 'date_format:H:i'],
            'patterns.*.end_time' => ['nullable', 'date_format:H:i'],
            'patterns.*.work_hours' => [
                'required',
                'decimal:0,2',
                'min:0',
                'max:9999.99',
            ],
            'staffing_check_mode' => [
                'required',
                'string',
                Rule::in(['disabled', 'fixed_total', 'pattern_combinations']),
            ],
            'required_staff_count' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('staffing_check_mode') === 'fixed_total',
                ),
                'nullable',
                'integer',
                'min:0',
            ],
            'staffing_options' => ['present', 'array', 'max:20'],
            'staffing_options.*.id' => ['nullable', 'integer', 'distinct'],
            'staffing_options.*.code' => ['nullable', 'string', 'max:50'],
            'staffing_options.*.display_order' => ['nullable', 'integer', 'min:0'],
            'staffing_options.*.remove' => ['required', 'boolean'],
            'staffing_options.*.pattern_counts' => ['sometimes', 'array'],
            'staffing_options.*.pattern_counts.*' => [
                'nullable',
                'integer',
                'min:0',
            ],
            ...collect([
                'organization_id',
                'code',
                'status',
                'display_order',
                'deleted_at',
            ])->mapWithKeys(
                fn (string $field): array => [$field => ['prohibited']],
            )->all(),
        ];
    }

    /**
     * 空の選択状態も明示的な空配列として扱います。
     *
     * @return array<int, mixed>|mixed
     */
    private function normalizedIds(string $field): mixed
    {
        $ids = $this->input($field, []);

        if (! is_array($ids)) {
            return $ids;
        }

        return collect($ids)
            ->reject(fn (mixed $id): bool => $id === null || $id === '')
            ->values()
            ->all();
    }
}
