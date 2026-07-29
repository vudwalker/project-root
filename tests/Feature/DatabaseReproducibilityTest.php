<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PublishedShift;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseReproducibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_business_tables_and_required_columns_are_created(): void
    {
        $tables = [
            'organizations',
            'stores',
            'users',
            'roles',
            'role_user',
            'store_user',
            'store_shift_manager',
            'store_shift_patterns',
            'shift_schedules',
            'shifts',
            'published_shifts',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} が作成されていません。");
        }

        $this->assertTrue(Schema::hasColumns('users', [
            'organization_id',
            'primary_store_id',
            'status',
            'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('shift_schedules', [
            'draft_version',
            'published_version',
            'shift_updated_at',
            'published_at',
        ]));
        $this->assertTrue(Schema::hasColumns('shifts', [
            'shift_schedule_id',
            'store_shift_pattern_id',
            'entry_uuid',
            'pattern_code',
            'work_minutes',
        ]));
    }

    public function test_seeders_are_idempotent_and_keep_conflicting_drafts_unpublished(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(4, Store::query()->count());
        $this->assertSame(11, User::query()->count());
        $this->assertSame(69, Shift::query()->count());
        $this->assertSame(31, PublishedShift::query()->count());

        $this->assertDatabaseHas('organizations', [
            'code' => 'sample-company',
            'name' => 'サンプル運営会社',
        ]);
        $this->assertDatabaseHas('stores', [
            'code' => 'okayama-tomida',
            'name' => '岡山富田',
        ]);

        $schedules = ShiftSchedule::query()
            ->with('store')
            ->get()
            ->keyBy(fn (ShiftSchedule $schedule): string => $schedule->store->code);

        $this->assertSame(1, $schedules->get('noda')->draft_version);
        $this->assertSame(1, $schedules->get('noda')->published_version);

        foreach (['daianji', 'saidaiji', 'okayama-tomida'] as $storeCode) {
            $schedule = $schedules->get($storeCode);

            $this->assertSame(1, $schedule->draft_version);
            $this->assertNull($schedule->published_version);
            $this->assertNull($schedule->published_at);
            $this->assertSame(0, $schedule->publishedShifts()->count());
        }

        $this->assertSame([
            ['work_date' => '2026-07-07', 'stores' => 2],
            ['work_date' => '2026-07-11', 'stores' => 2],
        ], $this->conflicts('shifts'));
        $this->assertSame([], $this->conflicts('published_shifts'));
    }

    public function test_schedule_with_draft_shifts_cannot_be_deleted(): void
    {
        $this->seed(DatabaseSeeder::class);

        $schedule = ShiftSchedule::query()
            ->whereHas('store', fn ($query) => $query->where('code', 'daianji'))
            ->firstOrFail();
        $shiftCount = Shift::query()->count();

        try {
            $schedule->delete();
            $this->fail('下書きシフトを持つ対象月を削除できてしまいました。');
        } catch (QueryException) {
            $this->assertDatabaseHas('shift_schedules', ['id' => $schedule->getKey()]);
            $this->assertSame($shiftCount, Shift::query()->count());
        }
    }

    public function test_schedule_with_published_shifts_cannot_be_deleted(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::query()->where('code', 'noda')->firstOrFail();
        $user = User::query()->where('email', 'staff@example.com')->firstOrFail();
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $store->getKey(),
            'target_month' => CarbonImmutable::create(2026, 8, 1),
            'draft_version' => 0,
        ]);
        PublishedShift::query()->create([
            'shift_schedule_id' => $schedule->getKey(),
            'user_id' => $user->getKey(),
            'work_date' => CarbonImmutable::create(2026, 8, 1),
            'sequence' => 1,
            'pattern_code' => 'C',
            'work_minutes' => 390,
            'published_at' => CarbonImmutable::create(2026, 7, 31, 12),
        ]);
        $publishedCount = PublishedShift::query()->count();

        try {
            $schedule->delete();
            $this->fail('公開済みシフトを持つ対象月を削除できてしまいました。');
        } catch (QueryException) {
            $this->assertDatabaseHas('shift_schedules', ['id' => $schedule->getKey()]);
            $this->assertSame($publishedCount, PublishedShift::query()->count());
        }
    }

    public function test_schedule_without_child_shifts_can_be_deleted(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::query()->where('code', 'noda')->firstOrFail();
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $store->getKey(),
            'target_month' => CarbonImmutable::create(2026, 8, 1),
            'draft_version' => 0,
        ]);

        $this->assertTrue((bool) $schedule->delete());
        $this->assertDatabaseMissing('shift_schedules', ['id' => $schedule->getKey()]);
    }

    /**
     * @return list<array{work_date: string, stores: int}>
     */
    private function conflicts(string $shiftTable): array
    {
        return DB::table("{$shiftTable} as shift_rows")
            ->join('shift_schedules', 'shift_schedules.id', '=', 'shift_rows.shift_schedule_id')
            ->join('users', 'users.id', '=', 'shift_rows.user_id')
            ->where('users.email', 'staff@example.com')
            ->selectRaw('shift_rows.work_date, COUNT(DISTINCT shift_schedules.store_id) AS stores')
            ->groupBy('shift_rows.user_id', 'shift_rows.work_date')
            ->havingRaw('COUNT(DISTINCT shift_schedules.store_id) > 1')
            ->orderBy('shift_rows.work_date')
            ->get()
            ->map(fn (object $row): array => [
                'work_date' => CarbonImmutable::parse($row->work_date)->toDateString(),
                'stores' => (int) $row->stores,
            ])
            ->all();
    }
}
