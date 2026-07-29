<?php

namespace App\Rules;

use App\Services\TargetMonthService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SelectableTargetMonth implements ValidationRule
{
    public function __construct(
        private readonly TargetMonthService $targetMonthService,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            ! is_string($value)
            || $this->targetMonthService->parseSelectableMonth($value) === null
        ) {
            $fail('対象月が選択可能な期間外です。');
        }
    }
}
