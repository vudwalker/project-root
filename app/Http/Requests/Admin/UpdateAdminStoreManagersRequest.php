<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAdminStoreManagersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->canAccessAdmin()
            && $user->hasRole('system_admin');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'manager_user_ids' => ['sometimes', 'array'],
            'manager_user_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function managerUserIds(): array
    {
        return collect($this->validated('manager_user_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
