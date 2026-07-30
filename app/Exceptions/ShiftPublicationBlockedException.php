<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ShiftPublicationBlockedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $warningResult
     */
    public function __construct(
        public readonly array $warningResult,
    ) {
        parent::__construct('シフトに修正が必要なため配布できません。');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'shift_publication_blocked',
            'message' => $this->getMessage(),
            'warning_result' => $this->warningResult,
        ], 422);
    }
}
