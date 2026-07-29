<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminShiftMutationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private User $manager;

    private User $admin;

    private User $staff;

    private StoreShiftPattern $patternC;

    private StoreShiftPattern $patternD;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);

        $this->store = Store::query()->where('code', 'daianji')->firstOrFail();
        $this->otherStore = Store::query()->where('code', 'noda')->firstOrFail();
        $this->manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();
        $this->admin = User::query()
            ->where('email', 'admin@example.com')
            ->firstOrFail();
        $this->staff = User::query()
            ->where('email', 'otsuki@example.com')
            ->firstOrFail();
        $this->patternC = $this->pattern($this->store, 'C');
        $this->patternD = $this->pattern($this->store, 'D');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_first_add_creates_schedule_and_uuid_replay_is_idempotent(): void
    {
        $entryUuid = (string) Str::uuid();
        $payload = $this->validPayload(['entry_uuid' => $entryUuid]);

        $this->assertDatabaseMissing('shift_schedules', [
            'store_id' => $this->store->getKey(),
            'target_month' => '2026-08-01',
        ]);

        $created = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $payload)
            ->assertCreated()
            ->assertJson([
                'entry_uuid' => $entryUuid,
                'sequence' => 1,
                'user_id' => $this->staff->getKey(),
                'shift_date' => '2026-08-10',
                'shift_pattern_id' => $this->patternC->getKey(),
                'pattern_code' => 'C',
                'work_minutes' => $this->patternC->work_minutes,
                'created' => true,
                'draft_version' => 1,
            ])
            ->json();

        $scheduleId = (int) $created['shift_schedule_id'];
        $shiftId = (int) $created['shift_id'];

        $this->assertDatabaseHas('shift_schedules', [
            'id' => $scheduleId,
            'store_id' => $this->store->getKey(),
            'target_month' => '2026-08-01',
            'draft_version' => 1,
            'created_by' => $this->manager->getKey(),
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'shift_schedule_id' => $scheduleId,
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-08-10 00:00:00',
            'store_shift_pattern_id' => $this->patternC->getKey(),
            'sequence' => 1,
            'entry_uuid' => $entryUuid,
            'pattern_code' => 'C',
            'work_minutes' => $this->patternC->work_minutes,
            'created_by' => $this->manager->getKey(),
            'updated_by' => $this->manager->getKey(),
        ]);

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $payload)
            ->assertOk()
            ->assertJson([
                'shift_id' => $shiftId,
                'shift_schedule_id' => $scheduleId,
                'entry_uuid' => $entryUuid,
                'sequence' => 1,
                'created' => false,
                'draft_version' => 1,
            ]);

        $this->assertSame(1, ShiftSchedule::query()
            ->where('store_id', $this->store->getKey())
            ->whereDate('target_month', '2026-08-01')
            ->count());
        $this->assertSame(1, Shift::query()->where('entry_uuid', $entryUuid)->count());
    }

    public function test_server_assigns_sequence_and_rejects_client_sequence(): void
    {
        $first = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('sequence', 1);
        $second = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => (string) Str::uuid(),
            ]))
            ->assertCreated()
            ->assertJsonPath('sequence', 2);

        $this->assertNotSame(
            $first->json('shift_id'),
            $second->json('shift_id'),
        );

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => (string) Str::uuid(),
                'work_date' => '2026-08-11',
                'sequence' => 99,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sequence');

        $this->assertDatabaseMissing('shifts', [
            'work_date' => '2026-08-11 00:00:00',
            'sequence' => 99,
        ]);
    }

    public function test_manager_admin_guest_and_staff_authorization_are_enforced(): void
    {
        $this->postJson($this->storeUrl(), $this->validPayload())
            ->assertUnauthorized();

        $staffOnly = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();
        $this->actingAs($staffOnly)
            ->postJson($this->storeUrl(), $this->validPayload())
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload())
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => (string) Str::uuid(),
                'work_date' => '2026-08-11',
            ]))
            ->assertCreated();

        $this->assertTrue(
            Gate::forUser($this->manager)->allows('editAdminShifts', $this->store),
        );
        $this->assertFalse(
            Gate::forUser($staffOnly)->allows('editAdminShifts', $this->store),
        );
    }

    public function test_store_organization_state_and_manager_period_are_enforced(): void
    {
        $this->actingAs($this->manager)
            ->postJson(
                $this->storeUrl($this->otherStore),
                $this->validPayload(),
            )
            ->assertForbidden();

        $foreignStore = $this->foreignStore();
        $this->actingAs($this->admin)
            ->postJson(
                $this->storeUrl($foreignStore),
                $this->validPayload(),
            )
            ->assertForbidden();

        $this->store->update(['status' => 'inactive']);
        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), $this->validPayload())
            ->assertForbidden();
        $this->store->update(['status' => 'active']);

        DB::table('store_shift_manager')
            ->where('store_id', $this->store->getKey())
            ->where('user_id', $this->manager->getKey())
            ->update(['ended_on' => '2026-07-29']);

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload())
            ->assertForbidden();
    }

    public function test_add_validates_staff_membership_role_and_membership_period(): void
    {
        $nonMember = User::query()
            ->where('email', 'miyake@example.com')
            ->firstOrFail();
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'user_id' => $nonMember->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $managerOnly = User::query()
            ->where('email', 'manager-only@example.com')
            ->firstOrFail();
        $managerOnly->stores()->syncWithoutDetaching([
            $this->store->getKey() => [
                'display_order' => 99,
                'is_active' => true,
                'started_on' => null,
                'ended_on' => null,
            ],
        ]);
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'user_id' => $managerOnly->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        DB::table('store_user')
            ->where('store_id', $this->store->getKey())
            ->where('user_id', $this->staff->getKey())
            ->update(['started_on' => '2026-08-11']);
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_explicit_staff_role_allows_manager_and_admin_accounts_to_work(): void
    {
        $managerShift = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'user_id' => $this->manager->getKey(),
                'work_date' => '2026-08-12',
            ]))
            ->assertCreated()
            ->assertJson([
                'user_id' => $this->manager->getKey(),
                'shift_date' => '2026-08-12',
                'sequence' => 1,
            ])
            ->json();

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl((int) $managerShift['shift_id']), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertOk()
            ->assertJson([
                'user_id' => $this->manager->getKey(),
                'shift_pattern_id' => $this->patternD->getKey(),
                'pattern_code' => 'D',
            ]);

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'user_id' => $this->admin->getKey(),
                'work_date' => '2026-08-13',
                'entry_uuid' => (string) Str::uuid(),
            ]))
            ->assertCreated()
            ->assertJson([
                'user_id' => $this->admin->getKey(),
                'shift_date' => '2026-08-13',
                'sequence' => 1,
            ]);
    }

    public function test_add_validates_pattern_month_uuid_and_server_managed_fields(): void
    {
        $otherPattern = $this->pattern($this->otherStore, 'C');
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'shift_pattern_id' => $otherPattern->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_pattern_id');

        $inactivePattern = $this->pattern($this->store, 'A');
        $this->assertFalse((bool) $inactivePattern->is_active);
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'shift_pattern_id' => $inactivePattern->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_pattern_id');

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'work_date' => '2026-09-01',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_date');

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'target_month' => '2026-11',
                'work_date' => '2026-11-01',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_month');

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => 'not-a-uuid',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => (string) Str::uuid(),
                'start_time' => '18:00',
                'end_time' => '09:00',
                'break_minutes' => -1,
                'memo' => 'not-supported',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'start_time',
                'end_time',
                'break_minutes',
                'memo',
            ]);

        $this->assertDatabaseMissing('shift_schedules', [
            'store_id' => $this->store->getKey(),
            'target_month' => '2026-08-01',
        ]);
    }

    public function test_uuid_cannot_be_reused_for_a_different_shift_identity(): void
    {
        $entryUuid = (string) Str::uuid();
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => $entryUuid,
            ]))
            ->assertCreated();

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => $entryUuid,
                'work_date' => '2026-08-11',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $otherStaff = User::query()
            ->where('email', 'fujimoto@example.com')
            ->firstOrFail();
        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => $entryUuid,
                'user_id' => $otherStaff->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload([
                'entry_uuid' => $entryUuid,
                'shift_pattern_id' => $this->patternD->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $otherStoreStaff = User::query()
            ->where('email', 'miyake@example.com')
            ->firstOrFail();
        $this->actingAs($this->admin)
            ->postJson($this->storeUrl($this->otherStore), [
                'target_month' => '2026-08',
                'user_id' => $otherStoreStaff->getKey(),
                'work_date' => '2026-08-10',
                'shift_pattern_id' => $this->pattern(
                    $this->otherStore,
                    'C',
                )->getKey(),
                'entry_uuid' => $entryUuid,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $this->assertSame(1, Shift::query()->where('entry_uuid', $entryUuid)->count());
    }

    public function test_allowed_shift_can_be_updated_with_normalized_pattern_values(): void
    {
        $publishedBefore = $this->publishedSnapshot();
        $created = $this->createShift();
        $shiftId = (int) $created['shift_id'];
        $scheduleVersion = (int) $created['draft_version'];

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertOk()
            ->assertJson([
                'shift_id' => $shiftId,
                'entry_uuid' => $created['entry_uuid'],
                'sequence' => 1,
                'user_id' => $this->staff->getKey(),
                'shift_date' => '2026-08-10',
                'shift_pattern_id' => $this->patternD->getKey(),
                'pattern_code' => 'D',
                'work_minutes' => $this->patternD->work_minutes,
                'draft_version' => $scheduleVersion + 1,
            ]);

        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'store_shift_pattern_id' => $this->patternD->getKey(),
            'pattern_code' => 'D',
            'work_minutes' => $this->patternD->work_minutes,
            'updated_by' => $this->manager->getKey(),
        ]);
        $this->assertSame($publishedBefore, $this->publishedSnapshot());
    }

    public function test_update_rejects_invalid_pattern_identity_scope_and_role(): void
    {
        $created = $this->createShift();
        $shiftId = (int) $created['shift_id'];
        $staffOnly = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();

        $this->actingAs($staffOnly)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-07',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->patchJson($this->shiftUrl($shiftId, $this->otherStore), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->pattern($this->otherStore, 'D')->getKey(),
            ])
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->pattern($this->store, 'A')->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_pattern_id');

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl(999999), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertNotFound();

        DB::table('store_user')
            ->where('store_id', $this->store->getKey())
            ->where('user_id', $this->staff->getKey())
            ->update(['started_on' => '2026-08-11']);
        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_delete_resequences_remaining_cell_shifts_and_keeps_schedule(): void
    {
        $publishedBefore = $this->publishedSnapshot();
        $first = $this->createShift();
        $second = $this->createShift([
            'entry_uuid' => (string) Str::uuid(),
        ]);
        $otherCell = $this->createShift([
            'entry_uuid' => (string) Str::uuid(),
            'work_date' => '2026-08-11',
        ]);

        $this->actingAs($this->manager)
            ->deleteJson($this->shiftUrl((int) $first['shift_id']), [
                'target_month' => '2026-08',
            ])
            ->assertOk()
            ->assertJson([
                'deleted_shift_id' => (int) $first['shift_id'],
                'entry_uuid' => $first['entry_uuid'],
                'shift_schedule_id' => (int) $first['shift_schedule_id'],
                'remaining_shifts' => [[
                    'shift_id' => (int) $second['shift_id'],
                    'entry_uuid' => $second['entry_uuid'],
                    'sequence' => 1,
                    'user_id' => $this->staff->getKey(),
                    'shift_date' => '2026-08-10',
                    'shift_pattern_id' => $this->patternC->getKey(),
                    'pattern_code' => 'C',
                    'work_minutes' => $this->patternC->work_minutes,
                ]],
            ]);

        $this->assertDatabaseMissing('shifts', ['id' => $first['shift_id']]);
        $this->assertDatabaseHas('shifts', [
            'id' => $second['shift_id'],
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('shifts', [
            'id' => $otherCell['shift_id'],
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('shift_schedules', [
            'id' => $first['shift_schedule_id'],
        ]);
        $this->assertSame($publishedBefore, $this->publishedSnapshot());
    }

    public function test_delete_rejects_wrong_store_role_and_missing_shift(): void
    {
        $created = $this->createShift();
        $shiftId = (int) $created['shift_id'];
        $staffOnly = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();

        $this->actingAs($staffOnly)
            ->deleteJson($this->shiftUrl($shiftId), ['target_month' => '2026-08'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->deleteJson(
                $this->shiftUrl($shiftId, $this->otherStore),
                ['target_month' => '2026-08'],
            )
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->deleteJson($this->shiftUrl(999999), ['target_month' => '2026-08'])
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->deleteJson($this->shiftUrl($shiftId), ['target_month' => '2026-07'])
            ->assertNotFound();

        $this->assertDatabaseHas('shifts', ['id' => $shiftId]);
    }

    public function test_shift_ids_from_another_organization_cannot_be_changed(): void
    {
        [$foreignStore, $foreignShift] = $this->foreignDraftShift();

        $this->actingAs($this->admin)
            ->patchJson($this->shiftUrl($foreignShift->getKey()), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->deleteJson(
                $this->shiftUrl($foreignShift->getKey()),
                ['target_month' => '2026-08'],
            )
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->patchJson(
                $this->shiftUrl($foreignShift->getKey(), $foreignStore),
                [
                    'target_month' => '2026-08',
                    'shift_pattern_id' => $foreignShift->store_shift_pattern_id,
                ],
            )
            ->assertForbidden();

        $this->assertDatabaseHas('shifts', ['id' => $foreignShift->getKey()]);
    }

    public function test_store_editor_contract_and_staff_confirmation_read_only_are_separate(): void
    {
        $storeResponse = $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month=2026-08');
        $staffResponse = $this->actingAs($this->manager)
            ->get("/admin/shifts/staff/{$this->staff->getKey()}?month=2026-08&store=daianji");

        $storeResponse
            ->assertOk()
            ->assertSee('data-shift-editor', false)
            ->assertSee('data-create-shift-url=', false)
            ->assertSee('data-shift-url-template=', false)
            ->assertSee('data-shift-editor-cell', false)
            ->assertSee('admin-shift-editor.js', false);

        $staffResponse
            ->assertOk()
            ->assertDontSee('data-shift-editor', false)
            ->assertDontSee('data-create-shift-url=', false)
            ->assertDontSee('data-shift-editor-cell', false)
            ->assertDontSee('admin-shift-editor.js', false);

        $script = file_get_contents(public_path('js/admin-shift-editor.js'));
        $navigationScript = file_get_contents(public_path('js/admin-shift-static.js'));

        $this->assertStringContainsString('const DEBOUNCE_MS = 700;', $script);
        $this->assertStringContainsString('crypto.randomUUID', $script);
        $this->assertStringContainsString("method: 'POST'", $script);
        $this->assertStringContainsString("method: 'PATCH'", $script);
        $this->assertStringContainsString("method: 'DELETE'", $script);
        $this->assertStringContainsString("'beforeunload'", $script);
        $this->assertStringContainsString('queue.hasUnsaved()', $script);
        $this->assertStringContainsString(
            "'admin-shift:autosave-flush-request'",
            $script,
        );
        $this->assertStringContainsString(
            "'admin-shift:autosave-state'",
            $navigationScript,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'target_month' => '2026-08',
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-08-10',
            'shift_pattern_id' => $this->patternC->getKey(),
            'entry_uuid' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createShift(array $overrides = []): array
    {
        return $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->validPayload($overrides))
            ->assertCreated()
            ->json();
    }

    private function storeUrl(?Store $store = null): string
    {
        return '/admin/shifts/stores/'.($store ?? $this->store)->code.'/shifts';
    }

    private function shiftUrl(int $shiftId, ?Store $store = null): string
    {
        return $this->storeUrl($store).'/'.$shiftId;
    }

    private function pattern(Store $store, string $code): StoreShiftPattern
    {
        return StoreShiftPattern::query()
            ->where('store_id', $store->getKey())
            ->where('code', $code)
            ->firstOrFail();
    }

    private function foreignStore(): Store
    {
        $organization = Organization::query()->create([
            'code' => 'foreign-company',
            'name' => '別組織',
            'is_active' => true,
        ]);

        return Store::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'foreign-store',
            'name' => '別組織店舗',
            'status' => 'active',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
            'required_staff_count' => null,
        ]);
    }

    /**
     * @return array{0: Store, 1: Shift}
     */
    private function foreignDraftShift(): array
    {
        $store = $this->foreignStore();
        $user = User::query()->create([
            'organization_id' => $store->organization_id,
            'primary_store_id' => $store->getKey(),
            'name' => '別組織スタッフ',
            'email' => 'foreign-staff@example.com',
            'password' => 'not-used-for-login',
            'status' => 'active',
        ]);
        $user->roles()->attach(
            Role::query()->where('code', 'staff')->firstOrFail()->getKey(),
        );
        $user->stores()->attach($store->getKey(), [
            'display_order' => 1,
            'is_active' => true,
            'started_on' => null,
            'ended_on' => null,
        ]);
        $pattern = StoreShiftPattern::query()->create([
            'store_id' => $store->getKey(),
            'code' => 'C',
            'work_minutes' => 450,
            'display_order' => 1,
            'is_active' => true,
        ]);
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $store->getKey(),
            'target_month' => '2026-08-01',
            'draft_version' => 1,
            'published_version' => null,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
        $shift = Shift::query()->create([
            'shift_schedule_id' => $schedule->getKey(),
            'user_id' => $user->getKey(),
            'work_date' => '2026-08-12',
            'store_shift_pattern_id' => $pattern->getKey(),
            'sequence' => 1,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => $pattern->code,
            'work_minutes' => $pattern->work_minutes,
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);

        return [$store, $shift];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedSnapshot(): array
    {
        return DB::table('published_shifts')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
