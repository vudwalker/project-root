<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class DraftVersionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedDraftVersion,
        public readonly int $currentDraftVersion,
    ) {
        parent::__construct(
            '別の画面または別の管理者によってシフトが更新されました。'
            .'この画面の変更は保存されていません。最新状態を確認するため再読み込みしてください。',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'draft_version_conflict',
            'message' => $this->getMessage(),
            'expected_draft_version' => $this->expectedDraftVersion,
            'current_draft_version' => $this->currentDraftVersion,
            'reload_required' => true,
        ], 409);
    }
}
