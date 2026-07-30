<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreShiftPattern;
use Illuminate\Database\Seeder;

class ShiftPatternSeeder extends Seeder
{
    public function run(): void
    {
        $hoursByStore = [
            'daianji' => ['A' => '8.00', 'B' => '7.00', 'C' => '7.50', 'D' => '4.00', 'E' => '5.00', '研' => '0.00', '有' => '0.00'],
            'noda' => ['A' => '8.00', 'B' => '7.00', 'C' => '6.50', 'D' => '4.00', 'E' => '5.00', '研' => '0.00', '有' => '0.00'],
            'saidaiji' => ['A' => '8.00', 'B' => '7.00', 'C' => '7.50', 'D' => '4.00', 'E' => '5.00', '研' => '0.00', '有' => '0.00'],
            'okayama-tomida' => ['A' => '8.00', 'B' => '7.00', 'C' => '7.50', 'D' => '4.00', 'E' => '5.00', '研' => '0.00', '有' => '0.00'],
        ];
        $timeWindowsByStore = [
            'daianji' => $this->fullTimeCWindows(),
            'noda' => $this->fullTimeCWindows(),
            'saidaiji' => $this->splitTimeWindows(),
            'okayama-tomida' => $this->fullTimeCWindows(),
        ];
        $displayOrder = array_flip(['A', 'B', 'C', 'D', 'E', '研', '有']);

        Store::query()
            ->whereIn('code', array_keys($hoursByStore))
            ->each(function (Store $store) use (
                $hoursByStore,
                $displayOrder,
                $timeWindowsByStore,
            ): void {
                foreach ($hoursByStore[$store->code] as $code => $hours) {
                    $timeWindow = $timeWindowsByStore[$store->code][$code] ?? [];

                    StoreShiftPattern::query()->withTrashed()->updateOrCreate(
                        [
                            'store_id' => $store->getKey(),
                            'code' => $code,
                        ],
                        [
                            'work_hours' => $hours,
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
