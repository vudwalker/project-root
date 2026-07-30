<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('required_staff_count') === '') {
            $this->merge(['required_staff_count' => null]);
        }
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
        $user = $this->user();
        $canChangeStatus = $user instanceof User
            && $user->hasRole('system_admin');

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'status' => $canChangeStatus
                ? ['bail', 'required', 'string', Rule::in(['active', 'inactive'])]
                : ['prohibited'],
            'display_order' => ['bail', 'required', 'integer', 'min:0'],
            'staffing_check_mode' => [
                'bail',
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
            'filter_status' => [
                'sometimes',
                'string',
                Rule::in(['all', 'active', 'inactive']),
            ],
            ...collect([
                'organization_id',
                'code',
                'deleted_at',
            ])->mapWithKeys(
                fn (string $field): array => [$field => ['prohibited']],
            )->all(),
        ];
    }
}
