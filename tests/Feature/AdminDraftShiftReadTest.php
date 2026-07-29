<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PublishedShift;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDraftShiftReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_shift_pages_require_an_authenticated_admin_role(): void
    {
        $this->get('/admin?month=2026-07')
            ->assertRedirect('/login');

        $this->actingAs($this->staff('staff@example.com'))
            ->get('/admin?month=2026-07')
            ->assertForbidden();
    }

    public function test_shift_manager_can_open_an_assigned_store_but_not_an_unassigned_store(): void
    {
        $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->assertSee('data-shift-source="draft"', false)
            ->assertSee('data-store-id="'.$this->store('daianji')->getKey().'"', false);

        $this->get('/admin/shifts/stores/noda?month=2026-07')
            ->assertForbidden();
    }

    public function test_system_admin_can_open_active_and_inactive_stores_read_only(): void
    {
        $this->actingAs($this->systemAdmin())
            ->get('/admin/shifts/stores/noda?month=2026-07')
            ->assertOk()
            ->assertSee('data-store-read-only="false"', false);

        $store = $this->store('daianji');
        $store->update(['status' => 'inactive']);

        $this->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->assertSee('data-store-read-only="true"', false)
            ->assertSee('disabled', false);

        $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertForbidden();
    }

    public function test_store_screen_displays_members_even_when_schedule_and_shifts_do_not_exist(): void
    {
        $store = $this->store('daianji');
        $staff = $this->staff('otsuki@example.com');

        $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-08')
            ->assertOk()
            ->assertSee('data-shift-schedule-id=""', false)
            ->assertSee('data-user-id="'.$staff->getKey().'"', false)
            ->assertSee($staff->name)
            ->assertDontSee('data-shift-id=', false);

        $this->assertDatabaseMissing('shift_schedules', [
            'store_id' => $store->getKey(),
            'target_month' => '2026-08-01',
        ]);
    }

    public function test_admin_role_accounts_are_not_displayed_as_shift_staff(): void
    {
        $manager = $this->staff('manager@example.com');
        $systemAdmin = $this->staff('admin@example.com');

        $response = $this->actingAs($systemAdmin)
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk();

        $response
            ->assertDontSee('data-user-id="'.$manager->getKey().'"', false)
            ->assertDontSee('data-user-id="'.$systemAdmin->getKey().'"', false)
            ->assertSee(
                'data-user-id="'.$this->staff('otsuki@example.com')->getKey().'"',
                false,
            );

        $this->get(
            "/admin/shifts/staff/{$manager->getKey()}?month=2026-07&store=daianji",
        )->assertNotFound();
        $this->get(
            "/admin/shifts/staff/{$systemAdmin->getKey()}?month=2026-07&store=daianji",
        )->assertNotFound();
    }

    public function test_store_without_members_displays_a_distinct_empty_staff_state(): void
    {
        $organization = Organization::query()->where('code', 'sample-company')->firstOrFail();
        $store = Store::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'empty-store',
            'name' => '所属なし店舗',
            'status' => 'active',
            'display_order' => 99,
            'staffing_check_mode' => 'disabled',
        ]);

        $this->actingAs($this->systemAdmin())
            ->get('/admin/shifts/stores/empty-store?month=2026-07')
            ->assertOk()
            ->assertSee('data-empty-state="staff"', false)
            ->assertSee('所属スタッフがいません')
            ->assertDontSee('data-shift-id=', false);

        $this->assertDatabaseMissing('shift_schedules', [
            'store_id' => $store->getKey(),
        ]);
    }

    public function test_get_requests_do_not_create_or_change_business_records(): void
    {
        $countsBefore = $this->businessRecordCounts();
        $staff = $this->staff('staff@example.com');

        $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-08')
            ->assertOk();
        $this->get(
            "/admin/shifts/staff/{$staff->getKey()}?month=2026-08&store=daianji",
        )->assertOk();

        $this->assertSame($countsBefore, $this->businessRecordCounts());
    }

    public function test_store_screen_maps_a_shift_to_its_real_user_store_and_date(): void
    {
        $store = $this->store('daianji');
        $shift = Shift::query()
            ->whereHas('schedule', fn ($query) => $query->where('store_id', $store->getKey()))
            ->whereDate('work_date', '2026-07-07')
            ->where('user_id', $this->staff('staff@example.com')->getKey())
            ->firstOrFail();

        $response = $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk();

        $this->assertShiftMarkup($response->getContent(), $shift, $store);
    }

    public function test_reordering_staff_rows_does_not_move_shifts_between_users(): void
    {
        $store = $this->store('daianji');
        $otsuki = $this->staff('otsuki@example.com');
        $fujimoto = $this->staff('fujimoto@example.com');
        $otsukiShift = $this->firstShift($store, $otsuki);
        $fujimotoShift = $this->firstShift($store, $fujimoto);

        DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->where('user_id', $otsuki->getKey())
            ->update(['display_order' => 20]);
        DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->where('user_id', $fujimoto->getKey())
            ->update(['display_order' => 1]);

        $response = $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->assertSeeInOrder([$fujimoto->name, $otsuki->name]);

        $this->assertShiftMarkup($response->getContent(), $otsukiShift, $store);
        $this->assertShiftMarkup($response->getContent(), $fujimotoShift, $store);
    }

    public function test_same_name_staff_are_distinguished_by_user_id(): void
    {
        $store = $this->store('daianji');
        $otsuki = $this->staff('otsuki@example.com');
        $fujimoto = $this->staff('fujimoto@example.com');
        $otsuki->update(['name' => '同姓同名']);
        $fujimoto->update(['name' => '同姓同名']);

        $response = $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->assertSee('同姓同名', false);

        $this->assertShiftMarkup(
            $response->getContent(),
            $this->firstShift($store, $otsuki),
            $store,
        );
        $this->assertShiftMarkup(
            $response->getContent(),
            $this->firstShift($store, $fujimoto),
            $store,
        );
    }

    public function test_month_start_and_end_shifts_remain_in_their_real_date_columns(): void
    {
        $store = $this->store('daianji');
        $staff = $this->staff('motoyama@example.com');
        $firstDay = $this->shift($store, $staff, '2026-07-01', 1);
        $lastDay = $this->shift($store, $staff, '2026-07-31', 1);

        $response = $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk();

        $this->assertShiftMarkup($response->getContent(), $firstDay, $store);
        $this->assertShiftMarkup($response->getContent(), $lastDay, $store);
    }

    public function test_calendar_handles_28_29_30_and_31_day_months(): void
    {
        $admin = $this->systemAdmin();

        $cases = [
            ['now' => '2026-07-30', 'month' => '2026-07', 'last' => '31', 'missing' => '32'],
            ['now' => '2026-07-30', 'month' => '2026-09', 'last' => '30', 'missing' => '31'],
            ['now' => '2026-11-15', 'month' => '2027-02', 'last' => '28', 'missing' => '29'],
            ['now' => '2027-11-15', 'month' => '2028-02', 'last' => '29', 'missing' => '30'],
        ];

        foreach ($cases as $case) {
            CarbonImmutable::setTestNow(
                CarbonImmutable::parse($case['now'], 'Asia/Tokyo')->setTime(12, 0),
            );

            $this->actingAs($admin)
                ->get("/admin/shifts/stores/daianji?month={$case['month']}")
                ->assertOk()
                ->assertSee(
                    'data-shift-date="'.$case['month'].'-'.$case['last'].'"',
                    false,
                )
                ->assertDontSee(
                    'data-shift-date="'.$case['month'].'-'.$case['missing'].'"',
                    false,
                );
        }
    }

    public function test_multiple_shifts_in_one_cell_are_displayed_in_sequence_order(): void
    {
        $store = $this->store('daianji');
        $staff = $this->staff('staff@example.com');
        $firstShift = $this->shift($store, $staff, '2026-07-07', 1);
        $pattern = StoreShiftPattern::query()
            ->where('store_id', $store->getKey())
            ->where('code', 'C')
            ->firstOrFail();
        $secondShift = Shift::query()->create([
            'shift_schedule_id' => $firstShift->shift_schedule_id,
            'user_id' => $staff->getKey(),
            'work_date' => '2026-07-07',
            'store_shift_pattern_id' => $pattern->getKey(),
            'sequence' => 2,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => 'C',
            'work_minutes' => 450,
        ]);

        $content = $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->getContent();

        $firstPosition = strpos($content, 'data-shift-id="'.$firstShift->getKey().'"');
        $secondPosition = strpos($content, 'data-shift-id="'.$secondShift->getKey().'"');

        $this->assertIsInt($firstPosition);
        $this->assertIsInt($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
        $this->assertShiftMarkup($content, $firstShift, $store);
        $this->assertShiftMarkup($content, $secondShift, $store);
    }

    public function test_store_and_staff_screens_project_the_same_draft_shift_records(): void
    {
        $staff = $this->staff('staff@example.com');
        $daianji = $this->store('daianji');
        $noda = $this->store('noda');
        $daianjiShift = $this->firstShift($daianji, $staff);
        $nodaShift = $this->firstShift($noda, $staff);

        $storeContent = $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->getContent();
        $staffResponse = $this->get(
            "/admin/shifts/staff/{$staff->getKey()}?month=2026-07&store=daianji",
        )
            ->assertOk()
            ->assertSee('data-read-only="true"', false)
            ->assertDontSee('data-static-shift-mode', false);
        $staffContent = $staffResponse->getContent();

        $this->assertShiftMarkup($storeContent, $daianjiShift, $daianji);
        $this->assertShiftMarkup($staffContent, $daianjiShift, $daianji);
        $this->assertShiftMarkup($staffContent, $nodaShift, $noda);

        $this->get('/admin/shifts/stores/noda?month=2026-07')
            ->assertForbidden();
    }

    public function test_staff_screen_rejects_a_user_without_membership_in_the_filter_store(): void
    {
        $nodaOnlyStaff = $this->staff('miyake@example.com');

        $this->actingAs($this->manager())
            ->get(
                "/admin/shifts/staff/{$nodaOnlyStaff->getKey()}?month=2026-07&store=daianji",
            )
            ->assertNotFound();
    }

    public function test_staff_screen_does_not_expose_drafts_from_another_organization(): void
    {
        $organization = Organization::query()->create([
            'code' => 'other-company',
            'name' => '別組織',
            'is_active' => true,
        ]);
        $store = Store::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'other-store',
            'name' => '別組織店舗',
            'status' => 'active',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
        ]);
        $pattern = StoreShiftPattern::query()->create([
            'store_id' => $store->getKey(),
            'code' => 'X',
            'work_minutes' => 60,
            'display_order' => 1,
            'is_active' => true,
        ]);
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $store->getKey(),
            'target_month' => '2026-07-01',
        ]);
        $foreignShift = Shift::query()->create([
            'shift_schedule_id' => $schedule->getKey(),
            'user_id' => $this->staff('staff@example.com')->getKey(),
            'work_date' => '2026-07-15',
            'store_shift_pattern_id' => $pattern->getKey(),
            'sequence' => 1,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => 'X',
            'work_minutes' => 60,
        ]);

        $this->actingAs($this->manager())
            ->get(
                '/admin/shifts/staff/'.$foreignShift->user_id
                .'?month=2026-07&store=daianji',
            )
            ->assertOk()
            ->assertDontSee(
                'data-shift-id="'.$foreignShift->getKey().'"',
                false,
            )
            ->assertDontSee('別組織店舗');
    }

    public function test_admin_reads_never_query_published_shifts(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        });
        $staff = $this->staff('staff@example.com');

        $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk();
        $this->get(
            "/admin/shifts/staff/{$staff->getKey()}?month=2026-07&store=daianji",
        )->assertOk();

        $this->assertFalse(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'published_shifts'),
            ),
        );
    }

    public function test_out_of_range_month_redirects_without_creating_records(): void
    {
        $countsBefore = $this->businessRecordCounts();

        $this->actingAs($this->manager())
            ->get('/admin/shifts/stores/daianji?month=2026-06')
            ->assertRedirect('/admin/shifts/stores/daianji?month=2026-07');

        $this->assertSame($countsBefore, $this->businessRecordCounts());
    }

    /**
     * @return array{shift_schedules: int, shifts: int, published_shifts: int}
     */
    private function businessRecordCounts(): array
    {
        return [
            'shift_schedules' => ShiftSchedule::query()->count(),
            'shifts' => Shift::query()->count(),
            'published_shifts' => PublishedShift::query()->count(),
        ];
    }

    private function manager(): User
    {
        return $this->staff('manager@example.com');
    }

    private function systemAdmin(): User
    {
        return $this->staff('admin@example.com');
    }

    private function staff(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function store(string $code): Store
    {
        return Store::query()->where('code', $code)->firstOrFail();
    }

    private function firstShift(Store $store, User $staff): Shift
    {
        return Shift::query()
            ->where('user_id', $staff->getKey())
            ->whereHas('schedule', fn ($query) => $query->where('store_id', $store->getKey()))
            ->orderBy('work_date')
            ->orderBy('sequence')
            ->firstOrFail();
    }

    private function shift(
        Store $store,
        User $staff,
        string $date,
        int $sequence,
    ): Shift {
        return Shift::query()
            ->where('user_id', $staff->getKey())
            ->whereDate('work_date', $date)
            ->where('sequence', $sequence)
            ->whereHas('schedule', fn ($query) => $query->where('store_id', $store->getKey()))
            ->firstOrFail();
    }

    private function assertShiftMarkup(string $html, Shift $shift, Store $store): void
    {
        $pattern = sprintf(
            '/<span\s+class="admin-shift-grid__shift-code"\s+'
            .'data-user-id="%d"\s+data-store-id="%d"\s+'
            .'data-shift-date="%s"\s+data-shift-id="%d"\s+'
            .'data-entry-uuid="%s"\s+data-sequence="%d"\s+'
            .'data-shift-pattern-id="%d"\s+data-work-minutes="%d"[^>]*>'
            .'%s<\/span>/s',
            $shift->user_id,
            $store->getKey(),
            preg_quote($shift->work_date->toDateString(), '/'),
            $shift->getKey(),
            preg_quote((string) $shift->entry_uuid, '/'),
            $shift->sequence,
            $shift->store_shift_pattern_id,
            $shift->work_minutes,
            preg_quote($shift->pattern_code, '/'),
        );

        $this->assertMatchesRegularExpression($pattern, $html);
    }
}
