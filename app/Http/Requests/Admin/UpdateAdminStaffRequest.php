<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateAdminStaffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $password = $this->input('password');

        $this->merge([
            'name' => is_string($this->input('name'))
                ? trim($this->input('name'))
                : $this->input('name'),
            'email' => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),
            'password' => $password === '' ? null : $password,
            'password_confirmation' => $password === ''
                ? null
                : $this->input('password_confirmation'),
            'store_ids' => $this->normalizedIds(),
        ]);
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor instanceof User
            && $target instanceof User
            && Gate::forUser($actor)->allows('update', $target);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $canManageShiftManagerRole = $this->user()?->hasRole('system_admin') === true;

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'status' => [
                'required',
                'string',
                Rule::in(['active', 'on_leave', 'retired']),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'staff_role' => ['required', 'boolean'],
            'shift_manager_role' => $canManageShiftManagerRole
                ? ['required', 'boolean']
                : ['prohibited'],
            'store_ids' => ['present', 'array'],
            'store_ids.*' => ['integer', 'distinct'],
            'organization_id' => ['prohibited'],
            'system_admin_role' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, mixed>|mixed
     */
    private function normalizedIds(): mixed
    {
        $ids = $this->input('store_ids', []);

        if (! is_array($ids)) {
            return $ids;
        }

        return collect($ids)
            ->reject(fn (mixed $id): bool => $id === null || $id === '')
            ->values()
            ->all();
    }
}
