<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminStaffIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->canAccessAdmin();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                'string',
                Rule::in(['active', 'on_leave', 'retired']),
            ],
            'store_id' => ['nullable', 'integer'],
            'role' => [
                'nullable',
                'string',
                Rule::in(['staff', 'shift_manager', 'system_admin']),
            ],
        ];
    }

    /**
     * @return array{query: string|null, status: string|null, store_id: int|null, role: string|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'query' => $validated['q'] ?? null,
            'status' => $validated['status'] ?? null,
            'store_id' => isset($validated['store_id'])
                ? (int) $validated['store_id']
                : null,
            'role' => $validated['role'] ?? null,
        ];
    }
}
