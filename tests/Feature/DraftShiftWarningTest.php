<?php

namespace Tests\Feature;

use App\Enums\DraftShiftWarningCode;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\User;
use App\Services\Admin\DraftShiftWarningService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DraftShiftWarningTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private User $staffA;

    private User $staffB;

    private User $staffC;

    private DraftShiftWarningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);

        $this->store = Store::query()->where('code', 'daianji')->firstOrFail();
        $this->otherStore = Store::query()->where('code', 'saidaiji')->firstOrFail();
        $this->staffA = User::query()->where('email', 'otsuki@example.com')->firstOrFail();
        $this->staffB = User::query()->where('email', 'fujimoto@example.com')->firstOrFail();
        $this->staffC = User::query()->where('email', 'motoyama@example.com')->firstOrFail();
        foreach ([$this->staffA, $this->staffB, $this->staffC] as $index => $staff) {
            $staff->stores()->syncWithoutDetaching([
                $this->otherStore->getKey() => [
                    'display_order' => $index + 10,
                    'is_active' => true,
                    'started_on' => null,
                    'ended_on' => null,
                ],
            ]);
        }
        $this->service = app(DraftShiftWarningService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_seeder_defines_store_specific_time_windows_and_options(): void
    {
        $this->assertDatabaseHas('stores', [
            'id' => $this->store->getKey(),
            'staffing_check_mode' => 'pattern_combinations',
        ]);
        foreach (['daianji', 'noda', 'okayama-tomida'] as $storeCode) {
            $store = Store::query()->where('code', $storeCode)->firstOrFail();

            $this->assertDatabaseHas('store_shift_patterns', [
                'store_id' => $store->getKey(),
                'code' => 'C',
                'start_time' => '20:00:00',
                'start_day_offset' => 0,
                'end_time' => '08:00:00',
                'end_day_offset' => 1,
            ]);
            $this->assertSame(
                ['full-c' => ['C' => 1]],
                $this->requirementOptions($store),
            );
        }

        $this->assertDatabaseHas('store_shift_patterns', [
            'store_id' => $this->otherStore->getKey(),
            'code' => 'C',
            'start_time' => '02:00:00',
            'start_day_offset' => 1,
            'end_time' => '08:00:00',
            'end_day_offset' => 1,
        ]);
        $this->assertSame(
            [
                'split-b-c' => ['B' => 1, 'C' => 1],
                'full-d' => ['D' => 1],
            ],
            $this->requirementOptions($this->otherStore),
        );
    }

    public function test_full_time_c_for_every_day_is_publishable_at_c_only_store(): void
    {
        $this->fillMonthWith('2026-08', function (CarbonImmutable $date): void {
            $this->addShift($this->store, $this->staffA, $date, 'C');
        });

        $result = $this->evaluate();

        $this->assertTrue($result['can_publish']);
        $this->assertSame(0, $result['blocking_warning_count']);
    }

    public function test_d_or_separate_b_and_c_for_every_day_are_publishable_at_saidaiji(): void
    {
        $this->fillMonthWith('2026-08', function (CarbonImmutable $date): void {
            $this->addShift($this->otherStore, $this->staffA, $date, 'D');
        });

        $fullResult = $this->evaluateStore($this->otherStore);

        $this->assertTrue($fullResult['can_publish']);
        $this->assertSame(0, $fullResult['blocking_warning_count']);

        Shift::query()
            ->whereHas('schedule', fn ($query) => $query
                ->where('store_id', $this->otherStore->getKey())
                ->whereDate('target_month', '2026-08-01'))
            ->delete();

        $this->fillMonthWith('2026-08', function (CarbonImmutable $date): void {
            $this->addShift($this->otherStore, $this->staffA, $date, 'B');
            $this->addShift($this->otherStore, $this->staffB, $date, 'C');
        });

        $splitResult = $this->evaluateStore($this->otherStore);

        $this->assertTrue($splitResult['can_publish']);
        $this->assertSame([], $splitResult['warnings']);
    }

    public function test_shortage_takes_precedence_when_a_required_time_range_is_uncovered(): void
    {
        $date = CarbonImmutable::parse('2026-08-10');
        $this->addShift($this->store, $this->staffA, $date, 'B');
        $this->addShift($this->store, $this->staffB, $date, 'B');

        $warning = $this->warningAt(
            $this->evaluate(),
            $date,
            DraftShiftWarningCode::StaffingShortage,
        );

        $this->assertSame(2, $warning['current_b_count']);
        $this->assertSame(0, $warning['current_c_count']);
        $this->assertSame(0, $warning['current_d_count']);
        $this->assertSame(
            ['20:00から翌08:00'],
            $warning['missing_time_ranges'],
        );
    }

    public function test_excess_reports_covered_time_range_and_count(): void
    {
        $date = CarbonImmutable::parse('2026-08-11');
        $this->addShift($this->store, $this->staffA, $date, 'B');
        $this->addShift($this->store, $this->staffB, $date, 'C');

        $warning = $this->warningAt(
            $this->evaluate(),
            $date,
            DraftShiftWarningCode::StaffingExcess,
        );

        $this->assertSame(['20:00から翌02:00'], $warning['excess_time_ranges']);
        $this->assertSame(1, $warning['excess_count']);
        $this->assertFalse($this->evaluate()['can_publish']);
    }

    public function test_same_staff_b_and_c_is_staffing_valid_but_duplicate_blocks_publish(): void
    {
        $date = CarbonImmutable::parse('2026-08-12');
        $this->addShift($this->otherStore, $this->staffA, $date, 'B');
        $this->addShift($this->otherStore, $this->staffA, $date, 'C');
        $result = $this->evaluateStore($this->otherStore);

        $duplicate = $this->warningAt(
            $result,
            $date,
            DraftShiftWarningCode::SameStoreDuplicate,
        );
        $staffingWarnings = collect($result['warnings'])
            ->where('work_date', $date->toDateString())
            ->whereIn('warning_code', [
                DraftShiftWarningCode::StaffingShortage->value,
                DraftShiftWarningCode::StaffingExcess->value,
            ]);

        $this->assertSame($this->staffA->getKey(), $duplicate['user_id']);
        $this->assertCount(2, $duplicate['shift_ids']);
        $this->assertTrue($staffingWarnings->isEmpty());
        $this->assertFalse($result['can_publish']);
    }

    public function test_cross_store_same_day_is_detected_even_when_patterns_differ(): void
    {
        $date = CarbonImmutable::parse('2026-08-13');
        $this->addShift($this->store, $this->staffA, $date, 'C');
        $this->addShift($this->otherStore, $this->staffA, $date, 'D');

        $warning = $this->warningAt(
            $this->evaluate(),
            $date,
            DraftShiftWarningCode::CrossStoreDuplicate,
        );

        $this->assertEqualsCanonicalizing(
            [$this->store->getKey(), $this->otherStore->getKey()],
            $warning['store_ids'],
        );
        $this->assertEqualsCanonicalizing(
            ['大安寺', '西大寺'],
            $warning['store_names'],
        );
        $this->assertCount(2, $warning['shift_patterns']);
    }

    public function test_staff_role_controls_staffing_count_even_with_admin_roles(): void
    {
        $date = CarbonImmutable::parse('2026-08-14');
        $staffAdmin = $this->createUser(
            'staff-system-admin@example.com',
            ['staff', 'system_admin'],
            true,
        );
        $adminOnly = $this->createUser(
            'system-admin-only@example.com',
            ['system_admin'],
            true,
        );
        $this->addShift($this->store, $staffAdmin, $date, 'C');

        $validDayWarnings = collect($this->evaluate()['warnings'])
            ->where('work_date', $date->toDateString())
            ->whereIn('warning_code', [
                DraftShiftWarningCode::StaffingShortage->value,
                DraftShiftWarningCode::StaffingExcess->value,
            ]);
        $this->assertTrue($validDayWarnings->isEmpty());

        $otherDate = $date->addDay();
        $this->addShift($this->store, $adminOnly, $otherDate, 'C');
        $warning = $this->warningAt(
            $this->evaluate(),
            $otherDate,
            DraftShiftWarningCode::StaffingShortage,
        );

        $this->assertSame(0, $warning['current_c_count']);
    }

    public function test_same_staff_same_pattern_is_counted_once_but_duplicate_is_reported(): void
    {
        $date = CarbonImmutable::parse('2026-08-16');
        $this->addShift($this->store, $this->staffA, $date, 'B');
        $this->addShift($this->store, $this->staffA, $date, 'B');
        $result = $this->evaluate();

        $shortage = $this->warningAt(
            $result,
            $date,
            DraftShiftWarningCode::StaffingShortage,
        );
        $duplicate = $this->warningAt(
            $result,
            $date,
            DraftShiftWarningCode::SameStoreDuplicate,
        );

        $this->assertSame(1, $shortage['current_b_count']);
        $this->assertCount(2, $duplicate['shift_ids']);
    }

    public function test_missing_requirement_is_not_treated_as_zero_staffing(): void
    {
        StoreStaffingRequirement::query()
            ->where('store_id', $this->store->getKey())
            ->delete();
        $date = CarbonImmutable::parse('2026-08-17');

        $warning = $this->warningAt(
            $this->evaluate(),
            $date,
            DraftShiftWarningCode::StaffingRequirementMissing,
        );

        $this->assertTrue($warning['requires_configuration']);
        $this->assertStringContainsString('設定されていません', $warning['message']);
        $this->assertFalse($this->evaluate()['can_publish']);
    }

    public function test_disabled_mode_is_an_explicit_opt_out(): void
    {
        $this->store->forceFill(['staffing_check_mode' => 'disabled'])->save();

        $result = $this->evaluate();

        $this->assertTrue($result['can_publish']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_both_admin_screens_use_same_warning_and_staff_screen_stays_read_only(): void
    {
        $manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();
        $date = CarbonImmutable::parse('2026-08-18');
        $this->addShift($this->store, $this->staffA, $date, 'C');
        $this->addShift($this->otherStore, $this->staffA, $date, 'D');

        $storeResponse = $this->actingAs($manager)
            ->get('/admin/shifts/stores/daianji?month=2026-08');
        $staffResponse = $this->actingAs($manager)
            ->get(
                "/admin/shifts/staff/{$this->staffA->getKey()}"
                .'?month=2026-08&store=daianji',
            );

        $storeResponse
            ->assertOk()
            ->assertSee(DraftShiftWarningCode::CrossStoreDuplicate->value, false)
            ->assertSee('data-warning-date="2026-08-18"', false)
            ->assertSee('data-admin-warning-panel', false);
        $staffResponse
            ->assertOk()
            ->assertSee(DraftShiftWarningCode::CrossStoreDuplicate->value, false)
            ->assertSee('data-warning-date="2026-08-18"', false)
            ->assertSee('data-read-only="true"', false)
            ->assertDontSee('data-shift-editor', false)
            ->assertDontSee('admin-shift-editor.js', false);
    }

    public function test_successful_mutation_returns_matching_warning_version_without_published_changes(): void
    {
        $manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();
        $pattern = $this->pattern($this->store, 'B');
        $publishedBefore = DB::table('published_shifts')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $created = $this->actingAs($manager)
            ->postJson('/admin/shifts/stores/daianji/shifts', [
                'target_month' => '2026-08',
                'user_id' => $this->staffA->getKey(),
                'work_date' => '2026-08-19',
                'shift_pattern_id' => $pattern->getKey(),
                'entry_uuid' => (string) Str::uuid(),
                'expected_draft_version' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('draft_version', 1)
            ->assertJsonPath('warning_result.checked_draft_version', 1)
            ->assertJsonPath('warning_result.can_publish', false)
            ->json();

        $this->actingAs($manager)
            ->patchJson(
                '/admin/shifts/stores/daianji/shifts/'.$created['shift_id'],
                [
                    'target_month' => '2026-08',
                    'shift_pattern_id' => $this->pattern($this->store, 'D')->getKey(),
                    'expected_draft_version' => 1,
                ],
            )
            ->assertOk()
            ->assertJsonPath('draft_version', 2)
            ->assertJsonPath('warning_result.checked_draft_version', 2);

        $response = $this->actingAs($manager)
            ->deleteJson(
                '/admin/shifts/stores/daianji/shifts/'.$created['shift_id'],
                [
                    'target_month' => '2026-08',
                    'expected_draft_version' => 2,
                ],
            )
            ->assertOk()
            ->assertJsonPath('draft_version', 3)
            ->assertJsonPath('warning_result.checked_draft_version', 3);

        $this->assertSame(
            $publishedBefore,
            DB::table('published_shifts')
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );
        $response->assertJsonPath(
            'warning_result.warnings.0.warning_code',
            DraftShiftWarningCode::StaffingShortage->value,
        );
    }

    public function test_editor_applies_only_warning_results_for_current_draft_version(): void
    {
        $script = file_get_contents(public_path('js/admin-shift-editor.js'));

        $this->assertStringContainsString('applyWarningResult(payload.warning_result)', $script);
        $this->assertStringContainsString(
            'checkedVersion !== draftVersion',
            $script,
        );
        $this->assertStringContainsString('if (!result || queue.isStopped())', $script);
        $this->assertStringContainsString('warningList.replaceChildren()', $script);
    }

    /**
     * @param  callable(CarbonImmutable): void  $callback
     */
    private function fillMonthWith(string $month, callable $callback): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $end = $start->endOfMonth();

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $callback($date);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(): array
    {
        return $this->evaluateStore($this->store);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateStore(Store $store): array
    {
        return $this->service->evaluate(
            $store->fresh(),
            CarbonImmutable::parse('2026-08-01'),
        );
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function requirementOptions(Store $store): array
    {
        $requirement = StoreStaffingRequirement::query()
            ->where('store_id', $store->getKey())
            ->with('options.patterns.shiftPattern')
            ->firstOrFail();

        return $requirement->options
            ->mapWithKeys(fn ($option): array => [
                $option->code => $option->patterns
                    ->mapWithKeys(fn ($pattern): array => [
                        $pattern->shiftPattern->code => $pattern->required_count,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function warningAt(
        array $result,
        CarbonImmutable $date,
        DraftShiftWarningCode $code,
    ): array {
        $warning = collect($result['warnings'])->first(
            fn (array $warning): bool => $warning['work_date'] === $date->toDateString()
                && $warning['warning_code'] === $code->value,
        );

        $this->assertIsArray(
            $warning,
            "警告 {$code->value} が {$date->toDateString()} にありません。",
        );

        return $warning;
    }

    private function addShift(
        Store $store,
        User $user,
        CarbonImmutable $date,
        string $code,
    ): Shift {
        $schedule = ShiftSchedule::query()
            ->where('store_id', $store->getKey())
            ->whereDate('target_month', $date->startOfMonth()->toDateString())
            ->first();

        if (! $schedule instanceof ShiftSchedule) {
            $schedule = ShiftSchedule::query()->create([
                'store_id' => $store->getKey(),
                'target_month' => $date->startOfMonth(),
                'draft_version' => 1,
                'created_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ]);
        }
        $sequence = (int) Shift::query()
            ->where('shift_schedule_id', $schedule->getKey())
            ->where('user_id', $user->getKey())
            ->whereDate('work_date', $date->toDateString())
            ->max('sequence') + 1;
        $pattern = $this->pattern($store, $code);

        return Shift::query()->create([
            'shift_schedule_id' => $schedule->getKey(),
            'user_id' => $user->getKey(),
            'work_date' => $date,
            'store_shift_pattern_id' => $pattern->getKey(),
            'sequence' => $sequence,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => $pattern->code,
            'work_minutes' => $pattern->work_minutes,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
    }

    private function pattern(Store $store, string $code): StoreShiftPattern
    {
        return StoreShiftPattern::query()
            ->where('store_id', $store->getKey())
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $roleCodes
     */
    private function createUser(
        string $email,
        array $roleCodes,
        bool $attachToStore,
    ): User {
        $user = User::query()->create([
            'organization_id' => $this->store->organization_id,
            'primary_store_id' => $attachToStore ? $this->store->getKey() : null,
            'name' => $email,
            'email' => $email,
            'password' => 'not-used-for-login',
            'status' => 'active',
        ]);
        $user->roles()->attach(
            Role::query()->whereIn('code', $roleCodes)->pluck('id'),
        );

        if ($attachToStore) {
            $user->stores()->attach($this->store->getKey(), [
                'display_order' => 999,
                'is_active' => true,
                'started_on' => null,
                'ended_on' => null,
            ]);
        }

        return $user;
    }
}
