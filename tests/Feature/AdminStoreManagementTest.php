<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use App\Support\WorkHours;
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
        $this->daianji->update(['area' => '岡山中央']);
        $this->noda->update(['area' => '岡山西']);
        $foreignStore = $this->foreignStore();

        $response = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.index'))
            ->assertOk()
            ->assertSee('name="manager_id"', false)
            ->assertSee('name="area"', false)
            ->assertSee('name="q"', false)
            ->assertSee('店舗追加')
            ->assertSee('<th scope="col">店舗名</th>', false)
            ->assertSee('<th scope="col">店舗コード</th>', false)
            ->assertSee('<th scope="col">エリア</th>', false)
            ->assertSee('<th scope="col">担当シフト管理者</th>', false)
            ->assertDontSee($foreignStore->name)
            ->assertDontSee('有効・無効')
            ->assertDontSee('<th scope="col">表示順</th>', false)
            ->assertDontSee('<th scope="col">人数チェック方式</th>', false);

        $this->assertSame(
            ['daianji', 'noda', 'okayama-tomida', 'saidaiji'],
            $response->viewData('stores')->pluck('code')->all(),
        );
    }

    public function test_manager_area_and_name_code_filters_include_multi_manager_store(): void
    {
        $this->daianji->update(['area' => '岡山中央']);
        $this->noda->update(['area' => '岡山西']);
        $this->store('okayama-tomida')->update(['area' => '岡山中央']);
        $this->assignManager($this->managerOnly, $this->daianji);

        foreach ([$this->manager, $this->managerOnly] as $manager) {
            $response = $this->actingAs($this->systemAdmin)
                ->get(route('admin.stores.index', [
                    'manager_id' => $manager->getKey(),
                ]))
                ->assertOk();
            $this->assertSame(
                ['daianji'],
                $response->viewData('stores')->pluck('code')->all(),
            );
        }

        $area = $this->get(route('admin.stores.index', [
            'area' => '岡山中央',
        ]))->assertOk();
        $this->assertSame(
            ['daianji', 'okayama-tomida'],
            $area->viewData('stores')->pluck('code')->all(),
        );

        $unset = $this->get(route('admin.stores.index', [
            'area' => '__unset__',
        ]))->assertOk();
        $this->assertSame(
            ['saidaiji'],
            $unset->viewData('stores')->pluck('code')->all(),
        );

        $name = $this->get(route('admin.stores.index', [
            'q' => '富田',
        ]))->assertOk();
        $this->assertSame(
            ['okayama-tomida'],
            $name->viewData('stores')->pluck('code')->all(),
        );

        $code = $this->get(route('admin.stores.index', [
            'q' => 'noda',
        ]))->assertOk();
        $this->assertSame(['noda'], $code->viewData('stores')->pluck('code')->all());
    }

    public function test_only_system_admin_can_create_store_with_trimmed_required_area(): void
    {
        $this->actingAs($this->systemAdmin)
            ->post(route('admin.stores.store'), [
                'name' => ' 新店舗 ',
                'code' => ' new-store ',
                'area' => ' 岡山北 ',
            ])
            ->assertRedirect(route('admin.stores.edit', [
                'store' => 'new-store',
            ]));

        $store = Store::query()->where('code', 'new-store')->firstOrFail();
        $this->assertSame('新店舗', $store->name);
        $this->assertSame('岡山北', $store->area);
        $this->assertSame('disabled', $store->staffing_check_mode);
        $this->assertNull($store->required_staff_count);

        $this->post(route('admin.stores.store'), [
            'name' => '重複',
            'code' => 'new-store',
            'area' => '岡山北',
        ])->assertSessionHasErrors('code');

        $this->post(route('admin.stores.store'), [
            'name' => '空エリア',
            'code' => 'blank-area',
            'area' => '  ',
        ])->assertSessionHasErrors('area');

        $this->actingAs($this->manager)
            ->post(route('admin.stores.store'), [
                'name' => '権限外',
                'code' => 'manager-created',
                'area' => '岡山北',
            ])
            ->assertForbidden();
    }

    public function test_detail_page_uses_one_form_one_save_and_compact_current_lists(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertOk()
            ->assertSee('data-store-detail-form', false)
            ->assertSee('data-store-save-button', false)
            ->assertSee('data-candidate-panel-toggle="staff"', false)
            ->assertSee('data-candidate-panel-toggle="manager"', false)
            ->assertSee('name="staff_user_ids[]"', false)
            ->assertSee('name="manager_user_ids[]"', false)
            ->assertSee('name="patterns[0][work_hours]"', false)
            ->assertSee('大月敦弘')
            ->assertDontSee('三宅由幸')
            ->assertDontSee('主所属')
            ->assertDontSee('有効・無効')
            ->assertDontSee('翌日終了');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-store-detail-form'));
        $this->assertSame(1, substr_count($html, 'data-store-save-button'));
        $this->assertStringContainsString(
            'window.addEventListener(\'beforeunload\'',
            file_get_contents(public_path('js/admin-store-management.js')),
        );
    }

    public function test_candidate_searches_use_name_email_and_exclude_selected_or_foreign_users(): void
    {
        $foreignStore = $this->foreignStore();
        $staffRoleId = DB::table('roles')->where('code', 'staff')->value('id');
        $foreignStaff = User::factory()->create([
            'organization_id' => $foreignStore->organization_id,
            'name' => '別組織検索対象',
            'email' => 'foreign-search@example.net',
            'status' => 'active',
        ]);
        DB::table('role_user')->insert([
            'user_id' => $foreignStaff->getKey(),
            'role_id' => $staffRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->systemAdmin)
            ->getJson(route('admin.stores.staff.candidates', [
                'store' => 'daianji',
                'q' => 'miyake@example.com',
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'miyake@example.com');

        $this->getJson(route('admin.stores.staff.candidates', [
            'store' => 'daianji',
            'q' => '大月',
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('admin.stores.staff.candidates', [
            'store' => 'daianji',
            'q' => '別組織検索対象',
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('admin.stores.manager.candidates', [
            'store' => 'daianji',
            'q' => '専用',
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'manager-only@example.com');

        $this->actingAs($this->manager)
            ->getJson(route('admin.stores.manager.candidates', [
                'store' => 'daianji',
                'q' => '専用',
            ]))
            ->assertForbidden();
    }

    public function test_single_save_updates_all_sections_and_keeps_shift_snapshots(): void
    {
        $miyake = $this->user('miyake@example.com');
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');
        $payload = $this->detailsPayload($this->daianji);
        $payload['name'] = ' 大安寺 更新 ';
        $payload['area'] = ' 岡山中央 ';
        $payload['staff_user_ids'][] = $miyake->getKey();
        $payload['manager_user_ids'][] = $this->managerOnly->getKey();
        $payload['patterns'][0]['start_time'] = '09:00';
        $payload['patterns'][0]['end_time'] = '10:00';
        $payload['patterns'][0]['work_hours'] = '11.25';
        $payload['staffing_check_mode'] = 'fixed_total';
        $payload['required_staff_count'] = 3;

        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', ['store' => 'daianji']),
                $payload,
            )
            ->assertRedirect(route('admin.stores.edit', [
                'store' => 'daianji',
            ]));

        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'name' => '大安寺 更新',
            'area' => '岡山中央',
            'staffing_check_mode' => 'fixed_total',
            'required_staff_count' => 3,
        ]);
        $this->assertDatabaseHas('store_user', [
            'store_id' => $this->daianji->getKey(),
            'user_id' => $miyake->getKey(),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('store_shift_manager', [
            'store_id' => $this->daianji->getKey(),
            'user_id' => $this->managerOnly->getKey(),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('store_shift_patterns', [
            'id' => $payload['patterns'][0]['id'],
            'start_time' => '09:00',
            'end_time' => '10:00',
            'work_hours' => 11.25,
        ]);
        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame($publishedBefore, $this->tableSnapshot('published_shifts'));
    }

    public function test_work_hours_is_direct_decimal_and_new_shift_copies_pattern_snapshot(): void
    {
        $payload = $this->detailsPayload($this->daianji);
        $payload['patterns'][] = [
            'code' => 'Z',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'work_hours' => '11.25',
        ];

        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.stores.update', ['store' => 'daianji']), $payload)
            ->assertRedirect();

        $pattern = StoreShiftPattern::query()
            ->where('store_id', $this->daianji->getKey())
            ->where('code', 'Z')
            ->firstOrFail();
        $this->assertSame('11.25', $pattern->work_hours);

        $schedule = $this->daianji->shiftSchedules()
            ->whereDate('target_month', '2026-07-01')
            ->firstOrFail();
        $user = $this->user('otsuki@example.com');

        $this->actingAs($this->manager)
            ->postJson(route('admin.shifts.store', [
                'store' => 'daianji',
            ]), [
                'target_month' => '2026-07',
                'expected_draft_version' => $schedule->draft_version,
                'user_id' => $user->getKey(),
                'work_date' => '2026-07-06',
                'shift_pattern_id' => $pattern->getKey(),
                'entry_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('work_hours', '11.25');

        $shift = $schedule->shifts()
            ->where('user_id', $user->getKey())
            ->whereDate('work_date', '2026-07-06')
            ->firstOrFail();
        $this->assertSame('11.25', $shift->work_hours);
    }

    public function test_multiple_managers_and_staffing_combinations_are_saved(): void
    {
        $payload = $this->detailsPayload($this->daianji);
        $payload['manager_user_ids'] = [
            $this->manager->getKey(),
            $this->managerOnly->getKey(),
        ];
        $payload['staffing_check_mode'] = 'pattern_combinations';
        $payload['required_staff_count'] = null;
        $option = $payload['staffing_options'][0];
        $patternId = (int) array_key_first($option['pattern_counts']);
        $option['display_order'] = 9;
        $option['pattern_counts'] = [$patternId => 2];
        $payload['staffing_options'] = [$option];

        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.stores.update', ['store' => 'daianji']), $payload)
            ->assertRedirect();

        $this->assertSame(
            2,
            DB::table('store_shift_manager')
                ->where('store_id', $this->daianji->getKey())
                ->where('is_active', true)
                ->count(),
        );
        $this->assertDatabaseHas('store_staffing_requirement_options', [
            'id' => $option['id'],
            'display_order' => 9,
        ]);
        $this->assertDatabaseHas(
            'store_staffing_requirement_option_patterns',
            [
                'store_staffing_requirement_option_id' => $option['id'],
                'store_shift_pattern_id' => $patternId,
                'required_count' => 2,
            ],
        );
    }

    public function test_pattern_release_and_staffing_relation_are_saved_together(): void
    {
        $pattern = StoreShiftPattern::query()
            ->where('store_id', $this->daianji->getKey())
            ->where('code', 'C')
            ->firstOrFail();
        $payload = $this->detailsPayload($this->daianji);
        $payload['patterns'] = array_values(array_filter(
            $payload['patterns'],
            fn (array $row): bool => (int) $row['id'] !== (int) $pattern->getKey(),
        ));

        foreach ($payload['staffing_options'] as &$option) {
            unset($option['pattern_counts'][$pattern->getKey()]);
        }
        unset($option);

        $this->actingAs($this->systemAdmin)
            ->patch(route('admin.stores.update', ['store' => 'daianji']), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('store_shift_patterns', [
            'id' => $pattern->getKey(),
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing(
            'store_staffing_requirement_option_patterns',
            ['store_shift_pattern_id' => $pattern->getKey()],
        );
    }

    public function test_shift_manager_can_update_assigned_store_but_not_managers_or_other_store(): void
    {
        $payload = $this->detailsPayload($this->daianji, false);
        $payload['name'] = '担当者更新';

        $this->actingAs($this->manager)
            ->patch(route('admin.stores.update', ['store' => 'daianji']), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('stores', [
            'id' => $this->daianji->getKey(),
            'name' => '担当者更新',
        ]);

        $payload['manager_user_ids'] = [$this->managerOnly->getKey()];
        $this->patch(
            route('admin.stores.update', ['store' => 'daianji']),
            $payload,
        )->assertSessionHasErrors('manager_user_ids');

        $this->get(route('admin.stores.edit', ['store' => 'noda']))
            ->assertForbidden();
        $this->patch(
            route('admin.stores.update', ['store' => 'noda']),
            $this->detailsPayload($this->noda, false),
        )->assertForbidden();
    }

    public function test_foreign_organization_store_is_not_visible_or_updatable(): void
    {
        $foreignStore = $this->foreignStore();

        $this->actingAs($this->systemAdmin)
            ->get(route('admin.stores.index'))
            ->assertOk()
            ->assertDontSee($foreignStore->name);
        $this->get(route('admin.stores.edit', [
            'store' => $foreignStore->code,
        ]))->assertForbidden();
        $this->patch(route('admin.stores.update', [
            'store' => $foreignStore->code,
        ]), $this->detailsPayload($foreignStore))->assertForbidden();
    }

    public function test_membership_release_preserves_and_keeps_existing_draft_and_public_rows_visible(): void
    {
        $staff = $this->user('staff@example.com');
        $draftBefore = $this->tableSnapshot('shifts');
        $publishedBefore = $this->tableSnapshot('published_shifts');

        $daianjiPayload = $this->detailsPayload($this->daianji);
        $daianjiPayload['staff_user_ids'] = array_values(array_filter(
            $daianjiPayload['staff_user_ids'],
            fn (int $id): bool => $id !== (int) $staff->getKey(),
        ));
        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', ['store' => 'daianji']),
                $daianjiPayload,
            )
            ->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('admin.shifts.stores.show', [
                'store' => 'daianji',
                'month' => '2026-07',
            ]))
            ->assertOk()
            ->assertSee('近澤幸次');

        $nodaPayload = $this->detailsPayload($this->noda);
        $nodaPayload['staff_user_ids'] = array_values(array_filter(
            $nodaPayload['staff_user_ids'],
            fn (int $id): bool => $id !== (int) $staff->getKey(),
        ));
        $this->actingAs($this->systemAdmin)
            ->patch(
                route('admin.stores.update', ['store' => 'noda']),
                $nodaPayload,
            )
            ->assertRedirect();

        $this->actingAs($this->user('miyake@example.com'))
            ->get(route('staff.store', [
                'store' => 'noda',
                'month' => '2026-07',
            ]))
            ->assertOk()
            ->assertSee('近澤幸次');

        $this->assertSame($draftBefore, $this->tableSnapshot('shifts'));
        $this->assertSame($publishedBefore, $this->tableSnapshot('published_shifts'));
    }

    public function test_late_validation_failure_rolls_back_all_store_sections(): void
    {
        $foreignPattern = StoreShiftPattern::query()
            ->where('store_id', $this->noda->getKey())
            ->firstOrFail();
        $storeBefore = $this->tableSnapshot('stores');
        $staffBefore = $this->tableSnapshot('store_user');
        $managerBefore = $this->tableSnapshot('store_shift_manager');
        $patternsBefore = $this->tableSnapshot('store_shift_patterns');
        $payload = $this->detailsPayload($this->daianji);
        $payload['name'] = 'ロールバック対象';
        $payload['staff_user_ids'][] = $this->user('miyake@example.com')->getKey();
        $payload['patterns'][0]['id'] = $foreignPattern->getKey();

        $this->actingAs($this->systemAdmin)
            ->from(route('admin.stores.edit', ['store' => 'daianji']))
            ->patch(route('admin.stores.update', ['store' => 'daianji']), $payload)
            ->assertRedirect(route('admin.stores.edit', ['store' => 'daianji']))
            ->assertSessionHasErrors('patterns.0.id');

        $this->assertSame($storeBefore, $this->tableSnapshot('stores'));
        $this->assertSame($staffBefore, $this->tableSnapshot('store_user'));
        $this->assertSame($managerBefore, $this->tableSnapshot('store_shift_manager'));
        $this->assertSame($patternsBefore, $this->tableSnapshot('store_shift_patterns'));
    }

    public function test_validation_failure_keeps_input_from_all_store_sections(): void
    {
        $miyake = $this->user('miyake@example.com');
        $payload = $this->detailsPayload($this->daianji);
        $payload['name'] = '入力保持中の店舗名';
        $payload['area'] = '入力保持中のエリア';
        $payload['staff_user_ids'][] = $miyake->getKey();
        $payload['manager_user_ids'][] = $this->managerOnly->getKey();
        $payload['patterns'][0]['work_hours'] = '10000';
        $editUrl = route('admin.stores.edit', ['store' => 'daianji']);

        $this->actingAs($this->systemAdmin)
            ->from($editUrl)
            ->patch(
                route('admin.stores.update', ['store' => 'daianji']),
                $payload,
            )
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('patterns.0.work_hours');

        $this->get($editUrl)
            ->assertOk()
            ->assertSee('value="入力保持中の店舗名"', false)
            ->assertSee('value="入力保持中のエリア"', false)
            ->assertSee($miyake->email)
            ->assertSee($this->managerOnly->email)
            ->assertSee('value="10000"', false);
    }

    public function test_store_management_requires_admin_access(): void
    {
        $this->get(route('admin.stores.index'))->assertRedirect(route('login'));

        $this->actingAs($this->user('staff@example.com'))
            ->get(route('admin.stores.index'))
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsPayload(Store $store, bool $includeManagers = true): array
    {
        $store->refresh();
        $today = CarbonImmutable::today('Asia/Tokyo')->toDateString();
        $patterns = StoreShiftPattern::query()
            ->where('store_id', $store->getKey())
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        $requirement = $store->staffingRequirements()
            ->whereNull('work_date')
            ->whereNull('weekday')
            ->where('is_active', true)
            ->with('options.patterns')
            ->first();
        $staffingOptions = $requirement?->options
            ->map(fn ($option): array => [
                'id' => $option->getKey(),
                'code' => $option->code,
                'display_order' => $option->display_order,
                'remove' => 0,
                'pattern_counts' => $option->patterns
                    ->mapWithKeys(fn ($pattern): array => [
                        $pattern->store_shift_pattern_id => $pattern->required_count,
                    ])
                    ->all(),
            ])
            ->all() ?? [];
        $payload = [
            'name' => $store->name,
            'area' => $store->area,
            'staff_user_ids' => DB::table('store_user')
                ->where('store_id', $store->getKey())
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('started_on')
                    ->orWhereDate('started_on', '<=', $today))
                ->where(fn ($query) => $query
                    ->whereNull('ended_on')
                    ->orWhereDate('ended_on', '>=', $today))
                ->orderBy('display_order')
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'patterns' => $patterns
                ->map(fn (StoreShiftPattern $pattern): array => [
                    'id' => $pattern->getKey(),
                    'code' => $pattern->code,
                    'start_time' => $pattern->start_time
                        ? substr((string) $pattern->start_time, 0, 5)
                        : null,
                    'end_time' => $pattern->end_time
                        ? substr((string) $pattern->end_time, 0, 5)
                        : null,
                    'work_hours' => WorkHours::format($pattern->work_hours),
                ])
                ->all(),
            'staffing_check_mode' => $store->staffing_check_mode,
            'required_staff_count' => $store->required_staff_count,
            'staffing_options' => $staffingOptions,
        ];

        if ($includeManagers) {
            $payload['manager_user_ids'] = DB::table('store_shift_manager')
                ->where('store_id', $store->getKey())
                ->where('is_active', true)
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $payload;
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
            ['name' => '別組織', 'is_active' => true],
        );

        return Store::query()->firstOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'code' => 'foreign-store',
            ],
            [
                'name' => '別組織店舗',
                'area' => '別エリア',
                'display_order' => 1,
                'staffing_check_mode' => 'disabled',
                'required_staff_count' => null,
            ],
        );
    }
}
