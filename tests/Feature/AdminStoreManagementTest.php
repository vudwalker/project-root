<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
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

    private User $managerOnly;

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
        $this->managerOnly = $this->user('manager-only@example.com');
        $this->daianji = $this->store('daianji');
        $this->noda = $this->store('noda');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_store_list_is_code_ordered_and_contains_only_search_columns(): void
    {
        $this->daianji->update([
            'area' => '岡山中央',
            'status' => 'inactive',
        ]);
        $this->noda->update(['area' => '岡山西']);
        $foreignStore = $this->foreignStore();

        $response = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.index'))
            ->assertOk()
            ->assertSee('店舗情報管理')
            ->assertSee('name="manager_id"', false)
            ->assertSee('name="area"', false)
            ->assertSee('name="q"', false)
            ->assertSee('店舗追加')
            ->assertSee('data-store-status="inactive"', false)
            ->assertSee('未設定')
            ->assertDontSee($foreignStore->name)
            ->assertDontSee('<th scope="col">表示順</th>', false)
            ->assertDontSee('<th scope="col">人数チェック方式</th>', false)
            ->assertDontSee('<th scope="col">固定必要人数</th>', false);

        $this->assertSame(
            ['daianji', 'noda', 'okayama-tomida', 'saidaiji'],
            $response->viewData('stores')->pluck('code')->all(),
        );
    }

    public function test_manager_area_and_name_code_filters_are_applied_to_accessible_stores(): void
    {
        $this->daianji->update(['area' => '岡山中央']);
        $this->noda->update(['area' => '岡山西']);
        $this->store('okayama-tomida')->update(['area' => '岡山中央']);
        $this->assignManager($this->managerOnly, $this->daianji);

        $managerFiltered = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.index', [
                'manager_id' => $this->managerOnly->getKey(),
            ]))
            ->assertOk();
        $this->assertSame(
            ['daianji'],
            $managerFiltered->viewData('stores')->pluck('code')->all(),
        );

        $originalManagerFiltered = $this->get(route('admin.stores.index', [
            'manager_id' => $this->manager->getKey(),
        ]))->assertOk();
        $this->assertSame(
            ['daianji'],
            $originalManagerFiltered->viewData('stores')->pluck('code')->all(),
        );

        $areaFiltered = $this->get(route('admin.stores.index', [
            'area' => '岡山中央',
        ]))->assertOk();
        $this->assertSame(
            ['daianji', 'okayama-tomida'],
            $areaFiltered->viewData('stores')->pluck('code')->all(),
        );

        $unsetArea = $this->get(route('admin.stores.index', [
            'area' => '__unset__',
        ]))->assertOk();
        $this->assertSame(
            ['saidaiji'],
            $unsetArea->viewData('stores')->pluck('code')->all(),
        );

        $nameSearch = $this->get(route('admin.stores.index', [
            'q' => '富田',
        ]))->assertOk();
        $this->assertSame(
            ['okayama-tomida'],
            $nameSearch->viewData('stores')->pluck('code')->all(),
        );

        $codeSearch = $this->get(route('admin.stores.index', [
            'q' => 'noda',
        ]))->assertOk();
        $this->assertSame(
            ['noda'],
            $codeSearch->viewData('stores')->pluck('code')->all(),
        );
    }

    public function test_system_admin_creates_store_with_trimmed_area_and_defaults(): void
    {
        $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.create'))
            ->assertOk()
            ->assertSee('店舗追加');

        $response = $this->post(route('admin.stores.store'), [
            'name' => ' 新店舗 ',
            'code' => ' new-store ',
            'area' => ' 岡山北 ',
            'status' => 'active',
        ]);

        $store = Store::query()->where('code', 'new-store')->firstOrFail();

        $response->assertRedirect(route('admin.stores.edit', [
            'store' => 'new-store',
        ]));
        $this->assertSame($this->systemAdmin->organization_id, $store->organization_id);
        $this->assertSame('新店舗', $store->name);
        $this->assertSame('岡山北', $store->area);
        $this->assertSame(0, $store->display_order);
        $this->assertSame('disabled', $store->staffing_check_mode);
        $this->assertNull($store->required_staff_count);
        $this->assertDatabaseCount('store_user', 12);
        $this->assertDatabaseCount('store_shift_patterns', 28);
    }

    public function test_store_creation_rejects_missing_area_duplicate_code_and_shift_manager(): void
    {
        $payload = [
            'name' => '重複店舗',
            'code' => $this->daianji->code,
            'area' => '岡山中央',
            'status' => 'active',
        ];

        $this->actingAs($this->systemAdmin)
            ->post(route('admin.stores.store'), $payload)
            ->assertSessionHasErrors('code');

        $payload['code'] = 'blank-area';
        $payload['area'] = '   ';
        $this->post(route('admin.stores.store'), $payload)
            ->assertSessionHasErrors('area');

        $payload['code'] = 'manager-created';
        $payload['area'] = '岡山中央';
        $this->actingAs($this->manager)
            ->post(route('admin.stores.store'), $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('stores', ['code' => 'manager-created']);
    }

    public function test_system_admin_updates_basic_information_without_changing_code_or_shift_data(): void
    {
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');
        $organizationId = $this->daianji->organization_id;
        $code = $this->daianji->code;

        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', ['store' => $code]),
                [
                    'name' => ' 大安寺 更新 ',
                    'area' => ' 岡山中央 ',
                    'status' => 'inactive',
                ],
            )
            ->assertRedirect(route('admin.stores.edit', ['store' => $code]));

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => '大安寺 更新',
            'area' => '岡山中央',
            'status' => 'inactive',
        ]);
        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );

        $this->patch(route('admin.stores.update', ['store' => $code]), [
            'name' => '不正',
            'area' => '岡山中央',
            'status' => 'active',
            'code' => 'changed-code',
            'organization_id' => 999,
        ])->assertSessionHasErrors(['code', 'organization_id']);

        $this->assertDatabaseMissing('stores', ['code' => 'changed-code']);
    }

    public function test_shift_manager_can_manage_assigned_inactive_store_but_not_status(): void
    {
        $this->daianji->update(['status' => 'inactive']);

        $response = $this->actingAs($this->manager)
            ->get(route('admin.stores.index'))
            ->assertOk()
            ->assertSee('data-store-code="daianji"', false)
            ->assertDontSee('data-store-code="noda"', false)
            ->assertDontSee('店舗追加');

        $this->assertSame(
            ['daianji'],
            $response->viewData('stores')->pluck('code')->all(),
        );

        $this->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk()
            ->assertSee('無効')
            ->assertDontSee('name="status"', false);

        $this->patch(route('admin.stores.update', ['store' => 'daianji']), [
            'name' => '大安寺 担当者更新',
            'area' => ' 岡山中央 ',
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'name' => '大安寺 担当者更新',
            'area' => '岡山中央',
            'status' => 'inactive',
        ]);

        $this->patch(route('admin.stores.update', ['store' => 'daianji']), [
            'name' => '変更不可',
            'area' => '岡山中央',
            'status' => 'active',
        ])->assertSessionHasErrors('status');
    }

    public function test_unassigned_and_foreign_organization_stores_are_rejected(): void
    {
        $foreignStore = $this->foreignStore();

        $this->actingAs($this->manager)
            ->get(route('admin.stores.edit', ['store' => $this->noda->code]))
            ->assertForbidden();
        $this->patch(
            route('admin.stores.update', ['store' => $this->noda->code]),
            ['name' => '不正更新', 'area' => '不正'],
        )->assertForbidden();

        $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.edit', ['store' => $foreignStore->code]))
            ->assertForbidden();
        $this->patch(
            route('admin.stores.update', ['store' => $foreignStore->code]),
            [
                'name' => '不正更新',
                'area' => '不正',
                'status' => 'active',
            ],
        )->assertForbidden();
    }

    public function test_staff_section_shows_only_current_store_members_in_compact_table(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk()
            ->assertSee('data-store-member-table', false)
            ->assertSee('<th scope="col">氏名</th>', false)
            ->assertSee('<th scope="col">メールアドレス</th>', false)
            ->assertSee('<th scope="col">主所属</th>', false)
            ->assertSee('<th scope="col">操作</th>', false)
            ->assertSee('大月敦弘')
            ->assertSee('otsuki@example.com')
            ->assertDontSee('三宅由幸')
            ->assertDontSee('miyake@example.com')
            ->assertDontSee('name="staff_user_ids[]"', false)
            ->assertDontSee('data-store-staff-search-results', false);

        $this->assertSame(
            $this->activeStaffIds($this->daianji),
            $response->viewData('store')->staffMembers
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    public function test_unassigned_staff_search_uses_name_and_email_and_excludes_current_and_foreign_staff(): void
    {
        $miyake = $this->user('miyake@example.com');
        $otsuki = $this->user('otsuki@example.com');
        $foreignStore = $this->foreignStore();
        $foreignStaff = User::factory()->create([
            'organization_id' => $foreignStore->organization_id,
            'primary_store_id' => $foreignStore->getKey(),
            'name' => '別組織検索対象',
            'email' => 'foreign-search@example.net',
            'status' => 'active',
        ]);
        DB::table('role_user')->insert([
            'user_id' => $foreignStaff->getKey(),
            'role_id' => DB::table('roles')->where('code', 'staff')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nameSearch = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.edit', [
                'store' => 'daianji',
                'staff_add' => 1,
                'staff_query' => '三宅',
            ]))
            ->assertOk()
            ->assertSee('data-store-staff-search-results', false)
            ->assertSee('三宅由幸')
            ->assertSee('miyake@example.com');
        $this->assertSame(
            [$miyake->getKey()],
            $nameSearch->viewData('staffSearchResults')->pluck('id')->all(),
        );

        $emailSearch = $this->get(route('admin.stores.edit', [
            'store' => 'daianji',
            'staff_add' => 1,
            'staff_query' => 'miyake@example.com',
        ]))->assertOk();
        $this->assertSame(
            [$miyake->getKey()],
            $emailSearch->viewData('staffSearchResults')->pluck('id')->all(),
        );

        $currentSearch = $this->get(route('admin.stores.edit', [
            'store' => 'daianji',
            'staff_add' => 1,
            'staff_query' => '大月',
        ]))->assertOk();
        $this->assertNotContains(
            $otsuki->getKey(),
            $currentSearch->viewData('staffSearchResults')->pluck('id')->all(),
        );

        $foreignSearch = $this->get(route('admin.stores.edit', [
            'store' => 'daianji',
            'staff_add' => 1,
            'staff_query' => '別組織検索対象',
        ]))->assertOk();
        $this->assertNotContains(
            $foreignStaff->getKey(),
            $foreignSearch->viewData('staffSearchResults')->pluck('id')->all(),
        );
    }

    public function test_staff_membership_individual_add_and_release_keep_shift_history(): void
    {
        $addedStaff = $this->user('miyake@example.com');
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');

        $this->actingAs($this->manager)
            ->post(
                route('admin.stores.staff.store', ['store' => 'daianji']),
                ['staff_user_id' => $addedStaff->getKey()],
            )
            ->assertRedirect();

        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->getKey(),
            'user_id' => $addedStaff->getKey(),
            'is_active' => true,
        ]);

        $this->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk()
            ->assertSee('三宅由幸')
            ->assertSee('data-store-member-user-id="'.$addedStaff->getKey().'"', false);

        $this->get(route('admin.stores.edit', [
            'store' => 'daianji',
            'staff_add' => 1,
            'staff_query' => '三宅',
        ]))
            ->assertOk()
            ->assertDontSee('data-staff-search-user-id="'.$addedStaff->getKey().'"', false);

        $this->delete(route('admin.stores.staff.destroy', [
            'store' => 'daianji',
            'staff' => $addedStaff->getKey(),
        ]))->assertRedirect();

        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->getKey(),
            'user_id' => $addedStaff->getKey(),
            'is_active' => false,
            'ended_on' => '2026-07-30',
        ]);
        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );

        $this->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk()
            ->assertDontSee('三宅由幸')
            ->assertDontSee(
                'data-store-member-user-id="'.$addedStaff->getKey().'"',
                false,
            );
    }

    public function test_primary_store_membership_cannot_be_released(): void
    {
        $primaryStaff = $this->user('otsuki@example.com');

        $this->actingAs($this->manager)
            ->delete(route('admin.stores.staff.destroy', [
                'store' => 'daianji',
                'staff' => $primaryStaff->getKey(),
            ]))
            ->assertSessionHasErrors('staff_user_id');

        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->getKey(),
            'user_id' => $primaryStaff->getKey(),
            'is_active' => true,
        ]);

        $this->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk()
            ->assertSee('解除不可')
            ->assertSee('主所属変更後に解除できます');
    }

    public function test_shift_manager_can_change_staff_only_for_assigned_store(): void
    {
        $candidate = $this->user('miyake@example.com');

        $this->actingAs($this->manager)
            ->post(
                route('admin.stores.staff.store', ['store' => 'daianji']),
                ['staff_user_id' => $candidate->getKey()],
            )
            ->assertRedirect();

        $this->post(
            route('admin.stores.staff.store', ['store' => 'noda']),
            ['staff_user_id' => $this->user('otsuki@example.com')->getKey()],
        )->assertForbidden();

        $this->delete(route('admin.stores.staff.destroy', [
            'store' => 'noda',
            'staff' => $candidate->getKey(),
        ]))->assertForbidden();
    }

    public function test_system_admin_assigns_and_releases_multiple_shift_managers(): void
    {
        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.managers.update', ['store' => 'daianji']),
                [
                    'manager_user_ids' => [
                        $this->manager->getKey(),
                        $this->managerOnly->getKey(),
                    ],
                ],
            )
            ->assertRedirect();

        $this->assertSame(
            2,
            DB::table('store_shift_manager')
                ->where('store_id', $this->daianji->getKey())
                ->where('is_active', true)
                ->count(),
        );

        $this->patch(
            route('admin.stores.managers.update', ['store' => 'daianji']),
            ['manager_user_ids' => [$this->manager->getKey()]],
        )->assertRedirect();

        $this->assertDatabaseHas('store_shift_manager', [
            'store_id' => $this->daianji->getKey(),
            'user_id' => $this->managerOnly->getKey(),
            'is_active' => false,
            'ended_on' => '2026-07-30',
        ]);

        $this->actingAs($this->manager)
            ->patch(
                route('admin.stores.managers.update', ['store' => 'daianji']),
                ['manager_user_ids' => [$this->managerOnly->getKey()]],
            )
            ->assertForbidden();
    }

    public function test_shift_pattern_update_and_add_preserve_existing_shift_snapshots(): void
    {
        $pattern = StoreShiftPattern::query()
            ->where('store_id', $this->daianji->getKey())
            ->where('code', 'C')
            ->firstOrFail();
        $workMinutes = $pattern->work_minutes;
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');

        $this->actingAs($this->manager)
            ->patch(
                route('admin.stores.patterns.update', ['store' => 'daianji']),
                [
                    'patterns' => [
                        [
                            'id' => $pattern->getKey(),
                            'code' => 'C',
                            'start_time' => '07:00',
                            'end_time' => '19:00',
                            'ends_next_day' => 1,
                            'display_order' => 2,
                            'is_active' => 1,
                        ],
                        [
                            'code' => 'Z',
                            'start_time' => '09:00',
                            'end_time' => '12:00',
                            'ends_next_day' => 0,
                            'display_order' => 20,
                            'is_active' => 1,
                        ],
                    ],
                ],
            )
            ->assertRedirect();

        $this->assertDatabaseHas('store_shift_patterns', [
            'id' => $pattern->getKey(),
            'start_time' => '07:00',
            'end_time' => '19:00',
            'start_day_offset' => 0,
            'end_day_offset' => 1,
            'display_order' => 2,
            'is_active' => true,
            'work_minutes' => $workMinutes,
        ]);
        $this->assertDatabaseHas('store_shift_patterns', [
            'store_id' => $this->daianji->getKey(),
            'code' => 'Z',
            'work_minutes' => 0,
            'is_active' => true,
        ]);
        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );
    }

    public function test_staffing_mode_and_pattern_combination_are_updated_in_related_tables(): void
    {
        $pattern = StoreShiftPattern::query()
            ->where('store_id', $this->daianji->getKey())
            ->where('code', 'C')
            ->firstOrFail();
        $requirement = StoreStaffingRequirement::query()
            ->where('store_id', $this->daianji->getKey())
            ->with('options')
            ->firstOrFail();
        $option = $requirement->options->firstOrFail();
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');

        $this->actingAs($this->manager)
            ->patch(
                route('admin.stores.staffing.update', ['store' => 'daianji']),
                [
                    'staffing_check_mode' => 'pattern_combinations',
                    'required_staff_count' => null,
                    'staffing_options' => [
                        [
                            'id' => $option->getKey(),
                            'code' => $option->code,
                            'display_order' => 4,
                            'remove' => 0,
                            'pattern_counts' => [
                                $pattern->getKey() => 2,
                            ],
                        ],
                    ],
                ],
            )
            ->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'staffing_check_mode' => 'pattern_combinations',
            'required_staff_count' => null,
        ]);
        $this->assertDatabaseHas('store_staffing_requirement_options', [
            'id' => $option->getKey(),
            'display_order' => 4,
        ]);
        $this->assertDatabaseHas('store_staffing_requirement_option_patterns', [
            'store_staffing_requirement_option_id' => $option->getKey(),
            'store_shift_pattern_id' => $pattern->getKey(),
            'required_count' => 2,
        ]);

        $this->patch(
            route('admin.stores.staffing.update', ['store' => 'daianji']),
            [
                'staffing_check_mode' => 'fixed_total',
                'required_staff_count' => 3,
                'staffing_options' => [],
            ],
        )->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'staffing_check_mode' => 'fixed_total',
            'required_staff_count' => 3,
        ]);
        $this->assertDatabaseHas('store_staffing_requirement_option_patterns', [
            'store_staffing_requirement_option_id' => $option->getKey(),
            'required_count' => 2,
        ]);
        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame(
            $publishedBefore,
            $this->tableSnapshot('published_shifts'),
        );
    }

    public function test_inactive_store_related_settings_are_editable_but_shift_writes_and_publish_are_rejected(): void
    {
        $this->daianji->update(['status' => 'inactive']);
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

        $this->actingAs($this->manager)
            ->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk();
        $this->patch(route('admin.stores.update', ['store' => 'daianji']), [
            'name' => $this->daianji->name,
            'area' => '岡山中央',
        ])->assertRedirect();
        $inactiveStoreCandidate = $this->user('miyake@example.com');
        $this->post(
            route('admin.stores.staff.store', ['store' => 'daianji']),
            ['staff_user_id' => $inactiveStoreCandidate->getKey()],
        )->assertRedirect();
        $this->delete(route('admin.stores.staff.destroy', [
            'store' => 'daianji',
            'staff' => $inactiveStoreCandidate->getKey(),
        ]))->assertRedirect();

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

    /**
     * @return list<int>
     */
    private function activeStaffIds(Store $store): array
    {
        return DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->where('is_active', true)
            ->orderBy('display_order')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function assignManager(User $manager, Store $store): void
    {
        DB::table('store_shift_manager')->updateOrInsert(
            [
                'store_id' => $store->getKey(),
                'user_id' => $manager->getKey(),
            ],
            [
                'is_active' => true,
                'started_on' => null,
                'ended_on' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
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
                'area' => '別エリア',
                'status' => 'active',
                'display_order' => 1,
                'staffing_check_mode' => 'disabled',
                'required_staff_count' => null,
            ],
        );
    }
}
