<?php

namespace Tests\Unit\Services;

use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TargetMonthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_api_month_parser_returns_a_month_start_in_selectable_range(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );

        $month = app(TargetMonthService::class)->parseSelectableMonth('2026-10');

        $this->assertSame('2026-10-01', $month?->toDateString());
        $this->assertTrue($month?->isStartOfMonth() ?? false);
    }

    public function test_api_month_parser_uses_the_same_pre_start_fallback_window_as_ui(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2025, 1, 15, 12, 0, 0, 'Asia/Tokyo'),
        );

        $month = app(TargetMonthService::class)->parseSelectableMonth('2026-07');

        $this->assertSame('2026-07-01', $month?->toDateString());
    }

    #[DataProvider('invalidMonths')]
    public function test_api_month_parser_rejects_invalid_or_unselectable_values(
        string $value,
    ): void {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 7, 30, 12, 0, 0, 'Asia/Tokyo'),
        );

        $this->assertNull(
            app(TargetMonthService::class)->parseSelectableMonth($value),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMonths(): array
    {
        return [
            'before system start' => ['2026-06'],
            'more than three months ahead' => ['2026-11'],
            'zero month' => ['2026-00'],
            'thirteen month' => ['2026-13'],
            'non numeric' => ['year-month'],
            'missing' => [''],
        ];
    }
}
