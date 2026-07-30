<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAdminStorePatternsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $patterns = collect($this->input('patterns', []))
            ->map(function (mixed $pattern): mixed {
                if (! is_array($pattern)) {
                    return $pattern;
                }

                foreach (['code', 'start_time', 'end_time'] as $field) {
                    if (isset($pattern[$field]) && is_string($pattern[$field])) {
                        $pattern[$field] = trim($pattern[$field]);
                    }
                }

                foreach (['start_time', 'end_time'] as $field) {
                    if (($pattern[$field] ?? null) === '') {
                        $pattern[$field] = null;
                    }
                }

                return $pattern;
            })
            ->values()
            ->all();

        $this->merge(['patterns' => $patterns]);
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
            'patterns' => ['present', 'array', 'max:50'],
            'patterns.*.id' => ['nullable', 'integer', 'distinct'],
            'patterns.*.code' => ['nullable', 'string', 'max:20'],
            'patterns.*.start_time' => ['nullable', 'date_format:H:i'],
            'patterns.*.end_time' => ['nullable', 'date_format:H:i'],
            'patterns.*.ends_next_day' => ['required', 'boolean'],
            'patterns.*.display_order' => ['nullable', 'integer', 'min:0'],
            'patterns.*.is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function patterns(): array
    {
        return array_values($this->validated('patterns', []));
    }
}
