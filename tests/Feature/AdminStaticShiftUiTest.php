<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Tests\TestCase;

class AdminStaticShiftUiTest extends TestCase
{
    public function test_admin_top_displays_the_store_shift_ui_with_static_controls(): void
    {
        $response = $this->get('/admin?month=2026-07');

        $response
            ->assertOk()
            ->assertSee('店舗別シフト編集')
            ->assertSee('大安寺')
            ->assertSee('月間計')
            ->assertSee('シフト配布')
            ->assertSee('admin-shift.css')
            ->assertDontSee('staff-shift.css')
            ->assertSee('name="year"', false)
            ->assertSee('name="month_number"', false);
    }

    public function test_admin_staff_shift_ui_is_read_only(): void
    {
        $response = $this->get('/admin/shifts/staff/chikazawa?month=2026-07');

        $response
            ->assertOk()
            ->assertSee('スタッフ別シフト確認')
            ->assertSee('近澤幸次')
            ->assertSee('閲覧専用')
            ->assertDontSee('data-static-shift-mode', false)
            ->assertSee('data-shift-source="draft"', false);
    }

    public function test_ng_state_is_a_state_of_each_admin_shift_screen(): void
    {
        $storeResponse = $this->get('/admin/shifts/stores/daianji?month=2026-07&state=ng');
        $staffResponse = $this->get('/admin/shifts/staff/chikazawa?month=2026-07&state=ng');

        $storeResponse
            ->assertOk()
            ->assertSee('重複勤務があります')
            ->assertSee('修正が必要・配布不可');

        $staffResponse
            ->assertOk()
            ->assertSee('西大寺と大安寺の勤務が重複しています')
            ->assertSee('修正が必要・配布不可');
    }

    public function test_unknown_static_context_returns_not_found(): void
    {
        $this->get('/admin/shifts/stores/unknown?month=2026-07')->assertNotFound();
        $this->get('/admin/shifts/staff/unknown?month=2026-07')->assertNotFound();
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
