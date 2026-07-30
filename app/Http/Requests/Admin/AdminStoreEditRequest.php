<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AdminStoreEditRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('staff_query'))) {
            $this->merge([
                'staff_query' => trim((string) $this->query('staff_query')),
            ]);
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
        return [
            'staff_add' => ['nullable', 'boolean'],
            'staff_query' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function staffAddOpen(): bool
    {
        return $this->boolean('staff_add');
    }

    public function staffQuery(): string
    {
        return (string) ($this->validated('staff_query') ?? '');
    }
}
