<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminStoreStaffingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('required_staff_count') === '') {
            $this->merge(['required_staff_count' => null]);
        }

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

        $this->merge(['staffing_options' => $options]);
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
        return [
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
        ];
    }
}
