<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateShiftManagersRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $managerIds = collect($this->input('manager_user_ids', []))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $storeIds = collect($this->input('store_ids', []))
            ->filter(fn (mixed $ids, mixed $userId): bool => is_numeric($userId))
            ->map(function (mixed $ids): array {
                if (! is_array($ids)) {
                    return [];
                }

                return collect($ids)
                    ->filter(fn (mixed $id): bool => is_numeric($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            })
            ->all();

        $this->merge([
            'manager_user_ids' => $managerIds,
            'store_ids' => $storeIds,
        ]);
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
            'manager_user_ids' => ['present', 'array'],
            'manager_user_ids.*' => ['integer', 'distinct'],
            'store_ids' => ['present', 'array'],
            'store_ids.*' => ['array'],
            'store_ids.*.*' => ['integer'],
        ];
    }
}
