<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminStoreRequest extends FormRequest
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
            'area' => ['nullable', 'string', 'max:100'],
            'status' => $canChangeStatus
                ? ['bail', 'required', 'string', Rule::in(['active', 'inactive'])]
                : ['prohibited'],
            ...collect([
                'organization_id',
                'code',
                'display_order',
                'staffing_check_mode',
                'required_staff_count',
                'deleted_at',
            ])->mapWithKeys(
                fn (string $field): array => [$field => ['prohibited']],
            )->all(),
        ];
    }
}
