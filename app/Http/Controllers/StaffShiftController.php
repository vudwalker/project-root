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
            /*
             * todayは動作確認用に明示された場合だけリンクへ引き継ぎます。
             * 通常表示で現在日をURLへ固定すると、日付が変わっても前日のままになるためです。
             */
            'query' => $this->todayQuery($request),
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
            'query' => $this->todayQuery($request),
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

    /**
     * 有効なtodayがURLで明示された場合だけ、画面内リンクへ引き継ぎます。
     *
     * @return array<string, string>
     */
    private function todayQuery(Request $request): array
    {
        $today = $this->validDate((string) $request->query('today', ''));

        return $today !== null ? ['today' => $today] : [];
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
