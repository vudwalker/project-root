<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminStoreIndexRequest extends FormRequest
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
            'status' => [
                'sometimes',
                'string',
                Rule::in(['all', 'active', 'inactive']),
            ],
        ];
    }

    public function statusFilter(User $actor): string
    {
        if (! $actor->hasRole('system_admin')) {
            return 'active';
        }

        return (string) $this->validated('status', 'all');
    }
}
