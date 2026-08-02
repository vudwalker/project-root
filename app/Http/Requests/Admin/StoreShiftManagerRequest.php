<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreShiftManagerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('create_name', $this->input('name'));
        $email = $this->input('create_email', $this->input('email'));
        $password = $this->input('create_password', $this->input('password'));
        $passwordConfirmation = $this->input(
            'create_password_confirmation',
            $this->input('password_confirmation'),
        );

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
            'store_ids' => $this->normalizedIds(),
        ]);
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && Gate::forUser($actor)->allows('manageShiftManagers', User::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
            'password' => ['bail', 'required', 'string', 'min:8', 'confirmed'],
            'store_ids' => ['present', 'array'],
            'store_ids.*' => ['integer', 'distinct'],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['active']),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function normalizedIds(): array
    {
        $ids = $this->input('create_store_ids', $this->input('store_ids', []));

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
