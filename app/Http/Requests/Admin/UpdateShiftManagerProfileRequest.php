<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateShiftManagerProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $password = $this->profileInput('profile_password');

        $normalized = [
            'name' => is_string($this->profileInput('profile_name'))
                ? trim($this->profileInput('profile_name'))
                : $this->profileInput('profile_name'),
            'email' => is_string($this->profileInput('profile_email'))
                ? Str::lower(trim($this->profileInput('profile_email')))
                : $this->profileInput('profile_email'),
            'status' => $this->profileInput('status'),
            'password' => $password === '' ? null : $password,
            'password_confirmation' => $password === ''
                ? null
                : $this->profileInput('profile_password_confirmation'),
        ];

        $storeIds = $this->profileInput('store_ids');

        if ($storeIds !== null) {
            $normalized['store_ids'] = is_array($storeIds)
                ? collect($storeIds)
                    ->reject(fn (mixed $id): bool => $id === null || $id === '')
                    ->values()
                    ->all()
                : $storeIds;
        }

        $this->merge($normalized);
    }

    private function profileInput(string $key): mixed
    {
        $target = $this->route('user');

        if ($target instanceof User && $key !== 'store_ids') {
            $scopedValue = $this->input($key.'.'.$target->getKey());

            if ($scopedValue !== null) {
                return $scopedValue;
            }
        }

        return $this->input($key);
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        return $actor instanceof User
            && $target instanceof User
            && Gate::forUser($actor)->allows('manageShiftManagerProfile', $target);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'status' => [
                'required',
                'string',
                Rule::in(['active', 'on_leave', 'retired']),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer', 'distinct'],
        ];
    }
}
