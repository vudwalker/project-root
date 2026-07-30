<?php

namespace Tests\Feature;

use App\Models\Organization;
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

class AdminShiftOptimisticLockTest extends TestCase
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
        $this->patternC = $this->pattern('C');
        $this->patternD = $this->pattern('D');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_get_exposes_current_or_initial_version_without_side_effects(): void
    {
        $before = $this->businessCounts();

        $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month=2026-08')
            ->assertOk()
            ->assertSee('data-draft-version="0"', false);

        $this->assertSame($before, $this->businessCounts());

        $created = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->createPayload(0))
            ->assertCreated()
            ->assertJsonPath('draft_version', 1)
            ->json();

        $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month=2026-08')
            ->assertOk()
            ->assertSee('data-draft-version="1"', false)
            ->assertSee(
                'data-shift-schedule-id="'.(int) $created['shift_schedule_id'].'"',
                false,
            );

        $this->assertSame(1, $this->draftVersion());
    }

    public function test_expected_version_is_required_for_all_mutations(): void
    {
        $withoutVersion = $this->createPayload(0);
        unset($withoutVersion['expected_draft_version']);

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $withoutVersion)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_draft_version');
        $this->assertSame(0, $this->draftVersion());

        $created = $this->createShift();
        $shiftId = (int) $created['shift_id'];

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_draft_version');

        $this->actingAs($this->manager)
            ->deleteJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_draft_version');

        $this->assertSame(1, $this->draftVersion());
        $this->assertDatabaseHas('shifts', ['id' => $shiftId]);
    }

    public function test_stale_add_update_and_delete_return_409_without_changes(): void
    {
        $publishedBefore = $this->publishedSnapshot();
        $created = $this->createShift();
        $shiftId = (int) $created['shift_id'];

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), $this->createPayload(0, [
                'entry_uuid' => (string) Str::uuid(),
                'work_date' => '2026-08-11',
            ]))
            ->assertStatus(409)
            ->assertJson([
                'error' => 'draft_version_conflict',
                'expected_draft_version' => 0,
                'current_draft_version' => 1,
                'reload_required' => true,
            ]);

        $this->assertSame(1, Shift::query()
            ->where('shift_schedule_id', $created['shift_schedule_id'])
            ->count());
        $this->assertSame(1, $this->draftVersion());

        $this->actingAs($this->admin)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternD->getKey(),
                'expected_draft_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('draft_version', 2)
            ->assertJsonPath('pattern_code', 'D');

        $shiftAfterAdmin = Shift::query()->findOrFail($shiftId)->only([
            'store_shift_pattern_id',
            'pattern_code',
            'work_hours',
            'updated_by',
        ]);

        $this->actingAs($this->manager)
            ->patchJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'shift_pattern_id' => $this->patternC->getKey(),
                'expected_draft_version' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('current_draft_version', 2);

        $this->assertSame(
            $shiftAfterAdmin,
            Shift::query()->findOrFail($shiftId)->only(array_keys($shiftAfterAdmin)),
        );

        $this->actingAs($this->manager)
            ->deleteJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'expected_draft_version' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('current_draft_version', 2);

        $this->assertDatabaseHas('shifts', ['id' => $shiftId]);
        $this->assertSame(2, $this->draftVersion());

        $this->actingAs($this->manager)
            ->deleteJson($this->shiftUrl($shiftId), [
                'target_month' => '2026-08',
                'expected_draft_version' => 2,
            ])
            ->assertOk()
            ->assertJson([
                'deleted_shift_id' => $shiftId,
                'entry_uuid' => $created['entry_uuid'],
                'draft_version' => 3,
            ]);

        $this->assertDatabaseMissing('shifts', ['id' => $shiftId]);
        $this->assertSame(3, $this->draftVersion());
        $this->assertSame($publishedBefore, $this->publishedSnapshot());
    }

    public function test_two_initial_screens_cannot_both_add_with_version_zero(): void
    {
        $firstPayload = $this->createPayload(0);
        $secondPayload = $this->createPayload(0, [
            'entry_uuid' => (string) Str::uuid(),
        ]);

        $first = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $firstPayload)
            ->assertCreated()
            ->assertJson([
                'sequence' => 1,
                'draft_version' => 1,
            ])
            ->json();

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), $secondPayload)
            ->assertStatus(409)
            ->assertJsonPath('current_draft_version', 1);

        $this->assertSame(1, ShiftSchedule::query()
            ->where('store_id', $this->store->getKey())
            ->whereDate('target_month', '2026-08-01')
            ->count());
        $this->assertSame(1, Shift::query()
            ->where('shift_schedule_id', $first['shift_schedule_id'])
            ->count());
        $this->assertDatabaseMissing('shifts', [
            'entry_uuid' => $secondPayload['entry_uuid'],
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), [
                ...$secondPayload,
                'expected_draft_version' => 1,
            ])
            ->assertCreated()
            ->assertJson([
                'sequence' => 2,
                'draft_version' => 2,
            ]);

        $this->assertEquals(
            [1, 2],
            Shift::query()
                ->where('shift_schedule_id', $first['shift_schedule_id'])
                ->where('user_id', $this->staff->getKey())
                ->whereDate('work_date', '2026-08-10')
                ->orderBy('sequence')
                ->pluck('sequence')
                ->all(),
        );
    }

    public function test_uuid_replay_is_idempotent_but_cannot_bypass_identity_validation(): void
    {
        $originalPayload = $this->createPayload(0);
        $original = $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $originalPayload)
            ->assertCreated()
            ->json();

        $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->createPayload(1, [
                'entry_uuid' => (string) Str::uuid(),
                'work_date' => '2026-08-11',
            ]))
            ->assertCreated()
            ->assertJsonPath('draft_version', 2);

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), $originalPayload)
            ->assertOk()
            ->assertJson([
                'shift_id' => (int) $original['shift_id'],
                'entry_uuid' => $originalPayload['entry_uuid'],
                'created' => false,
                'draft_version' => 2,
            ]);

        $this->assertSame(1, Shift::query()
            ->where('entry_uuid', $originalPayload['entry_uuid'])
            ->count());
        $this->assertSame(2, $this->draftVersion());

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), [
                ...$originalPayload,
                'work_date' => '2026-08-12',
                'expected_draft_version' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $this->actingAs($this->admin)
            ->postJson($this->storeUrl(), [
                ...$originalPayload,
                'shift_pattern_id' => $this->patternD->getKey(),
                'expected_draft_version' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_uuid');

        $this->assertSame(2, Shift::query()
            ->where('shift_schedule_id', $original['shift_schedule_id'])
            ->count());
        $this->assertSame(2, $this->draftVersion());
    }

    public function test_unauthorized_requests_do_not_receive_current_version(): void
    {
        $payload = $this->createPayload(99);

        $this->postJson($this->storeUrl(), $payload)
            ->assertUnauthorized()
            ->assertJsonMissingPath('current_draft_version');

        $staffOnly = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();
        $this->actingAs($staffOnly)
            ->postJson($this->storeUrl(), $payload)
            ->assertForbidden()
            ->assertJsonMissingPath('current_draft_version');

        $this->actingAs($this->manager)
            ->postJson(
                '/admin/shifts/stores/'.$this->otherStore->code.'/shifts',
                $payload,
            )
            ->assertForbidden()
            ->assertJsonMissingPath('current_draft_version');

        $foreignOrganization = Organization::query()->create([
            'code' => 'conflict-test-foreign',
            'name' => '競合テスト別組織',
            'is_active' => true,
        ]);
        $foreignStore = Store::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'code' => 'conflict-test-foreign-store',
            'name' => '競合テスト別組織店舗',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
            'required_staff_count' => null,
        ]);

        $this->actingAs($this->admin)
            ->postJson(
                '/admin/shifts/stores/'.$foreignStore->code.'/shifts',
                $payload,
            )
            ->assertForbidden()
            ->assertJsonMissingPath('current_draft_version');
    }

    public function test_editor_queue_and_read_only_screen_contracts_remain_separate(): void
    {
        $storeResponse = $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month=2026-08');
        $staffResponse = $this->actingAs($this->manager)
            ->get("/admin/shifts/staff/{$this->staff->getKey()}?month=2026-08&store=daianji");

        $storeResponse
            ->assertOk()
            ->assertSee('data-draft-version="0"', false)
            ->assertSee('data-admin-conflict-notice', false)
            ->assertSee('data-admin-conflict-reload', false)
            ->assertSee('admin-shift-editor.js', false);

        $staffResponse
            ->assertOk()
            ->assertDontSee('data-draft-version=', false)
            ->assertDontSee('data-admin-conflict-notice', false)
            ->assertDontSee('data-admin-conflict-reload', false)
            ->assertDontSee('admin-shift-editor.js', false)
            ->assertDontSee('expected_draft_version', false);

        $editorScript = file_get_contents(public_path('js/admin-shift-editor.js'));
        $autosaveScript = file_get_contents(public_path('js/admin-shift-autosave.js'));
        $navigationScript = file_get_contents(public_path('js/admin-shift-static.js'));

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($editorScript, 'expected_draft_version: draftVersion'),
        );
        $this->assertStringContainsString('let networkBusy = false;', $autosaveScript);
        $this->assertStringContainsString('let nextOperationId = 1;', $autosaveScript);
        $this->assertStringContainsString('lastAppliedOperationId', $autosaveScript);
        $this->assertStringContainsString('stopForConflict', $autosaveScript);
        $this->assertStringContainsString('queue.isStopped()', $editorScript);
        $this->assertStringContainsString('window.confirm(', $editorScript);
        $this->assertStringContainsString("saveState === 'conflict'", $navigationScript);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createPayload(
        int $expectedDraftVersion,
        array $overrides = [],
    ): array {
        return [
            'target_month' => '2026-08',
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-08-10',
            'shift_pattern_id' => $this->patternC->getKey(),
            'entry_uuid' => (string) Str::uuid(),
            'expected_draft_version' => $expectedDraftVersion,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createShift(): array
    {
        return $this->actingAs($this->manager)
            ->postJson($this->storeUrl(), $this->createPayload(0))
            ->assertCreated()
            ->json();
    }

    private function storeUrl(): string
    {
        return '/admin/shifts/stores/'.$this->store->code.'/shifts';
    }

    private function shiftUrl(int $shiftId): string
    {
        return $this->storeUrl().'/'.$shiftId;
    }

    private function pattern(string $code): StoreShiftPattern
    {
        return StoreShiftPattern::query()
            ->where('store_id', $this->store->getKey())
            ->where('code', $code)
            ->firstOrFail();
    }

    private function draftVersion(): int
    {
        return (int) (ShiftSchedule::query()
            ->where('store_id', $this->store->getKey())
            ->whereDate('target_month', '2026-08-01')
            ->value('draft_version') ?? 0);
    }

    /**
     * @return array{shift_schedules: int, shifts: int, published_shifts: int}
     */
    private function businessCounts(): array
    {
        return [
            'shift_schedules' => ShiftSchedule::query()->count(),
            'shifts' => Shift::query()->count(),
            'published_shifts' => DB::table('published_shifts')->count(),
        ];
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
