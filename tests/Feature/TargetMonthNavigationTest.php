<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TargetMonthNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<array{path: string, query: array<string, string>}>
     */
    private array $screens;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([
            Role::query()->where('code', 'staff')->firstOrFail()->getKey(),
        ]);
        $admin->stores()->syncWithoutDetaching([
            Store::query()->where('code', 'daianji')->firstOrFail()->getKey() => [
                'display_order' => 99,
                'is_active' => true,
                'started_on' => null,
                'ended_on' => null,
            ],
        ]);
        $this->actingAs($admin);
        $staff = User::query()->where('email', 'staff@example.com')->firstOrFail();
        $this->screens = [
            ['path' => '/staff', 'query' => []],
            ['path' => '/staff/store/daianji', 'query' => []],
            ['path' => '/admin/shifts/stores/daianji', 'query' => []],
            [
                'path' => "/admin/shifts/staff/{$staff->getKey()}",
                'query' => ['store' => 'daianji'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_each_screen_redirects_to_a_canonical_current_month_url(): void
    {
        foreach ($this->screens as $screen) {
            $this->get($this->urlWithoutMonth($screen))
                ->assertRedirect($this->monthUrl($screen, '2026-07'));
        }
    }

    public function test_previous_next_and_current_month_urls_are_available_inside_the_window(): void
    {
        foreach ($this->screens as $screen) {
            $response = $this->get($this->monthUrl($screen, '2026-09'));

            $response
                ->assertOk()
                ->assertSee($this->monthUrl($screen, '2026-08'))
                ->assertSee($this->monthUrl($screen, '2026-10'))
                ->assertSee($this->monthUrl($screen, '2026-07'))
                ->assertSee('今月');
        }
    }

    public function test_previous_and_next_navigation_stop_at_the_selectable_boundaries(): void
    {
        foreach ($this->screens as $screen) {
            $this->get($this->monthUrl($screen, '2026-07'))
                ->assertOk()
                ->assertSee('data-month-boundary="minimum"', false)
                ->assertDontSee($this->monthUrl($screen, '2026-06'));

            $this->get($this->monthUrl($screen, '2026-10'))
                ->assertOk()
                ->assertSee('data-month-boundary="maximum"', false)
                ->assertDontSee($this->monthUrl($screen, '2026-11'));
        }
    }

    public function test_january_and_december_navigation_crosses_the_year_boundary(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 11, 15, 12, 0, 0, 'Asia/Tokyo'),
        );

        foreach ($this->screens as $screen) {
            $this->get($this->monthUrl($screen, '2026-12'))
                ->assertOk()
                ->assertSee($this->monthUrl($screen, '2026-11'))
                ->assertSee($this->monthUrl($screen, '2027-01'));

            $this->get($this->monthUrl($screen, '2027-01'))
                ->assertOk()
                ->assertSee($this->monthUrl($screen, '2026-12'))
                ->assertSee($this->monthUrl($screen, '2027-02'));
        }
    }

    public function test_direct_year_and_month_selection_redirects_to_the_canonical_url(): void
    {
        foreach ($this->screens as $screen) {
            $this->get($this->selectionUrl($screen, 2026, 10))
                ->assertRedirect($this->monthUrl($screen, '2026-10'));

            $this->get($this->monthUrl($screen, '2026-10'))
                ->assertOk()
                ->assertSee('2026年10月');
        }
    }

    public function test_target_month_is_always_resolved_as_the_first_day(): void
    {
        $request = Request::create('/staff', 'GET', [
            'year' => '2026',
            'month_number' => '10',
        ]);
        $targetMonth = app(TargetMonthService::class)->resolve($request);

        $this->assertSame('2026-10-01 00:00:00', $targetMonth['date']->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tokyo', $targetMonth['date']->timezoneName);
    }

    public function test_leap_year_february_is_rendered_correctly(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2027, 11, 15, 12, 0, 0, 'Asia/Tokyo'),
        );

        $this->get('/staff?month=2028-02')
            ->assertOk()
            ->assertSee('data-date="2028-02-29"', false)
            ->assertDontSee('data-date="2028-02-30"', false);

        $this->get('/admin/shifts/stores/daianji?month=2028-02')
            ->assertOk()
            ->assertSee('data-shift-date="2028-02-29"', false)
            ->assertDontSee('data-shift-date="2028-02-30"', false);
    }

    public function test_invalid_month_values_are_rejected_safely(): void
    {
        $invalidQueries = [
            'month=2026-00',
            'month=2026-13',
            'month=not-a-month',
            'year=2026&month_number=0',
            'year=2026&month_number=13',
            'year=text&month_number=7',
            'year=2026',
            'month_number=7',
            'month=2026-06',
            'month=2026-11',
            'year=2026&month_number=6',
            'year=2026&month_number=11',
            'month=1800-01',
            'month=2200-01',
        ];

        foreach ($invalidQueries as $query) {
            $this->get("/staff?{$query}")
                ->assertRedirect('/staff?month=2026-07');
        }
    }

    public function test_direct_url_keeps_the_same_target_month(): void
    {
        $this->get($this->monthUrl($this->screens[3], '2026-09'))
            ->assertOk()
            ->assertSee('2026年9月')
            ->assertSee('value="2026"', false)
            ->assertSee('value="9"', false);
    }

    public function test_months_before_system_start_and_four_months_ahead_are_rejected_on_every_screen(): void
    {
        foreach ($this->screens as $screen) {
            $this->get($this->monthUrl($screen, '2026-06'))
                ->assertRedirect($this->monthUrl($screen, '2026-07'));

            $this->get($this->monthUrl($screen, '2026-11'))
                ->assertRedirect($this->monthUrl($screen, '2026-07'));
        }
    }

    public function test_every_month_inside_the_window_remains_selectable_without_data_lookup(): void
    {
        foreach ($this->screens as $screen) {
            foreach (range(7, 10) as $month) {
                $this->get($this->monthUrl($screen, sprintf('2026-%02d', $month)))
                    ->assertOk();
            }
        }
    }

    public function test_staff_and_admin_data_sources_remain_separated_during_month_navigation(): void
    {
        $staff = $this->get('/staff?month=2026-08');
        $admin = $this->get('/admin/shifts/stores/daianji?month=2026-08');

        $staff
            ->assertOk()
            ->assertSee('data-shift-source="published"', false)
            ->assertSee('staff-shift.css')
            ->assertDontSee('data-shift-source="draft"', false)
            ->assertDontSee('admin-shift.css');

        $admin
            ->assertOk()
            ->assertSee('data-shift-source="draft"', false)
            ->assertSee('admin-shift.css')
            ->assertDontSee('data-shift-source="published"', false)
            ->assertDontSee('staff-shift.css');
    }

    public function test_year_and_month_options_are_generated_from_the_allowed_window(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 11, 15, 12, 0, 0, 'Asia/Tokyo'),
        );

        $response = $this->get('/staff?month=2027-01');

        $response
            ->assertOk()
            ->assertSee('value="2026"', false)
            ->assertSee('data-months="7,8,9,10,11,12"', false)
            ->assertSee('value="2027"', false)
            ->assertSee('data-months="1,2"', false)
            ->assertDontSee('value="2025"', false)
            ->assertDontSee('value="2028"', false);

        $monthOptions = $this->monthSelectHtml($response->getContent());

        $this->assertStringContainsString('>1月</option>', $monthOptions);
        $this->assertStringContainsString('>2月</option>', $monthOptions);
        $this->assertStringNotContainsString('>3月</option>', $monthOptions);
        $this->assertStringNotContainsString('>12月</option>', $monthOptions);
    }

    public function test_staff_and_admin_month_selectors_use_their_own_scripts(): void
    {
        $staffScript = file_get_contents(public_path('js/staff-shift.js'));
        $adminScript = $this->adminMonthNavigationScript();

        $this->assertIsString($staffScript);
        $this->assertStringContainsString('data-staff-month-navigation', $staffScript);
        $this->assertStringContainsString('selectedYearOption.dataset.months', $staffScript);
        $this->assertStringContainsString('monthSelect.replaceChildren()', $staffScript);
        $this->assertStringContainsString('setupYearMonthSelector(form)', $adminScript);
        $this->assertStringContainsString('selectedYearOption.dataset.months', $adminScript);
        $this->assertStringContainsString('monthSelect.replaceChildren()', $adminScript);
    }

    public function test_admin_store_screen_exposes_the_autosave_navigation_guard_contract(): void
    {
        $this->get('/admin/shifts/stores/daianji?month=2026-07')
            ->assertOk()
            ->assertSee('data-admin-month-navigation', false)
            ->assertSee('data-target-month="2026-07"', false)
            ->assertSee('data-admin-month-form', false)
            ->assertSee('data-admin-month-navigation-error', false)
            ->assertSee('admin-shift-static.js');
    }

    public function test_admin_month_navigation_flushes_pending_changes_before_moving(): void
    {
        $script = $this->adminMonthNavigationScript();

        $this->assertMatchesRegularExpression(
            '/pendingDestination = destination;.*admin-shift:autosave-flush-request/s',
            $script,
        );
        $this->assertMatchesRegularExpression(
            "/saveState === 'saving'.*setNavigationDisabled\\(true\\)/s",
            $script,
        );
    }

    public function test_admin_month_navigation_stays_on_the_current_month_when_saving_fails(): void
    {
        $script = $this->adminMonthNavigationScript();

        $this->assertMatchesRegularExpression(
            "/saveState === 'failed'.*pendingDestination = null;.*showError/s",
            $script,
        );
        $this->assertMatchesRegularExpression(
            "/if \\(saveState === 'failed'\\).*showError.*return;/s",
            $script,
        );
    }

    public function test_admin_month_navigation_ignores_a_save_response_for_another_month(): void
    {
        $script = $this->adminMonthNavigationScript();

        $this->assertMatchesRegularExpression(
            '/detail\\.month && detail\\.month !== currentMonth\\) \\{\\s*return;/s',
            $script,
        );
        $this->assertMatchesRegularExpression(
            "/saveState === 'saved' && pendingDestination.*window\\.location\\.assign/s",
            $script,
        );
    }

    private function adminMonthNavigationScript(): string
    {
        $script = file_get_contents(public_path('js/admin-shift-static.js'));

        $this->assertIsString($script);

        return $script;
    }

    private function monthSelectHtml(string $html): string
    {
        $matched = preg_match(
            '/<select[^>]*name="month_number"[^>]*>(.*?)<\/select>/s',
            $html,
            $matches,
        );

        $this->assertSame(1, $matched);

        return $matches[1];
    }

    /**
     * @param  array{path: string, query: array<string, string>}  $screen
     */
    private function urlWithoutMonth(array $screen): string
    {
        return $screen['query'] === []
            ? $screen['path']
            : $screen['path'].'?'.http_build_query($screen['query']);
    }

    /**
     * @param  array{path: string, query: array<string, string>}  $screen
     */
    private function monthUrl(array $screen, string $month): string
    {
        return $screen['path'].'?'.http_build_query([
            'month' => $month,
            ...$screen['query'],
        ]);
    }

    /**
     * @param  array{path: string, query: array<string, string>}  $screen
     */
    private function selectionUrl(array $screen, int $year, int $month): string
    {
        return $screen['path'].'?'.http_build_query([
            'year' => $year,
            'month_number' => $month,
            ...$screen['query'],
        ]);
    }
}
