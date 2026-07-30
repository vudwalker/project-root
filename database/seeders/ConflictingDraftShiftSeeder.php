<?php

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ConflictingDraftShiftSeeder extends Seeder
{
    private const TARGET_MONTH = '2026-07-01';

    private const SHIFT_UPDATED_AT = '2026-07-28 16:45:39';

    public function run(): void
    {
        DB::transaction(function (): void {
            $manager = User::query()->where('email', 'manager@example.com')->firstOrFail();
            $targetMonth = CarbonImmutable::parse(self::TARGET_MONTH);
            $stores = Store::query()
                ->whereIn('code', array_keys($this->shifts()))
                ->get()
                ->keyBy('code');
            $users = User::query()
                ->whereIn('email', $this->staffEmails())
                ->get()
                ->keyBy('email');

            foreach ($this->shifts() as $storeCode => $staffShifts) {
                $store = $stores->get($storeCode);
                $patterns = StoreShiftPattern::query()
                    ->where('store_id', $store->getKey())
                    ->get()
                    ->keyBy('code');

                // 重複勤務を含むため、公開情報は設定しない。
                $schedule = ShiftSchedule::query()->updateOrCreate(
                    [
                        'store_id' => $store->getKey(),
                        'target_month' => $targetMonth,
                    ],
                    [
                        'draft_version' => 1,
                        'published_version' => null,
                        'published_draft_version' => null,
                        'shift_updated_at' => self::SHIFT_UPDATED_AT,
                        'published_at' => null,
                        'published_by_user_id' => null,
                        'created_by' => $manager->getKey(),
                        'updated_by' => $manager->getKey(),
                    ],
                );

                foreach ($staffShifts as $email => $entries) {
                    $user = $users->get($email);

                    foreach ($entries as [$day, $code, $workMinutes]) {
                        $workDate = CarbonImmutable::create(2026, 7, $day);
                        $workDateString = $workDate->toDateString();
                        $entryUuid = Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            "seeded-shift:{$storeCode}:{$email}:{$workDateString}:1",
                        )->toString();

                        Shift::query()->updateOrCreate(
                            [
                                'shift_schedule_id' => $schedule->getKey(),
                                'user_id' => $user->getKey(),
                                'work_date' => $workDate,
                                'sequence' => 1,
                            ],
                            [
                                'store_shift_pattern_id' => $patterns->get($code)->getKey(),
                                'entry_uuid' => $entryUuid,
                                'pattern_code' => $code,
                                'work_minutes' => $workMinutes,
                                'created_by' => $manager->getKey(),
                                'updated_by' => $manager->getKey(),
                            ],
                        );
                    }
                }
            }
        });
    }

    /**
     * 参考画像用の重複勤務を含む下書きデータ。
     *
     * @return array<string, array<string, list<array{int, string, int}>>>
     */
    private function shifts(): array
    {
        return [
            'daianji' => [
                'staff@example.com' => [
                    [2, 'C', 450],
                    [7, 'C', 450],
                    [13, 'C', 450],
                    [14, 'C', 450],
                    [21, 'C', 450],
                    [22, 'C', 450],
                    [28, 'C', 450],
                ],
                'otsuki@example.com' => [
                    [2, 'C', 450],
                    [5, 'C', 450],
                    [9, 'C', 450],
                    [12, 'C', 450],
                    [16, 'C', 450],
                    [19, 'C', 450],
                    [23, 'C', 450],
                    [26, 'C', 450],
                    [30, 'C', 450],
                ],
                'fujimoto@example.com' => [
                    [4, 'C', 450],
                    [8, 'C', 450],
                    [10, 'C', 450],
                    [17, 'C', 450],
                    [18, 'C', 450],
                    [24, 'C', 450],
                    [25, 'C', 450],
                ],
                'motoyama@example.com' => [
                    [1, 'C', 450],
                    [3, 'C', 450],
                    [8, 'C', 450],
                    [11, 'C', 450],
                    [15, 'C', 450],
                    [21, 'C', 450],
                    [27, 'C', 450],
                    [29, 'C', 450],
                    [31, 'C', 450],
                ],
                'oai@example.com' => [
                    [10, 'C', 450],
                    [14, 'C', 450],
                ],
            ],
            'saidaiji' => [
                'staff@example.com' => [
                    [7, 'A', 450],
                    [11, 'C', 450],
                ],
            ],
            'okayama-tomida' => [
                'staff@example.com' => [
                    [11, 'B', 420],
                    [31, 'C', 450],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function staffEmails(): array
    {
        return collect($this->shifts())
            ->flatMap(fn (array $staffShifts): array => array_keys($staffShifts))
            ->unique()
            ->values()
            ->all();
    }
}
