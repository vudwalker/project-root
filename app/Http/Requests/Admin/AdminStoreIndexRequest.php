<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AdminStoreIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['area', 'q'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
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
            'manager_id' => ['nullable', 'integer'],
            'area' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{manager_id: int|null, area: string|null, query: string|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'manager_id' => isset($validated['manager_id'])
                ? (int) $validated['manager_id']
                : null,
            'area' => $validated['area'] ?? null,
            'query' => $validated['q'] ?? null,
        ];
    }
}
