<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MonthlyMembersVersionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(
            '月次表示スタッフが別の画面で更新されています。最新の状態を読み込んでから、もう一度操作してください。',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'monthly_members_version_conflict',
            'message' => $this->getMessage(),
            'expected_monthly_members_version' => $this->expectedVersion,
            'current_monthly_members_version' => $this->currentVersion,
            'reload_required' => true,
        ], 409);
    }
}
