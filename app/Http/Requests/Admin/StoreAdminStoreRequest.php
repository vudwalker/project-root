<?php

namespace App\Http\Requests\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAdminStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'code', 'area'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('createAdminStore', Store::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'code' => [
                'bail',
                'required',
                'string',
                'max:100',
                Rule::unique('stores', 'code')->where(
                    fn ($query) => $query->where(
                        'organization_id',
                        $organizationId,
                    ),
                ),
            ],
            'area' => ['bail', 'required', 'string', 'max:100'],
            'status' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'display_order' => ['prohibited'],
            'staffing_check_mode' => ['prohibited'],
            'required_staff_count' => ['prohibited'],
        ];
    }
}
