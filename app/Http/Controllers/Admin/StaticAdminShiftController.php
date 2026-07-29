<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StaticAdminShiftUiService;
use App\Services\CalendarService;
use App\Services\TargetMonthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 管理者用シフト画面の静的UI確認だけを担当します。
 *
 * DB接続、自動保存、配布処理は後続工程で別の責務として実装します。
 */
final class StaticAdminShiftController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly StaticAdminShiftUiService $uiService,
        private readonly TargetMonthService $targetMonthService,
    ) {}

    public function store(Request $request, ?string $store = null): View|RedirectResponse
    {
        $storeCode = $store ?? $this->uiService->defaultStoreCode();

        abort_unless($this->uiService->hasStore($storeCode), 404);

        $targetMonth = $this->targetMonthService->resolve($request);
        $isNg = $request->query('state') === 'ng';
        $query = $this->stateQuery($isNg);
        $baseUrl = $store === null
            ? route('admin.top')
            : route('admin.shifts.stores.show', ['store' => $storeCode]);

        if ($targetMonth['requires_canonical_redirect']) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value'], $query),
            );
        }

        $calendar = $this->calendarService->make(
            $targetMonth['value'],
            now((string) config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d'),
        );
        $monthNavigation = $this->targetMonthService->navigation(
            $baseUrl,
            $targetMonth,
            $query,
        );
        $staffCode = $this->uiService->defaultStaffCode();

        return view('admin.shifts.stores.show', [
            'calendar' => $calendar,
            'isNg' => $isNg,
            'loginUserName' => '近澤 幸次',
            'screen' => $this->uiService->makeStoreScreen($storeCode, $calendar, $isNg),
            'monthNavigation' => $monthNavigation,
            'navigation' => [
                'storeView' => route('admin.shifts.stores.show', [
                    'store' => $storeCode,
                    'month' => $calendar['month_value'],
                    ...$query,
                ]),
                'staffView' => route('admin.shifts.staff.show', [
                    'staff' => $staffCode,
                    'month' => $calendar['month_value'],
                    ...$query,
                ]),
            ],
            'contextOptions' => $this->storeOptions($storeCode, $calendar['month_value'], $query),
        ]);
    }

    public function staff(Request $request, ?string $staff = null): View|RedirectResponse
    {
        $staffCode = $staff ?? $this->uiService->defaultStaffCode();

        abort_unless($this->uiService->hasStaff($staffCode), 404);

        $targetMonth = $this->targetMonthService->resolve($request);
        $isNg = $request->query('state') === 'ng';
        $query = $this->stateQuery($isNg);
        $baseUrl = route('admin.shifts.staff.show', ['staff' => $staffCode]);

        if ($targetMonth['requires_canonical_redirect']) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value'], $query),
            );
        }

        $calendar = $this->calendarService->make(
            $targetMonth['value'],
            now((string) config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d'),
        );
        $monthNavigation = $this->targetMonthService->navigation(
            $baseUrl,
            $targetMonth,
            $query,
        );
        $storeCode = $this->uiService->defaultStoreCode();

        return view('admin.shifts.staff.show', [
            'calendar' => $calendar,
            'isNg' => $isNg,
            'loginUserName' => '近澤 幸次',
            'screen' => $this->uiService->makeStaffScreen($staffCode, $calendar, $isNg),
            'monthNavigation' => $monthNavigation,
            'navigation' => [
                'storeView' => route('admin.shifts.stores.show', [
                    'store' => $storeCode,
                    'month' => $calendar['month_value'],
                    ...$query,
                ]),
                'staffView' => route('admin.shifts.staff.show', [
                    'staff' => $staffCode,
                    'month' => $calendar['month_value'],
                    ...$query,
                ]),
            ],
            'contextOptions' => $this->staffOptions($staffCode, $calendar['month_value'], $query),
        ]);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int, array{label: string, url: string, current: bool}>
     */
    private function storeOptions(string $currentStore, string $month, array $query): array
    {
        $options = [];

        foreach ($this->uiService->stores() as $code => $name) {
            $options[] = [
                'label' => $name,
                'url' => route('admin.shifts.stores.show', [
                    'store' => $code,
                    'month' => $month,
                    ...$query,
                ]),
                'current' => $code === $currentStore,
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int, array{label: string, url: string, current: bool}>
     */
    private function staffOptions(string $currentStaff, string $month, array $query): array
    {
        $options = [];

        foreach ($this->uiService->staffMembers() as $code => $name) {
            $options[] = [
                'label' => $name,
                'url' => route('admin.shifts.staff.show', [
                    'staff' => $code,
                    'month' => $month,
                    ...$query,
                ]),
                'current' => $code === $currentStaff,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function stateQuery(bool $isNg): array
    {
        return $isNg ? ['state' => 'ng'] : [];
    }
}
