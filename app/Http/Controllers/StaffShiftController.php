<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarService;
use App\Services\Staff\PublishedShiftReadService;
use App\Services\TargetMonthService;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffShiftController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly PublishedShiftReadService $publishedShiftReadService,
        private readonly TargetMonthService $targetMonthService,
    ) {}

    public function top(Request $request): View|RedirectResponse
    {
        $targetMonth = $this->targetMonthService->resolve($request);
        $today = $this->resolveToday($request);
        $query = $this->todayQuery($request);
        $baseUrl = route('staff.top');

        if ($targetMonth['requires_canonical_redirect'] || ! $request->routeIs('staff.top')) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value'], $query),
            );
        }

        $user = $this->user($request);
        $screen = $this->publishedShiftReadService->personalScreen(
            $user,
            $targetMonth['date'],
        );

        return view('staff.top', [
            'calendar' => $this->calendarService->make($targetMonth['value'], $today),
            'loginUser' => $this->loginUser($user),
            'personalShifts' => $screen['personalShifts'],
            'stores' => $screen['stores'],
            'today' => $today,
            'monthNavigation' => $this->targetMonthService->navigation(
                $baseUrl,
                $targetMonth,
                $query,
            ),
            /*
             * todayは動作確認用に明示された場合だけリンクへ引き継ぎます。
             * 通常表示で現在日をURLへ固定すると、日付が変わっても前日のままになるためです。
             */
            'query' => $query,
        ]);
    }

    public function store(Request $request, string $store): View|RedirectResponse
    {
        $targetMonth = $this->targetMonthService->resolve($request);
        $today = $this->resolveToday($request);
        $query = $this->todayQuery($request);
        $baseUrl = route('staff.store', ['store' => $store]);
        $user = $this->user($request);
        $screen = $this->publishedShiftReadService->storeScreen(
            $user,
            $store,
            $targetMonth['date'],
        );

        abort_if($screen === null, 404);

        if ($targetMonth['requires_canonical_redirect']) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value'], $query),
            );
        }

        return view('staff.store', [
            'calendar' => $this->calendarService->make($targetMonth['value'], $today),
            'loginUser' => $this->loginUser($user),
            'stores' => $screen['stores'],
            'store' => $screen['store'],
            'storeCode' => $store,
            'today' => $today,
            'monthNavigation' => $this->targetMonthService->navigation(
                $baseUrl,
                $targetMonth,
                $query,
            ),
            'query' => $query,
        ]);
    }

    /**
     * 不正な日付は現在日へフォールバックします。
     */
    private function resolveToday(Request $request): string
    {
        $now = new DateTimeImmutable('today');
        $today = $this->validDate((string) $request->query('today', ''))
            ?? $now->format('Y-m-d');

        return $today;
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

    private function validDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @return array{name: string}
     */
    private function loginUser(User $user): array
    {
        return [
            'name' => $user->name,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
