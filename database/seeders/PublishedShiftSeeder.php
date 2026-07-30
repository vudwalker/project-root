<?php

namespace Database\Seeders;

use App\Models\PublishedShift;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PublishedShiftSeeder extends Seeder
{
    private const TARGET_MONTH = '2026-07-01';

    private const PUBLISHED_AT = '2026-07-28 16:45:39';

    public function run(): void
    {
        DB::transaction(function (): void {
            $manager = User::query()->where('email', 'manager@example.com')->firstOrFail();
            $store = Store::query()->where('code', 'noda')->firstOrFail();
            $targetMonth = CarbonImmutable::parse(self::TARGET_MONTH);
            $patterns = StoreShiftPattern::query()
                ->where('store_id', $store->getKey())
                ->get()
                ->keyBy('code');
            $users = User::query()
                ->whereIn('email', array_keys($this->shifts()))
                ->get()
                ->keyBy('email');

            $schedule = ShiftSchedule::query()->updateOrCreate(
                [
                    'store_id' => $store->getKey(),
                    'target_month' => $targetMonth,
                ],
                [
                    'draft_version' => 1,
                    'published_version' => 1,
                    'published_draft_version' => 1,
                    'shift_updated_at' => self::PUBLISHED_AT,
                    'published_at' => self::PUBLISHED_AT,
                    'published_by_user_id' => $manager->getKey(),
                    'created_by' => $manager->getKey(),
                    'updated_by' => $manager->getKey(),
                ],
            );

            foreach ($this->shifts() as $email => $entries) {
                $user = $users->get($email);

                foreach ($entries as [$day, $code, $workMinutes]) {
                    $workDate = CarbonImmutable::create(2026, 7, $day);
                    $workDateString = $workDate->toDateString();
                    $pattern = $patterns->get($code);
                    $entryUuid = Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        "seeded-shift:noda:{$email}:{$workDateString}:1",
                    )->toString();

                    $shift = Shift::query()->updateOrCreate(
                        [
                            'shift_schedule_id' => $schedule->getKey(),
                            'user_id' => $user->getKey(),
                            'work_date' => $workDate,
                            'sequence' => 1,
                        ],
                        [
                            'store_shift_pattern_id' => $pattern->getKey(),
                            'entry_uuid' => $entryUuid,
                            'pattern_code' => $code,
                            'work_minutes' => $workMinutes,
                            'created_by' => $manager->getKey(),
                            'updated_by' => $manager->getKey(),
                        ],
                    );

                    PublishedShift::query()->updateOrCreate(
                        [
                            'shift_schedule_id' => $schedule->getKey(),
                            'user_id' => $user->getKey(),
                            'work_date' => $workDate,
                            'sequence' => $shift->sequence,
                        ],
                        [
                            'pattern_code' => $shift->pattern_code,
                            'work_minutes' => $shift->work_minutes,
                            'published_at' => self::PUBLISHED_AT,
                        ],
                    );
                }
            }
        });
    }

    /**
     * 配布済みの正常データだけを定義する。
     *
     * @return array<string, list<array{int, string, int}>>
     */
    private function shifts(): array
    {
        return [
            'staff@example.com' => [
                [3, 'D', 360],
                [8, 'C', 390],
                [12, 'C', 390],
                [20, 'C', 390],
                [26, 'C', 390],
            ],
            'miyake@example.com' => [
                [3, 'C', 390],
                [5, 'C', 390],
                [10, 'C', 390],
                [11, 'C', 390],
                [17, 'C', 390],
                [24, 'C', 390],
                [25, 'C', 390],
            ],
            'morinaga@example.com' => [
                [1, 'C', 390],
                [4, 'C', 390],
                [13, 'C', 390],
                [18, 'C', 390],
                [22, 'C', 390],
                [31, 'C', 390],
            ],
            'kawamoto@example.com' => [
                [7, 'C', 390],
                [14, 'C', 390],
                [15, 'C', 390],
                [21, 'C', 390],
                [28, 'C', 390],
                [29, 'C', 390],
            ],
            'shimizu@example.com' => [
                [2, 'C', 390],
                [8, 'C', 390],
                [9, 'C', 390],
                [16, 'C', 390],
                [23, 'C', 390],
                [27, 'C', 390],
                [30, 'C', 390],
            ],
        ];
    }
}
