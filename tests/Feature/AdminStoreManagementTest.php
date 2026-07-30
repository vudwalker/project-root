<?php

namespace Tests\Feature;

use App\Models\Organization;
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

class AdminStoreManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $systemAdmin;

    private User $manager;

    private Store $daianji;

    private Store $noda;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);

        $this->systemAdmin = $this->user('admin@example.com');
        $this->manager = $this->user('manager@example.com');
        $this->daianji = $this->store('daianji');
        $this->noda = $this->store('noda');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_system_admin_lists_and_filters_same_organization_stores(): void
    {
        $this->daianji->update(['status' => 'inactive']);
        $foreignStore = $this->foreignStore();

        $all = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('店舗情報管理')
            ->assertSee('data-store-code="daianji"', false)
            ->assertSee('data-store-status="inactive"', false)
            ->assertSee('data-store-code="noda"', false)
            ->assertDontSee($foreignStore->name);

        $this->assertSame(
            ['noda', 'saidaiji', 'okayama-tomida', 'daianji'],
            $all->viewData('stores')->pluck('code')->all(),
        );

        $active = $this->get(
            route('admin.stores.index', ['status' => 'active']),
        )->assertOk();
        $this->assertSame(
            ['noda', 'saidaiji', 'okayama-tomida'],
            $active->viewData('stores')->pluck('code')->all(),
        );

        $inactive = $this->get(
            route('admin.stores.index', ['status' => 'inactive']),
        )->assertOk();
        $this->assertSame(
            ['daianji'],
            $inactive->viewData('stores')->pluck('code')->all(),
        );
    }

    public function test_system_admin_updates_existing_schema_fields_without_touching_shift_data(): void
    {
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');
        $organizationId = $this->daianji->organization_id;
        $code = $this->daianji->code;

        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', ['store' => $code]),
                [
                    'name' => '大安寺 更新',
                    'status' => 'inactive',
                    'display_order' => 5,
                    'staffing_check_mode' => 'fixed_total',
                    'required_staff_count' => 2,
                    'filter_status' => 'all',
                ],
            )
            ->assertRedirect(
                route('admin.stores.index', ['status' => 'all']),
            )
            ->assertSessionHas('status', '店舗情報を更新しました。');

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => '大安寺 更新',
            'status' => 'inactive',
            'display_order' => 5,
            'staffing_check_mode' => 'fixed_total',
            'required_staff_count' => 2,
        ]);
        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );
    }

    public function test_shift_manager_only_lists_and_edits_current_active_assignments(): void
    {
        $response = $this->actingAs($this->manager)
            ->get(route('admin.stores.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('現在有効な担当店舗だけを表示しています。')
            ->assertSee('data-store-code="daianji"', false)
            ->assertDontSee('data-store-code="noda"', false);

        $this->assertSame(
            ['daianji'],
            $response->viewData('stores')->pluck('code')->all(),
        );
        $this->get(
            route('admin.stores.edit', ['store' => $this->daianji->code]),
        )
            ->assertOk()
            ->assertDontSee('name="status"', false);
        $this->get(
            route('admin.stores.edit', ['store' => $this->noda->code]),
        )->assertForbidden();

        $publishedBefore = $this->tableSnapshot('published_shifts');

        $this->patch(
            route('admin.stores.update', ['store' => $this->daianji->code]),
            [
                'name' => '大安寺 担当者更新',
                'display_order' => 15,
                'staffing_check_mode' => 'disabled',
                'required_staff_count' => null,
                'filter_status' => 'active',
            ],
        )->assertRedirect(
            route('admin.stores.index', ['status' => 'active']),
        );

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'name' => '大安寺 担当者更新',
            'status' => 'active',
            'display_order' => 15,
            'staffing_check_mode' => 'disabled',
            'required_staff_count' => null,
        ]);
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );

        $this->patch(
            route('admin.stores.update', ['store' => $this->daianji->code]),
            [
                'name' => '変更不可',
                'status' => 'inactive',
                'display_order' => 16,
                'staffing_check_mode' => 'disabled',
                'required_staff_count' => null,
            ],
        )
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'name' => '大安寺 担当者更新',
            'status' => 'active',
        ]);
    }

    public function test_unassigned_and_foreign_organization_stores_are_rejected(): void
    {
        $foreignStore = $this->foreignStore();
        $payload = [
            'name' => '不正更新',
            'status' => 'active',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
            'required_staff_count' => null,
        ];

        $this->actingAs($this->manager)
            ->patch(
                route('admin.stores.update', ['store' => $this->noda->code]),
                array_diff_key($payload, ['status' => true]),
            )
            ->assertForbidden();

        $this->actingAs($this->systemAdmin)
            ->get(
                route('admin.stores.edit', ['store' => $foreignStore->code]),
            )
            ->assertForbidden();
        $this->patch(
            route('admin.stores.update', ['store' => $foreignStore->code]),
            $payload,
        )->assertForbidden();

        $this->assertDatabaseHas('stores', [
            'id' => $this->noda->getKey(),
            'name' => '野田',
        ]);
        $this->assertDatabaseHas('stores', [
            'id' => $foreignStore->getKey(),
            'name' => '別組織店舗',
        ]);
    }

    public function test_inactive_store_rejects_shift_writes_and_publish_but_keeps_history(): void
    {
        $schedule = ShiftSchedule::query()
            ->where('store_id', $this->daianji->getKey())
            ->whereDate('target_month', '2026-07-01')
            ->firstOrFail();
        $shift = $schedule->shifts()->firstOrFail();
        $pattern = StoreShiftPattern::query()
            ->where('store_id', $this->daianji->getKey())
            ->where('is_active', true)
            ->firstOrFail();
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');
        $scheduleBefore = $this->tableSnapshot('shift_schedules');

        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', [
                    'store' => $this->daianji->code,
                ]),
                [
                    'name' => $this->daianji->name,
                    'status' => 'inactive',
                    'display_order' => $this->daianji->display_order,
                    'staffing_check_mode' => $this->daianji->staffing_check_mode,
                    'required_staff_count' => $this->daianji->required_staff_count,
                    'filter_status' => 'all',
                ],
            )
            ->assertRedirect();

        $this->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->assertSee('data-store-read-only="true"', false);

        $this->postJson(
            '/admin/shifts/stores/daianji/shifts',
            [
                'target_month' => '2026-07',
                'expected_draft_version' => $schedule->draft_version,
                'user_id' => $shift->user_id,
                'work_date' => '2026-07-01',
                'shift_pattern_id' => $pattern->getKey(),
                'entry_uuid' => (string) Str::uuid(),
            ],
        )->assertForbidden();

        $this->patchJson(
            "/admin/shifts/stores/daianji/shifts/{$shift->getKey()}",
            [
                'target_month' => '2026-07',
                'expected_draft_version' => $schedule->draft_version,
                'shift_pattern_id' => $pattern->getKey(),
            ],
        )->assertForbidden();

        $this->postJson(
            '/admin/shifts/stores/daianji/publish',
            [
                'target_month' => '2026-07',
                'expected_draft_version' => $schedule->draft_version,
            ],
        )->assertForbidden();

        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );
        $this->assertSame(
            $scheduleBefore,
            $this->tableSnapshot('shift_schedules'),
        );
    }

    public function test_store_management_requires_admin_access(): void
    {
        $this->get(route('admin.stores.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->user('staff@example.com'))
            ->get(route('admin.stores.index'))
            ->assertForbidden();
    }

    public function test_store_fields_reject_invalid_and_server_managed_values(): void
    {
        $original = $this->daianji->only([
            'organization_id',
            'code',
            'name',
            'status',
            'display_order',
            'staffing_check_mode',
            'required_staff_count',
        ]);

        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', [
                    'store' => $this->daianji->code,
                ]),
                [
                    'organization_id' => 999,
                    'code' => 'changed-code',
                    'name' => '',
                    'status' => 'closed',
                    'display_order' => -1,
                    'staffing_check_mode' => 'unknown',
                    'required_staff_count' => -1,
                ],
            )
            ->assertSessionHasErrors([
                'organization_id',
                'code',
                'name',
                'status',
                'display_order',
                'staffing_check_mode',
                'required_staff_count',
            ]);

        $this->assertSame(
            $original,
            $this->daianji->fresh()->only(array_keys($original)),
        );

        $this->patch(
            route('admin.stores.update', [
                'store' => $this->daianji->code,
            ]),
            [
                'name' => $this->daianji->name,
                'status' => 'active',
                'display_order' => $this->daianji->display_order,
                'staffing_check_mode' => 'fixed_total',
                'required_staff_count' => null,
            ],
        )->assertSessionHasErrors('required_staff_count');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableSnapshot(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function store(string $code): Store
    {
        return Store::query()->where('code', $code)->firstOrFail();
    }

    private function foreignStore(): Store
    {
        $organization = Organization::query()->firstOrCreate(
            ['code' => 'foreign-company'],
            [
                'name' => '別組織',
                'is_active' => true,
            ],
        );

        return Store::query()->firstOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'code' => 'foreign-store',
            ],
            [
                'name' => '別組織店舗',
                'status' => 'active',
                'display_order' => 1,
                'staffing_check_mode' => 'disabled',
                'required_staff_count' => null,
            ],
        );
    }
}
