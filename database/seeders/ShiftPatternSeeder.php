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
        $displayOrder = array_flip(['A', 'B', 'C', 'D', 'E', '研', '有']);

        Store::query()
            ->whereIn('code', array_keys($minutesByStore))
            ->each(function (Store $store) use ($minutesByStore, $displayOrder): void {
                foreach ($minutesByStore[$store->code] as $code => $minutes) {
                    StoreShiftPattern::query()->withTrashed()->updateOrCreate(
                        [
                            'store_id' => $store->getKey(),
                            'code' => $code,
                        ],
                        [
                            'work_minutes' => $minutes,
                            'display_order' => $displayOrder[$code] + 1,
                            'is_active' => in_array($code, ['C', 'D', '研'], true),
                            'deleted_at' => null,
                        ],
                    );
                }
            });
    }
}
