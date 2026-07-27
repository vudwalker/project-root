<?php

namespace App\Services;

/**
 * 認証・データベース導入前のスタッフ用モックデータです。
 *
 * 配列の形は、将来のUser、Store、Shift、ShiftTypeへ
 * 置き換えやすいように分けています。
 */
class StaffShiftMockService
{
    /**
     * @return array<string, mixed>
     */
    public function loginUser(): array
    {
        return [
            'id' => 1,
            'name' => '近澤幸次',
            'role' => 'staff',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function stores(): array
    {
        return [
            'daianji' => [
                'id' => 1,
                'code' => 'daianji',
                'name' => '大安寺',
                // 参考画像（staff_tenpobetsu1.png）に近い位置へ配置しています。
                'staff' => [
                    [
                        'id' => 10,
                        'name' => '大月敦弘',
                        'shifts' => $this->shifts([
                            '2026-07-02' => 'C',
                            '2026-07-05' => 'C',
                            '2026-07-09' => 'C',
                            '2026-07-12' => 'C',
                            '2026-07-16' => 'C',
                            '2026-07-19' => 'C',
                            '2026-07-23' => 'C',
                            '2026-07-26' => 'C',
                            '2026-07-30' => 'C',
                        ]),
                    ],
                    [
                        'id' => 11,
                        'name' => '藤本保子',
                        'shifts' => $this->shifts([
                            '2026-07-04' => 'C',
                            '2026-07-10' => 'C',
                            '2026-07-17' => 'C',
                            '2026-07-18' => 'C',
                            '2026-07-24' => 'C',
                            '2026-07-25' => 'C',
                        ]),
                    ],
                    [
                        'id' => 12,
                        'name' => '本山宏明',
                        'shifts' => $this->shifts([
                            '2026-07-01' => 'C',
                            '2026-07-03' => 'C',
                            '2026-07-08' => 'C',
                            '2026-07-15' => 'C',
                            '2026-07-21' => 'C',
                            '2026-07-27' => 'C',
                            '2026-07-29' => 'C',
                            '2026-07-31' => 'C',
                        ]),
                    ],
                    [
                        'id' => 1,
                        'name' => '近澤幸次',
                        'shifts' => $this->shifts([
                            '2026-07-07' => 'C',
                            '2026-07-13' => 'C',
                            '2026-07-14' => 'C',
                            '2026-07-21' => 'C',
                            '2026-07-22' => 'C',
                            '2026-07-28' => 'C',
                        ]),
                    ],
                    [
                        'id' => 13,
                        'name' => '小合達也',
                        'shifts' => $this->shifts([
                            '2026-07-10' => 'C',
                        ]),
                    ],
                ],
            ],
            'noda' => [
                'id' => 2,
                'code' => 'noda',
                'name' => '野田',
                // 参考画像（staff_tenpobetsu2.png）に近い位置へ配置しています。
                'staff' => [
                    [
                        'id' => 20,
                        'name' => '三宅由幸',
                        'shifts' => $this->shifts([
                            '2026-07-03' => 'C',
                            '2026-07-05' => 'C',
                            '2026-07-10' => 'C',
                            '2026-07-11' => 'C',
                            '2026-07-17' => 'C',
                            '2026-07-24' => 'C',
                            '2026-07-25' => 'C',
                        ]),
                    ],
                    [
                        'id' => 21,
                        'name' => '森永俊巳',
                        'shifts' => $this->shifts([
                            '2026-07-01' => 'C',
                            '2026-07-04' => 'C',
                            '2026-07-13' => 'C',
                            '2026-07-18' => 'C',
                            '2026-07-22' => 'C',
                            '2026-07-31' => 'C',
                        ]),
                    ],
                    [
                        'id' => 22,
                        'name' => '河本健二',
                        'shifts' => $this->shifts([
                            '2026-07-07' => 'C',
                            '2026-07-14' => 'C',
                            '2026-07-15' => 'C',
                            '2026-07-21' => 'C',
                            '2026-07-28' => 'C',
                            '2026-07-29' => 'C',
                        ]),
                    ],
                    [
                        'id' => 23,
                        'name' => '清水輝夫',
                        'shifts' => $this->shifts([
                            '2026-07-02' => 'C',
                            '2026-07-08' => 'C',
                            '2026-07-09' => 'C',
                            '2026-07-16' => 'C',
                            '2026-07-23' => 'C',
                            '2026-07-27' => 'C',
                            '2026-07-30' => 'C',
                        ]),
                    ],
                    [
                        'id' => 1,
                        'name' => '近澤幸次',
                        'shifts' => $this->shifts([
                            '2026-07-08' => 'C',
                            '2026-07-12' => 'C',
                            '2026-07-20' => 'C',
                            '2026-07-26' => 'C',
                        ]),
                    ],
                ],
            ],
        ];
    }

    /**
     * 個人カレンダー用に、1日複数件を保持できる構造を返します。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function personalShifts(): array
    {
        // 参考画像（staff_top.png）と同じ日付・店舗の組み合わせです。
        return [
            '2026-07-07' => [$this->personalShift(1, 'daianji', '大安寺', 3, 'C', 'C勤務')],
            '2026-07-08' => [$this->personalShift(2, 'noda', '野田', 3, 'C', 'C勤務')],
            '2026-07-12' => [$this->personalShift(2, 'noda', '野田', 3, 'C', 'C勤務')],
            '2026-07-13' => [$this->personalShift(1, 'daianji', '大安寺', 3, 'C', 'C勤務')],
            '2026-07-14' => [$this->personalShift(1, 'daianji', '大安寺', 3, 'C', 'C勤務')],
            '2026-07-20' => [$this->personalShift(2, 'noda', '野田', 3, 'C', 'C勤務')],
            '2026-07-21' => [$this->personalShift(1, 'daianji', '大安寺', 3, 'C', 'C勤務')],
            '2026-07-22' => [$this->personalShift(1, 'daianji', '大安寺', 3, 'C', 'C勤務')],
            '2026-07-26' => [$this->personalShift(2, 'noda', '野田', 3, 'C', 'C勤務')],
            '2026-07-28' => [$this->personalShift(1, 'daianji', '大安寺', 3, 'C', 'C勤務')],
        ];
    }

    /**
     * @param array<string, string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function shifts(array $codes): array
    {
        $shifts = [];

        foreach ($codes as $date => $code) {
            $shifts[$date] = [
                'shift_type' => [
                    'id' => ord($code),
                    'code' => $code,
                    'name' => $code.'勤務',
                ],
            ];
        }

        return $shifts;
    }

    /**
     * @return array<string, mixed>
     */
    private function personalShift(
        int $storeId,
        string $storeCode,
        string $storeName,
        int $shiftTypeId,
        string $code,
        string $name,
    ): array {
        return [
            'store_id' => $storeId,
            'store_code' => $storeCode,
            'store_name' => $storeName,
            'shift_type' => [
                'id' => $shiftTypeId,
                'code' => $code,
                'name' => $name,
            ],
        ];
    }
}
