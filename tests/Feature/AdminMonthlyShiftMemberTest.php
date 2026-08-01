<?php

namespace Tests\Feature;

use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMonthlyShiftMemberTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private User $manager;

    private User $admin;

    private User $staff;

    private User $excludedStaff;

    private StoreShiftPattern $pattern;

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
        $this->excludedStaff = User::query()
            ->where('email', 'oai@example.com')
            ->firstOrFail();
        $this->pattern = StoreShiftPattern::query()
            ->where('store_id', $this->store->getKey())
            ->where('code', 'C')
            ->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_initializes_once_and_keeps_each_month_independent(): void
    {
        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-08'))
            ->assertOk()
            ->assertSee('月次表示スタッフ管理');

        $august = $this->schedule('2026-08-01');
        $this->assertNotNull($august->monthly_members_initialized_at);
        $this->assertSame(0, (int) $august->monthly_members_version);
        $this->assertSame(5, $august->scheduleUsers()->count());

        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-08'))
            ->assertOk();
        $this->assertSame(5, $august->scheduleUsers()->count());

        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-09'))
            ->assertOk();
        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-10'))
            ->assertOk();

        $this->assertSame(5, $this->schedule('2026-09-01')->scheduleUsers()->count());
        $this->assertSame(5, $this->schedule('2026-10-01')->scheduleUsers()->count());

        $this->actingAs($this->manager)
            ->delete($this->removeUrl('2026-09', $this->excludedStaff), [
                'target_month' => '2026-09',
                'expected_monthly_members_version' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('monthly_members_version', 1);

        $this->assertDatabaseMissing('shift_schedule_users', [
            'shift_schedule_id' => $this->schedule('2026-09-01')->getKey(),
            'user_id' => $this->excludedStaff->getKey(),
        ]);
        $this->assertDatabaseHas('shift_schedule_users', [
            'shift_schedule_id' => $this->schedule('2026-08-01')->getKey(),
            'user_id' => $this->excludedStaff->getKey(),
        ]);
        $this->assertDatabaseHas('shift_schedule_users', [
            'shift_schedule_id' => $this->schedule('2026-10-01')->getKey(),
            'user_id' => $this->excludedStaff->getKey(),
        ]);
    }

    public function test_excluding_existing_shift_keeps_row_but_blocks_new_shift(): void
    {
        $created = $this->actingAs($this->manager)
            ->postJson($this->shiftUrl(), $this->shiftPayload())
            ->assertCreated()
            ->json();
        $schedule = $this->schedule('2026-08-01');

        $this->actingAs($this->manager)
            ->delete($this->removeUrl('2026-08', $this->staff), [
                'target_month' => '2026-08',
                'expected_monthly_members_version' => 0,
            ])
            ->assertOk();

        $this->assertDatabaseHas('shifts', [
            'id' => $created['shift_id'],
            'user_id' => $this->staff->getKey(),
        ]);
        $this->assertDatabaseMissing('shift_schedule_users', [
            'shift_schedule_id' => $schedule->getKey(),
            'user_id' => $this->staff->getKey(),
        ]);

        $this->actingAs($this->manager)
            ->get($this->storeUrl('2026-08'))
            ->assertOk()
            ->assertSee($this->staff->name);

        $this->actingAs($this->manager)
            ->postJson($this->shiftUrl(), [
                ...$this->shiftPayload(),
                'entry_uuid' => (string) Str::uuid(),
                'work_date' => '2026-08-11',
                'expected_draft_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_reorder_uses_monthly_members_version_and_rejects_stale_request(): void
    {
        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-08'))
            ->assertOk();
        $schedule = $this->schedule('2026-08-01');
        $ids = $schedule->scheduleUsers()
            ->orderBy('display_order')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->manager)
            ->patchJson($this->reorderUrl('2026-08'), [
                'target_month' => '2026-08',
                'user_ids' => $reversed,
                'expected_monthly_members_version' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('monthly_members_version', 1);

        $this->actingAs($this->manager)
            ->patchJson($this->reorderUrl('2026-08'), [
                'target_month' => '2026-08',
                'user_ids' => $ids,
                'expected_monthly_members_version' => 0,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'monthly_members_version_conflict');
    }

    public function test_monthly_member_authorization_is_separate_from_store_membership(): void
    {
        $this->get($this->membersUrl('2026-08'))->assertRedirectToRoute('login');

        $staffOnly = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();
        $this->actingAs($staffOnly)
            ->get($this->membersUrl('2026-08'))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-08'))
            ->assertOk();

        $this->actingAs($this->manager)
            ->get($this->membersUrl('2026-08', $this->otherStore))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get($this->membersUrl('2026-08', $this->otherStore))
            ->assertOk();
    }

    private function schedule(string $targetMonth): ShiftSchedule
    {
        return ShiftSchedule::query()
            ->where('store_id', $this->store->getKey())
            ->whereDate('target_month', $targetMonth)
            ->firstOrFail();
    }

    private function membersUrl(string $month, ?Store $store = null): string
    {
        return route('admin.shifts.members', [
            'store' => ($store ?? $this->store)->code,
            'month' => $month,
        ]);
    }

    private function removeUrl(string $month, User $user): string
    {
        return route('admin.shifts.members.remove', [
            'store' => $this->store->code,
            'user' => $user->getKey(),
            'month' => $month,
        ]);
    }

    private function reorderUrl(string $month): string
    {
        return route('admin.shifts.members.reorder', [
            'store' => $this->store->code,
            'month' => $month,
        ]);
    }

    private function storeUrl(string $month): string
    {
        return route('admin.shifts.stores.show', [
            'store' => $this->store->code,
            'month' => $month,
        ]);
    }

    private function shiftUrl(): string
    {
        return route('admin.shifts.store', ['store' => $this->store->code]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftPayload(): array
    {
        return [
            'target_month' => '2026-08',
            'expected_draft_version' => 0,
            'user_id' => $this->staff->getKey(),
            'work_date' => '2026-08-10',
            'shift_pattern_id' => $this->pattern->getKey(),
            'entry_uuid' => (string) Str::uuid(),
        ];
    }
}
