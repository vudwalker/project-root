<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * 認証・データベース導入前のシフト区分マスターです。
 *
 * スタッフ画面と管理画面が同じID・コード・表示名を参照できるよう、
 * シフト区分は画面別のモックデータへ重複して定義しません。
 */
class ShiftTypeMockService
{
    /**
     * 将来のshift_typesテーブルへ移行しやすい形で保持します。
     *
     * @var array<string, array{id: int, code: string, name: string}>
     */
    private const SHIFT_TYPES = [
        'A' => [
            'id' => 1,
            'code' => 'A',
            'name' => 'A勤務',
        ],
        'B' => [
            'id' => 2,
            'code' => 'B',
            'name' => 'B勤務',
        ],
        'C' => [
            'id' => 3,
            'code' => 'C',
            'name' => 'C勤務',
        ],
        'D' => [
            'id' => 4,
            'code' => 'D',
            'name' => 'D勤務',
        ],
        'E' => [
            'id' => 5,
            'code' => 'E',
            'name' => 'E勤務',
        ],
    ];

    /**
     * @return array<string, array{id: int, code: string, name: string}>
     */
    public function all(): array
    {
        return self::SHIFT_TYPES;
    }

    /**
     * @return array{id: int, code: string, name: string}
     */
    public function getByCode(string $code): array
    {
        if (! isset(self::SHIFT_TYPES[$code])) {
            throw new InvalidArgumentException("未定義のシフト区分です: {$code}");
        }

        return self::SHIFT_TYPES[$code];
    }
}
