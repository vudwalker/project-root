<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreShiftPattern;
use Illuminate\Database\Seeder;

class ShiftPatternSeeder extends Seeder
{
    public function run(): void
    {
        $minutesByStore = [
            'daianji' => ['A' => 480, 'B' => 420, 'C' => 450, 'D' => 240, 'E' => 300, '研' => 0, '有' => 0],
            'noda' => ['A' => 480, 'B' => 420, 'C' => 390, 'D' => 240, 'E' => 300, '研' => 0, '有' => 0],
            'saidaiji' => ['A' => 480, 'B' => 420, 'C' => 450, 'D' => 240, 'E' => 300, '研' => 0, '有' => 0],
            'okayama-tomida' => ['A' => 480, 'B' => 420, 'C' => 450, 'D' => 240, 'E' => 300, '研' => 0, '有' => 0],
        ];
        $timeWindowsByStore = [
            'daianji' => $this->fullTimeCWindows(),
            'noda' => $this->fullTimeCWindows(),
            'saidaiji' => $this->splitTimeWindows(),
            'okayama-tomida' => $this->fullTimeCWindows(),
        ];
        $displayOrder = array_flip(['A', 'B', 'C', 'D', 'E', '研', '有']);

        Store::query()
            ->whereIn('code', array_keys($minutesByStore))
            ->each(function (Store $store) use (
                $minutesByStore,
                $displayOrder,
                $timeWindowsByStore,
            ): void {
                foreach ($minutesByStore[$store->code] as $code => $minutes) {
                    $timeWindow = $timeWindowsByStore[$store->code][$code] ?? [];

                    StoreShiftPattern::query()->withTrashed()->updateOrCreate(
                        [
                            'store_id' => $store->getKey(),
                            'code' => $code,
                        ],
                        [
                            'work_minutes' => $minutes,
                            'start_time' => $timeWindow['start_time'] ?? null,
                            'start_day_offset' => $timeWindow['start_day_offset'] ?? null,
                            'end_time' => $timeWindow['end_time'] ?? null,
                            'end_day_offset' => $timeWindow['end_day_offset'] ?? null,
                            'display_order' => $displayOrder[$code] + 1,
                            'is_active' => in_array($code, ['B', 'C', 'D', '研'], true),
                            'deleted_at' => null,
                        ],
                    );
                }
            });
    }

    /**
     * 大安寺・野田・岡山富田ではCが夜間の全時間帯を担当します。
     *
     * @return array<string, array<string, int|string>>
     */
    private function fullTimeCWindows(): array
    {
        $windows = $this->splitTimeWindows();
        $windows['C'] = [
            'start_time' => '20:00:00',
            'start_day_offset' => 0,
            'end_time' => '08:00:00',
            'end_day_offset' => 1,
        ];

        return $windows;
    }

    /**
     * 西大寺ではBとCで夜間を前半・後半に分け、Dが全時間帯を担当します。
     *
     * @return array<string, array<string, int|string>>
     */
    private function splitTimeWindows(): array
    {
        return [
            'B' => [
                'start_time' => '20:00:00',
                'start_day_offset' => 0,
                'end_time' => '02:00:00',
                'end_day_offset' => 1,
            ],
            'C' => [
                'start_time' => '02:00:00',
                'start_day_offset' => 1,
                'end_time' => '08:00:00',
                'end_day_offset' => 1,
            ],
            'D' => [
                'start_time' => '20:00:00',
                'start_day_offset' => 0,
                'end_time' => '08:00:00',
                'end_day_offset' => 1,
            ],
        ];
    }
}
