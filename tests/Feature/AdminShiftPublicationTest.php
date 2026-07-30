<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PublishedShift;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use App\Services\Admin\AdminShiftPublishService;
use App\Services\Admin\AdminShiftScheduleWriter;
use App\Services\Admin\DraftShiftWarningService;
use App\Services\Admin\PublishedShiftSnapshotWriter;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminShiftPublicationTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_MONTH = '2026-09';

    private Store $store;

    private User $manager;

    private User $staff;

    private ShiftSchedule $schedule;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);

        $this->store = Store::query()->where('code', 'daianji')->firstOrFail();
        $this->store->forceFill(['staffing_check_mode' => 'disabled'])->save();
        $this->manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();
        $this->staff = User::query()
            ->where('email', 'otsuki@example.com')
            ->firstOrFail();
        $pattern = $this->pattern('C');
        $this->schedule = ShiftSchedule::query()->create([
            'store_id' => $this->store->getKey(),
            'target_month' => self::TARGET_MONTH.'-01',
            'draft_version' => 1,
            'shift_updated_at' => now(),
            'created_by' => $this->manager->getKey(),
            'updated_by' => $this->manager->getKey(),
        ]);
        $this->shift = Shift::query()->create([
            'shift_schedule_id' => $this->schedule->getKey(),
            'user_id' => $this->staff->getKey(),
            'work_date' => self::TARGET_MONTH.'-10',
            'store_shift_pattern_id' => $pattern->getKey(),
            'sequence' => 1,
            'entry_uuid' => (string) Str::uuid(),
            'pattern_code' => $pattern->code,
            'work_minutes' => $pattern->work_minutes,
            'created_by' => $this->manager->getKey(),
            'updated_by' => $this->manager->getKey(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_publish_replaces_only_target_snapshot_and_updates_metadata(): void
    {
        $foreignSchedule = $this->createForeignPublishedSchedule();
        $draftBefore = $this->draftSnapshot();
        $otherPublishedBefore = $this->publishedSnapshotExceptTarget();

        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertOk()
            ->assertJson([
                'published' => true,
                'idempotent' => false,
                'shift_schedule_id' => $this->schedule->getKey(),
                'draft_version' => 1,
                'published_version' => 1,
                'published_draft_version' => 1,
                'published_by_user_id' => $this->manager->getKey(),
                'published_shift_count' => 1,
            ])
            ->assertJsonPath('warning_result.can_publish', true);

        $this->assertSame($draftBefore, $this->draftSnapshot());
        $this->assertSame($otherPublishedBefore, $this->publishedSnapshotExceptTarget());
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $this->schedule->getKey(),
            'user_id' => $this->staff->getKey(),
            'work_date' => self::TARGET_MONTH.'-10',
            'sequence' => 1,
            'pattern_code' => 'C',
            'work_minutes' => $this->shift->work_minutes,
            'published_at' => '2026-07-30 12:00:00',
        ]);
        $this->assertDatabaseHas('shift_schedules', [
            'id' => $this->schedule->getKey(),
            'draft_version' => 1,
            'published_version' => 1,
            'published_draft_version' => 1,
            'published_at' => '2026-07-30 12:00:00',
            'published_by_user_id' => $this->manager->getKey(),
        ]);
        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $foreignSchedule->getKey(),
            'pattern_code' => 'X',
        ]);
    }

    public function test_same_draft_is_idempotent_and_new_draft_increments_generation(): void
    {
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertOk();
        $firstPublished = $this->publishedSnapshot();
        $firstMetadata = $this->publicationMetadata();

        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertOk()
            ->assertJson([
                'idempotent' => true,
                'published_version' => 1,
                'published_draft_version' => 1,
            ]);

        $this->assertSame($firstPublished, $this->publishedSnapshot());
        $this->assertSame($firstMetadata, $this->publicationMetadata());

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 5, 0, 'Asia/Tokyo'),
        );
        $pattern = $this->pattern('D');
        $this->shift->forceFill([
            'store_shift_pattern_id' => $pattern->getKey(),
            'pattern_code' => $pattern->code,
            'work_minutes' => $pattern->work_minutes,
        ])->save();
        $this->schedule->forceFill(['draft_version' => 2])->save();

        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload(2))
            ->assertOk()
            ->assertJson([
                'idempotent' => false,
                'draft_version' => 2,
                'published_version' => 2,
                'published_draft_version' => 2,
                'published_shift_count' => 1,
            ]);

        $this->assertDatabaseHas('published_shifts', [
            'shift_schedule_id' => $this->schedule->getKey(),
            'pattern_code' => 'D',
            'work_minutes' => $pattern->work_minutes,
            'published_at' => '2026-07-30 12:05:00',
        ]);
        $this->assertNotSame($firstPublished, $this->publishedSnapshot());
    }

    public function test_blocking_warning_keeps_previous_publication_and_metadata(): void
    {
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertOk();
        $publishedBefore = $this->publishedSnapshot();
        $metadataBefore = $this->publicationMetadata();

        $this->store
            ->forceFill(['staffing_check_mode' => 'pattern_combinations'])
            ->save();
        $this->schedule->forceFill(['draft_version' => 2])->save();

        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload(2))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'shift_publication_blocked')
            ->assertJsonPath('warning_result.can_publish', false)
            ->assertJsonPath('warning_result.checked_draft_version', 2);

        $this->assertSame($publishedBefore, $this->publishedSnapshot());
        $this->assertSame($metadataBefore, $this->publicationMetadata());
    }

    public function test_snapshot_generation_failure_rolls_back_deleted_rows_and_metadata(): void
    {
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertOk();
        $publishedBefore = $this->publishedSnapshot();
        $metadataBefore = $this->publicationMetadata();
        $pattern = $this->pattern('D');

        $this->shift->forceFill([
            'store_shift_pattern_id' => $pattern->getKey(),
            'pattern_code' => $pattern->code,
            'work_minutes' => $pattern->work_minutes,
        ])->save();
        $this->schedule->forceFill(['draft_version' => 2])->save();

        $failingWriter = new class extends PublishedShiftSnapshotWriter
        {
            protected function insertRows(array $rows): void
            {
                throw new RuntimeException('simulated publication failure');
            }
        };
        $service = new AdminShiftPublishService(
            app(AdminShiftScheduleWriter::class),
            app(DraftShiftWarningService::class),
            $failingWriter,
        );

        try {
            $service->publish(
                $this->store,
                $this->manager,
                CarbonImmutable::parse(self::TARGET_MONTH.'-01'),
                2,
            );
            $this->fail('公開版生成失敗が送出されませんでした。');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated publication failure', $exception->getMessage());
        }

        $this->assertSame($publishedBefore, $this->publishedSnapshot());
        $this->assertSame($metadataBefore, $this->publicationMetadata());
    }

    public function test_authorization_validation_and_stale_version_reject_without_changes(): void
    {
        $publishedBefore = $this->allPublishedSnapshot();
        $staffOnly = User::query()
            ->where('email', 'staff@example.com')
            ->firstOrFail();

        $this->postJson($this->publishUrl(), $this->payload())
            ->assertUnauthorized();
        $this->actingAs($staffOnly)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertForbidden();
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), [
                ...$this->payload(),
                'published_version' => 99,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('published_version');
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload(0))
            ->assertConflict()
            ->assertJsonPath('error', 'draft_version_conflict');

        $foreignSchedule = $this->createForeignPublishedSchedule();
        $foreignStore = $foreignSchedule->store()->firstOrFail();
        $foreignBefore = $this->allPublishedSnapshot();

        $this->actingAs($this->manager)
            ->postJson(
                route('admin.shifts.publish', ['store' => $foreignStore->code]),
                $this->payload(),
            )
            ->assertForbidden();

        $this->assertSame($foreignBefore, $this->allPublishedSnapshot());
        $this->assertSame($publishedBefore, array_values(array_filter(
            $this->allPublishedSnapshot(),
            fn (array $row): bool => (int) $row['shift_schedule_id']
                !== (int) $foreignSchedule->getKey(),
        )));

        $this->store->forceFill(['status' => 'inactive'])->save();
        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertForbidden();
    }

    public function test_store_screen_exposes_publication_contract_without_staff_ui_mixing(): void
    {
        $storeResponse = $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month='.self::TARGET_MONTH);
        $staffResponse = $this->actingAs($this->staff)
            ->get('/staff/store/daianji?month='.self::TARGET_MONTH);

        $storeResponse
            ->assertOk()
            ->assertSee('data-publish-shifts-url=', false)
            ->assertSee('data-publication-state="unpublished"', false)
            ->assertSee('admin-shift-publication.js', false)
            ->assertSee('シフト配布');
        $staffResponse
            ->assertOk()
            ->assertDontSee('data-publish-shifts-url=', false)
            ->assertDontSee('admin-shift-publication.js', false)
            ->assertDontSee('data-shift-editor', false);

        $this->actingAs($this->manager)
            ->postJson($this->publishUrl(), $this->payload())
            ->assertOk();

        $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month='.self::TARGET_MONTH)
            ->assertOk()
            ->assertSee('data-publication-state="published"', false)
            ->assertSee('data-published-by-user-id="'.$this->manager->getKey().'"', false)
            ->assertSee('配布済み')
            ->assertSee('最終配布 7月30日 12:00');

        $this->schedule->forceFill(['draft_version' => 2])->save();

        $this->actingAs($this->manager)
            ->get('/admin/shifts/stores/daianji?month='.self::TARGET_MONTH)
            ->assertOk()
            ->assertSee('data-publication-state="requires_republish"', false)
            ->assertSee('再配布が必要')
            ->assertSee('再配布');
    }

    private function publishUrl(): string
    {
        return route('admin.shifts.publish', ['store' => $this->store->code]);
    }

    /**
     * @return array{target_month: string, expected_draft_version: int}
     */
    private function payload(int $expectedDraftVersion = 1): array
    {
        return [
            'target_month' => self::TARGET_MONTH,
            'expected_draft_version' => $expectedDraftVersion,
        ];
    }

    private function pattern(string $code): StoreShiftPattern
    {
        return StoreShiftPattern::query()
            ->where('store_id', $this->store->getKey())
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftSnapshot(): array
    {
        return DB::table('shifts')
            ->where('shift_schedule_id', $this->schedule->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedSnapshot(): array
    {
        return DB::table('published_shifts')
            ->where('shift_schedule_id', $this->schedule->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedSnapshotExceptTarget(): array
    {
        return DB::table('published_shifts')
            ->where('shift_schedule_id', '<>', $this->schedule->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allPublishedSnapshot(): array
    {
        return DB::table('published_shifts')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicationMetadata(): array
    {
        $schedule = DB::table('shift_schedules')
            ->where('id', $this->schedule->getKey())
            ->first([
                'published_version',
                'published_draft_version',
                'published_at',
                'published_by_user_id',
            ]);

        return (array) $schedule;
    }

    private function createForeignPublishedSchedule(): ShiftSchedule
    {
        $organization = Organization::query()->create([
            'name' => '別組織',
            'code' => 'foreign-organization',
            'is_active' => true,
        ]);
        $store = Store::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'foreign-store',
            'name' => '別組織店舗',
            'status' => 'active',
            'display_order' => 1,
            'staffing_check_mode' => 'disabled',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->getKey(),
            'primary_store_id' => $store->getKey(),
            'name' => '別組織スタッフ',
            'email' => 'foreign-staff@example.com',
            'password' => 'not-used-for-login',
            'status' => 'active',
        ]);
        $user->roles()->attach(
            Role::query()->where('code', 'staff')->firstOrFail()->getKey(),
        );
        $schedule = ShiftSchedule::query()->create([
            'store_id' => $store->getKey(),
            'target_month' => self::TARGET_MONTH.'-01',
            'draft_version' => 1,
            'published_version' => 1,
            'published_draft_version' => 1,
            'published_at' => '2026-07-29 10:00:00',
            'published_by_user_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
        PublishedShift::query()->create([
            'shift_schedule_id' => $schedule->getKey(),
            'user_id' => $user->getKey(),
            'work_date' => self::TARGET_MONTH.'-10',
            'sequence' => 1,
            'pattern_code' => 'X',
            'work_minutes' => 60,
            'published_at' => '2026-07-29 10:00:00',
        ]);

        return $schedule;
    }
}
