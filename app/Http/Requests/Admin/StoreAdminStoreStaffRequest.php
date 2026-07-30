<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAdminStoreStaffRequest extends FormRequest
{
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
            'staff_user_id' => ['required', 'integer'],
        ];
    }

    public function staffUserId(): int
    {
        return (int) $this->validated('staff_user_id');
    }
}
