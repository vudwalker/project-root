<?php

namespace App\Http\Controllers;

use App\Services\CalendarService;
use App\Services\StaffShiftMockService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffShiftController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly StaffShiftMockService $mockService,
    ) {
    }

    public function top(Request $request): View
    {
        [$month, $today] = $this->resolveDates($request);

        return view('staff.top', [
            'calendar' => $this->calendarService->make($month, $today),
            'loginUser' => $this->mockService->loginUser(),
            'personalShifts' => $this->mockService->personalShifts(),
            'stores' => $this->mockService->stores(),
            'today' => $today,
            'query' => ['today' => $today],
        ]);
    }

    public function store(Request $request, string $store): View
    {
        [$month, $today] = $this->resolveDates($request);
        $stores = $this->mockService->stores();

        if (! array_key_exists($store, $stores)) {
            abort(404);
        }

        return view('staff.store', [
            'calendar' => $this->calendarService->make($month, $today),
            'loginUser' => $this->mockService->loginUser(),
            'stores' => $stores,
            'store' => $stores[$store],
            'storeCode' => $store,
            'today' => $today,
            'query' => ['today' => $today],
        ]);
    }

    /**
     * 不正な値は現在年月・現在日へフォールバックします。
     *
     * @return array{string, string}
     */
    private function resolveDates(Request $request): array
    {
        $now = new DateTimeImmutable('today');
        $month = $this->validMonth((string) $request->query('month', ''))
            ?? $now->format('Y-m');
        $today = $this->validDate((string) $request->query('today', ''))
            ?? $now->format('Y-m-d');

        return [$month, $today];
    }

    private function validMonth(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');

        return $date !== false && $date->format('Y-m') === $value ? $value : null;
    }

    private function validDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
