<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStaticShiftUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(
            User::query()->where('email', 'admin@example.com')->firstOrFail(),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_top_displays_the_store_shift_ui_with_static_controls(): void
    {
        $response = $this->get('/admin?month=2026-07');

        $response
            ->assertOk()
            ->assertSee('店舗別シフト編集')
            ->assertSee('システム管理者画面')
            ->assertSee('admin-shift-page--system-admin', false)
            ->assertSee('大安寺')
            ->assertSee('月間計')
            ->assertSee('シフト配布')
            ->assertSee('admin-shift.css')
            ->assertDontSee('staff-shift.css')
            ->assertSee('name="year"', false)
            ->assertSee('name="month_number"', false);

        $stylesheet = file_get_contents(public_path('css/admin-shift.css'));

        $this->assertStringContainsString(
            '--admin-store-grid-min-height: 220px;',
            $stylesheet,
        );
        $this->assertStringContainsString(
            '.admin-shift-grid-scroll:not(.admin-shift-grid-scroll--staff)',
            $stylesheet,
        );
        $this->assertStringContainsString(
            'body.admin-shift-page--system-admin',
            $stylesheet,
        );
        $this->assertStringContainsString(
            '--admin-page-bg: #eef6ff;',
            $stylesheet,
        );
    }

    public function test_shift_manager_keeps_the_yellow_admin_context(): void
    {
        $manager = User::query()
            ->where('email', 'manager@example.com')
            ->firstOrFail();

        $response = $this->actingAs($manager)
            ->get('/admin/shifts/stores/daianji?month=2026-07');

        $response
            ->assertOk()
            ->assertSee('シフト管理者画面')
            ->assertSee('admin-shift-page--shift-manager', false)
            ->assertDontSee('admin-shift-page--system-admin', false);
    }

    public function test_admin_staff_shift_ui_is_read_only(): void
    {
        $staff = User::query()->where('email', 'staff@example.com')->firstOrFail();
        $response = $this->get(
            "/admin/shifts/staff/{$staff->getKey()}?month=2026-07&store=daianji",
        );

        $response
            ->assertOk()
            ->assertSee('スタッフ別シフト確認')
            ->assertSee('近澤幸次')
            ->assertSee('閲覧専用')
            ->assertDontSee('data-static-shift-mode', false)
            ->assertSee('admin-shift-grid-scroll--staff', false)
            ->assertSee('data-shift-source="draft"', false);

        $stylesheet = file_get_contents(public_path('css/admin-shift.css'));

        $this->assertStringContainsString(
            '--admin-staff-grid-min-height: 220px;',
            $stylesheet,
        );
        $this->assertStringContainsString(
            '.admin-shift-grid-scroll--staff',
            $stylesheet,
        );
    }

    public function test_both_admin_shift_screens_show_database_derived_warnings(): void
    {
        $staff = User::query()->where('email', 'staff@example.com')->firstOrFail();
        $storeResponse = $this->get('/admin/shifts/stores/daianji?month=2026-07&state=ng');
        $staffResponse = $this->get(
            "/admin/shifts/staff/{$staff->getKey()}?month=2026-07&store=daianji&state=ng",
        );

        $storeResponse
            ->assertOk()
            ->assertSee('配布不可')
            ->assertSee('data-admin-warning-panel', false)
            ->assertSee('data-admin-warning-count', false)
            ->assertSee('cross_store_duplicate', false);

        $staffResponse
            ->assertOk()
            ->assertSee('配布不可')
            ->assertSee('data-admin-warning-panel', false)
            ->assertSee('data-admin-warning-count', false)
            ->assertSee('cross_store_duplicate', false);
    }

    public function test_unknown_static_context_returns_not_found(): void
    {
        $this->get('/admin/shifts/stores/unknown?month=2026-07')->assertNotFound();
        $this->get('/admin/shifts/staff/999999?month=2026-07&store=daianji')->assertNotFound();
    }

    public function test_admin_grid_generates_the_actual_dates_for_a_leap_year_february(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2027, 11, 15, 12, 0, 0, 'Asia/Tokyo'),
        );
        $response = $this->get('/admin?month=2028-02');
        CarbonImmutable::setTestNow();

        $response
            ->assertOk()
            ->assertSee('2028年2月')
            ->assertSee('data-shift-date="2028-02-29"', false)
            ->assertDontSee('data-shift-date="2028-02-30"', false);
    }
}
