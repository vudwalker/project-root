<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PublishedShift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffPublishedShiftReadTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_MONTH = '2026-09';

    private Store $store;

    private User $manager;

    private User $staff;

    private User $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);

        $this->store = Store::query()
            ->where('code', 'daianji')
            ->firstOrFail();
        $this->store
            ->forceFill(['staffing_check_mode' => 'disabled'])
            ->save();
        $this->manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();
        $this->staff = User::query()
            ->where('email', 'otsuki@example.com')
            ->firstOrFail();
        $this->otherStaff = User::query()
            ->where('email', 'fujimoto@example.com')
            ->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_unpublished_month_keeps_drafts_out_of_both_staff_screens(): void
    {
        $created = $this->createDraft('C');

        $personalResponse = $this->actingAs($this->staff)
            ->get('/staff?month='.self::TARGET_MONTH);
        $storeResponse = $this->actingAs($this->staff)
            ->get('/staff/store/daianji?month='.self::TARGET_MONTH);

        $personalResponse
            ->assertOk()
            ->assertViewHas(
                'personalShifts',
                fn (array $shifts): bool => $shifts === [],
            );
        $this->assertStoreStaffCode(
            $storeResponse,
            $this->staff,
            '2026-09-10',
            null,
        );
        $this->assertNoStoreCache($personalResponse);
        $this->assertNoStoreCache($storeResponse);
        $this->assertDatabaseHas('shifts', [
            'id' => $created['shift_id'],
            'pattern_code' => 'C',
        ]);
        $this->assertDatabaseMissing('published_shifts', [
            'shift_schedule_id' => $created['shift_schedule_id'],
        ]);
    }

    public function test_initial_publish_stays_visible_until_republish_replaces_it(): void
    {
        $created = $this->createDraft('C');

        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), [
                'target_month' => self::TARGET_MONTH,
                'expected_draft_version' => 1,
            ])
            ->assertOk()
            ->assertJson([
                'published_version' => 1,
                'published_draft_version' => 1,
            ]);

        $this->assertStaffScreensShowCode('C');

        $patternD = $this->pattern('D');
        $this->actingAs($this->manager)
            ->patchJson(
                route('admin.shifts.update', [
                    'store' => $this->store->code,
                    'shift' => $created['shift_id'],
                ]),
                [
                    'target_month' => self::TARGET_MONTH,
                    'expected_draft_version' => 1,
                    'shift_pattern_id' => $patternD->getKey(),
                ],
            )
            ->assertOk()
            ->assertJson([
                'pattern_code' => 'D',
                'draft_version' => 2,
            ]);

        $this->assertStaffScreensShowCode('C');
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $created['shift_schedule_id'],
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-09-10',
            'sequence' => 1,
            'pattern_code' => 'C',
        ]);

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 5, 0, 'Asia/Tokyo'),
        );
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), [
                'target_month' => self::TARGET_MONTH,
                'expected_draft_version' => 2,
            ])
            ->assertOk()
            ->assertJson([
                'published_version' => 2,
                'published_draft_version' => 2,
            ]);

        $this->assertStaffScreensShowCode('D');
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $created['shift_schedule_id'],
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-09-10',
            'sequence' => 1,
            'pattern_code' => 'D',
            'published_at' => '2026-07-30 12:05:00',
        ]);
        $this->assertDatabaseHas('shift_schedules', [
            'id' => $created['shift_schedule_id'],
            'draft_version' => 2,
            'published_version' => 2,
            'published_draft_version' => 2,
            'published_at' => '2026-07-30 12:05:00',
        ]);
    }

    public function test_personal_screen_returns_only_the_authenticated_users_consistent_snapshot(): void
    {
        $this->createPublishedSchedule($this->store, [
            [$this->staff, '2026-09-08', 'A'],
            [$this->otherStaff, '2026-09-09', 'B'],
        ]);
        $foreignStore = $this->foreignStore();
        $this->createPublishedSchedule($foreignStore, [
            [$this->staff, '2026-09-11', 'C'],
        ]);
        $inconsistentStore = $this->sameOrganizationStore('inconsistent-store');
        $inconsistentSchedule = $this->createPublishedSchedule(
            $inconsistentStore,
            [],
            '2026-07-30 11:00:00',
        );
        PublishedShift::query()->create([
            'shift_schedule_id' => $inconsistentSchedule->getKey(),
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-09-12',
            'sequence' => 1,
            'pattern_code' => 'D',
            'work_hours' => '6.00',
            'published_at' => '2026-07-30 10:59:59',
        ]);
        $unpublishedStore = $this->sameOrganizationStore('unpublished-store');
        $unpublishedSchedule = ShiftSchedule::query()->create([
            'store_id' => $unpublishedStore->getKey(),
            'target_month' => self::TARGET_MONTH.'-01',
            'draft_version' => 1,
        ]);
        PublishedShift::query()->create([
            'shift_schedule_id' => $unpublishedSchedule->getKey(),
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-09-13',
            'sequence' => 1,
            'pattern_code' => 'E',
            'work_hours' => '6.00',
            'published_at' => '2026-07-30 11:00:00',
        ]);

        $response = $this->actingAs($this->staff)
            ->get('/staff?month='.self::TARGET_MONTH)
            ->assertOk();

        $response->assertViewHas('personalShifts', function (array $shifts): bool {
            $entries = collect($shifts)->flatten(1);

            return $entries->count() === 1
                && $entries->every(
                    fn (array $shift): bool => $shift['user_id'] === $this->staff->getKey(),
                )
                && $entries->pluck('store_code')->all() === ['daianji']
                && $entries->pluck('shift_type.code')->all() === ['A'];
        });
    }

    public function test_store_screen_requires_current_same_organization_membership(): void
    {
        $outsider = User::query()
            ->where('email', 'miyake@example.com')
            ->firstOrFail();
        $this->createPublishedSchedule($this->store, [
            [$this->staff, '2026-09-08', 'A'],
            [$this->otherStaff, '2026-09-09', 'B'],
            [$outsider, '2026-09-10', 'C'],
        ]);

        $response = $this->actingAs($this->staff)
            ->get('/staff/store/daianji?month='.self::TARGET_MONTH)
            ->assertOk();

        $response
            ->assertViewHas(
                'stores',
                fn (array $stores): bool => array_keys($stores) === ['daianji'],
            )
            ->assertViewHas('store', function (array $store) use ($outsider): bool {
                $staff = collect($store['staff']);

                return $staff->contains('id', $this->staff->getKey())
                    && $staff->contains('id', $this->otherStaff->getKey())
                    && $staff->contains('id', $outsider->getKey());
            });
        $this->assertStoreStaffCode(
            $response,
            $this->staff,
            '2026-09-08',
            'A',
        );
        $this->assertStoreStaffCode(
            $response,
            $this->otherStaff,
            '2026-09-09',
            'B',
        );
        $this->assertStoreStaffCode(
            $response,
            $outsider,
            '2026-09-10',
            'C',
        );

        $this->get('/staff/store/noda?month='.self::TARGET_MONTH)
            ->assertNotFound();

        $foreignStore = $this->foreignStore();
        $this->attachMembership($this->staff, $foreignStore);
        $this->get(
            "/staff/store/{$foreignStore->code}?month=".self::TARGET_MONTH,
        )->assertNotFound();

        $disabledMembershipStore = $this->sameOrganizationStore(
            'disabled-membership-store',
        );
        $this->attachMembership(
            $this->staff,
            $disabledMembershipStore,
            false,
        );
        $this->get(
            "/staff/store/{$disabledMembershipStore->code}?month=".self::TARGET_MONTH,
        )->assertNotFound();

        $endedMembershipStore = $this->sameOrganizationStore(
            'ended-membership-store',
        );
        $this->attachMembership(
            $this->staff,
            $endedMembershipStore,
            true,
            '2026-07-29',
        );
        $this->get(
            "/staff/store/{$endedMembershipStore->code}?month=".self::TARGET_MONTH,
        )->assertNotFound();
    }

    public function test_staff_role_is_required_for_both_staff_screens(): void
    {
        $managerOnly = User::query()
            ->where('email', 'manager-only@example.com')
            ->firstOrFail();

        $this->actingAs($managerOnly)
            ->get('/staff?month='.self::TARGET_MONTH)
            ->assertForbidden();
        $this->get('/staff/store/daianji?month='.self::TARGET_MONTH)
            ->assertForbidden();
    }

    public function test_staff_requests_query_published_shifts_and_never_query_drafts(): void
    {
        $staff = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($staff)
            ->get('/staff?month=2026-07')
            ->assertOk();
        $this->get('/staff/store/noda?month=2026-07')
            ->assertOk();

        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains(
                    strtolower($sql),
                    'published_shifts',
                ),
            ),
        );
        $draftQueries = collect($queries)->filter(
            fn (string $sql): bool => preg_match(
                '/\b(?:from|join)\s+["`]?shifts["`]?(?:\s|$)/i',
                $sql,
            ) === 1,
        );
        $this->assertSame([], $draftQueries->values()->all());

        $controller = file_get_contents(
            app_path('Http/Controllers/StaffShiftController.php'),
        );
        $service = file_get_contents(
            app_path('Services/Staff/PublishedShiftReadService.php'),
        );

        $this->assertIsString($controller);
        $this->assertIsString($service);
        $this->assertStringContainsString('PublishedShiftReadService', $controller);
        $this->assertStringNotContainsString('StaffShiftMockService', $controller);
        $this->assertStringContainsString('PublishedShift::query()', $service);
        $this->assertStringNotContainsString('App\\Models\\Shift;', $service);
    }

    /**
     * @return array<string, mixed>
     */
    private function createDraft(string $code): array
    {
        return $this->actingAs($this->manager)
            ->postJson(
                route('admin.shifts.store', ['store' => $this->store->code]),
                [
                    'target_month' => self::TARGET_MONTH,
                    'expected_draft_version' => 0,
                    'user_id' => $this->staff->getKey(),
                    'work_date' => '2026-09-10',
                    'shift_pattern_id' => $this->pattern($code)->getKey(),
                    'entry_uuid' => (string) Str::uuid(),
                ],
            )
            ->assertCreated()
            ->assertJson([
                'user_id' => $this->staff->getKey(),
                'shift_date' => '2026-09-10',
                'pattern_code' => $code,
                'draft_version' => 1,
            ])
            ->json();
    }

    private function assertStaffScreensShowCode(string $code): void
    {
        $personalResponse = $this->actingAs($this->staff)
            ->get('/staff?month='.self::TARGET_MONTH)
            ->assertOk();
        $storeResponse = $this->actingAs($this->staff)
            ->get('/staff/store/daianji?month='.self::TARGET_MONTH)
            ->assertOk();

        $personalResponse->assertViewHas(
            'personalShifts',
            fn (array $shifts): bool => (
                $shifts['2026-09-10'][0]['shift_type']['code'] ?? null
            ) === $code,
        );
        $this->assertStoreStaffCode(
            $storeResponse,
            $this->staff,
            '2026-09-10',
            $code,
        );
        $this->assertNoStoreCache($personalResponse);
        $this->assertNoStoreCache($storeResponse);
    }

    private function assertStoreStaffCode(
        TestResponse $response,
        User $staff,
        string $workDate,
        ?string $code,
    ): void {
        $response->assertViewHas('store', function (array $store) use (
            $staff,
            $workDate,
            $code,
        ): bool {
            $staffRow = collect($store['staff'])
                ->firstWhere('id', $staff->getKey());
            $actualCode = $staffRow['shifts'][$workDate]['shift_type']['code']
                ?? null;

            return $actualCode === $code;
        });
    }

    private function assertNoStoreCache(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
    }

    /**
     * @param  list<array{User, string, string}>  $entries
     */
    private function createPublishedSchedule(
        Store $store,
        array $entries,
        string $publishedAt = '2026-07-30 11:00:00',
    ): ShiftSchedule {
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $store->getKey(),
            'target_month' => self::TARGET_MONTH.'-01',
            'draft_version' => 1,
            'published_version' => 1,
            'published_draft_version' => 1,
            'published_at' => $publishedAt,
            'published_by_user_id' => $this->manager->getKey(),
            'created_by' => $this->manager->getKey(),
            'updated_by' => $this->manager->getKey(),
        ]);

        foreach ($entries as $index => [$user, $workDate, $code]) {
            PublishedShift::query()->create([
                'shift_schedule_id' => $schedule->getKey(),
                'user_id' => $user->getKey(),
                'work_date' => $workDate,
                'sequence' => 1,
                'pattern_code' => $code,
                'work_hours' => number_format(6 + ($index / 100), 2, '.', ''),
                'published_at' => $publishedAt,
            ]);
        }

        return $schedule;
    }

    private function attachMembership(
        User $user,
        Store $store,
        bool $isActive = true,
        ?string $endedOn = null,
    ): void {
        $user->stores()->syncWithoutDetaching([
            $store->getKey() => [
                'display_order' => 99,
                'is_active' => $isActive,
                'started_on' => null,
                'ended_on' => $endedOn,
            ],
        ]);
    }

    private function sameOrganizationStore(string $code): Store
    {
        return Store::query()->create([
            'organization_id' => $this->store->organization_id,
            'code' => $code,
            'name' => $code,
            'display_order' => 90,
            'staffing_check_mode' => 'disabled',
        ]);
    }

    private function foreignStore(): Store
    {
        $organization = Organization::query()->create([
            'code' => 'foreign-'.Str::lower(Str::random(8)),
            'name' => '別組織',
            'is_active' => true,
        ]);

        return Store::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'foreign-store-'.Str::lower(Str::random(8)),
            'name' => '別組織店舗',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
        ]);
    }

    private function pattern(string $code): StoreShiftPattern
    {
        return StoreShiftPattern::query()
            ->where('store_id', $this->store->getKey())
            ->where('code', $code)
            ->firstOrFail();
    }

    private function publishUrl(): string
    {
        return route('admin.shifts.publish', ['store' => $this->store->code]);
    }
}
