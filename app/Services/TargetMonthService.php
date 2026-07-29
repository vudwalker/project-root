<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * 画面に依存しない対象月の検証とURL生成を担当します。
 */
final class TargetMonthService
{
    private const SYSTEM_START_MONTH = '2026-07';

    /**
     * 「4か月以上先は選択不可」のため、現在月を含めて4か月を表示します。
     */
    private const SELECTABLE_FUTURE_MONTHS = 3;

    /**
     * 対象月は必ず月初日のCarbonImmutableとして解決します。
     *
     * @return array{
     *     date: CarbonImmutable,
     *     value: string,
     *     selected_year: int,
     *     selected_month: int,
     *     selectable_years: list<int>,
     *     selectable_months_by_year: array<int, list<int>>,
     *     previous: ?string,
     *     next: ?string,
     *     current: string,
     *     requires_canonical_redirect: bool
     * }
     */
    public function resolve(Request $request): array
    {
        $currentMonth = $this->currentMonth();
        $minimumMonth = $this->systemStartMonth();
        $maximumMonth = $currentMonth->addMonthsNoOverflow(self::SELECTABLE_FUTURE_MONTHS);

        if ($maximumMonth->lessThan($minimumMonth)) {
            $maximumMonth = $minimumMonth;
        }

        $fallbackMonth = $currentMonth->betweenIncluded($minimumMonth, $maximumMonth)
            ? $currentMonth
            : $minimumMonth;
        $hasDirectSelection = $request->query->has('year')
            || $request->query->has('month_number');

        $targetMonth = $hasDirectSelection
            ? $this->fromDirectSelection(
                $request,
                $fallbackMonth,
                $minimumMonth,
                $maximumMonth,
            )
            : $this->fromMonthValue(
                $request->query('month'),
                $fallbackMonth,
                $minimumMonth,
                $maximumMonth,
            );
        $selectableMonthsByYear = $this->selectableMonthsByYear(
            $minimumMonth,
            $maximumMonth,
        );
        $monthValue = $targetMonth->format('Y-m');
        $monthQuery = $request->query('month');
        $hasCanonicalMonth = ! $hasDirectSelection
            && is_string($monthQuery)
            && $monthQuery === $monthValue;

        return [
            'date' => $targetMonth,
            'value' => $monthValue,
            'selected_year' => $targetMonth->year,
            'selected_month' => $targetMonth->month,
            'selectable_years' => array_keys($selectableMonthsByYear),
            'selectable_months_by_year' => $selectableMonthsByYear,
            'previous' => $targetMonth->greaterThan($minimumMonth)
                ? $targetMonth->subMonthNoOverflow()->format('Y-m')
                : null,
            'next' => $targetMonth->lessThan($maximumMonth)
                ? $targetMonth->addMonthNoOverflow()->format('Y-m')
                : null,
            'current' => $fallbackMonth->format('Y-m'),
            'requires_canonical_redirect' => ! $hasCanonicalMonth,
        ];
    }

    /**
     * @param  array<string, scalar>  $preservedQuery
     * @return array{
     *     previousUrl: ?string,
     *     nextUrl: ?string,
     *     currentUrl: string,
     *     formAction: string,
     *     hiddenQuery: array<string, scalar>,
     *     selectedMonth: string,
     *     selectedYear: int,
     *     selectedMonthNumber: int,
     *     selectableYears: list<int>,
     *     selectableMonthsByYear: array<int, list<int>>
     * }
     */
    public function navigation(
        string $baseUrl,
        array $targetMonth,
        array $preservedQuery = [],
    ): array {
        return [
            'previousUrl' => $targetMonth['previous'] === null
                ? null
                : $this->url($baseUrl, $targetMonth['previous'], $preservedQuery),
            'nextUrl' => $targetMonth['next'] === null
                ? null
                : $this->url($baseUrl, $targetMonth['next'], $preservedQuery),
            'currentUrl' => $this->url($baseUrl, $targetMonth['current'], $preservedQuery),
            'formAction' => $baseUrl,
            'hiddenQuery' => $preservedQuery,
            'selectedMonth' => $targetMonth['value'],
            'selectedYear' => $targetMonth['selected_year'],
            'selectedMonthNumber' => $targetMonth['selected_month'],
            'selectableYears' => $targetMonth['selectable_years'],
            'selectableMonthsByYear' => $targetMonth['selectable_months_by_year'],
        ];
    }

    /**
     * @param  array<string, scalar>  $preservedQuery
     */
    public function url(string $baseUrl, string $month, array $preservedQuery = []): string
    {
        return $baseUrl.'?'.http_build_query([
            'month' => $month,
            ...$preservedQuery,
        ]);
    }

    private function currentMonth(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'Asia/Tokyo'))
            ->startOfMonth();
    }

    private function systemStartMonth(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m',
            self::SYSTEM_START_MONTH,
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->startOfMonth();
    }

    private function fromDirectSelection(
        Request $request,
        CarbonImmutable $fallback,
        CarbonImmutable $minimumMonth,
        CarbonImmutable $maximumMonth,
    ): CarbonImmutable {
        $year = $request->query('year');
        $month = $request->query('month_number');

        if (! is_string($year) || ! is_string($month)) {
            return $fallback;
        }

        if (! preg_match('/^\d{4}$/', $year) || ! preg_match('/^\d{1,2}$/', $month)) {
            return $fallback;
        }

        return $this->makeMonth(
            (int) $year,
            (int) $month,
            $fallback,
            $minimumMonth,
            $maximumMonth,
        );
    }

    private function fromMonthValue(
        mixed $value,
        CarbonImmutable $fallback,
        CarbonImmutable $minimumMonth,
        CarbonImmutable $maximumMonth,
    ): CarbonImmutable {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
            return $fallback;
        }

        $targetMonth = $this->makeMonth(
            (int) $matches[1],
            (int) $matches[2],
            $fallback,
            $minimumMonth,
            $maximumMonth,
        );

        return $targetMonth->format('Y-m') === $value ? $targetMonth : $fallback;
    }

    private function makeMonth(
        int $year,
        int $month,
        CarbonImmutable $fallback,
        CarbonImmutable $minimumMonth,
        CarbonImmutable $maximumMonth,
    ): CarbonImmutable {
        if ($month < 1 || $month > 12) {
            return $fallback;
        }

        $targetMonth = CarbonImmutable::create(
            $year,
            $month,
            1,
            0,
            0,
            0,
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->startOfMonth();

        return $targetMonth->betweenIncluded($minimumMonth, $maximumMonth)
            ? $targetMonth
            : $fallback;
    }

    /**
     * @return array<int, list<int>>
     */
    private function selectableMonthsByYear(
        CarbonImmutable $minimumMonth,
        CarbonImmutable $maximumMonth,
    ): array {
        $monthsByYear = [];
        $month = $minimumMonth;

        while ($month->lessThanOrEqualTo($maximumMonth)) {
            $monthsByYear[$month->year][] = $month->month;
            $month = $month->addMonthNoOverflow();
        }

        return $monthsByYear;
    }
}
