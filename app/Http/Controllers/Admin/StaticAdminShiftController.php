<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StaticAdminShiftUiService;
use App\Services\CalendarService;
use DateTimeImmutable;
use DateTimeZone;
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
    ) {
    }

    public function store(Request $request, ?string $store = null): View
    {
        $storeCode = $store ?? $this->uiService->defaultStoreCode();

        abort_unless($this->uiService->hasStore($storeCode), 404);

        [$calendar, $isNg] = $this->screenState($request);
        $query = $this->stateQuery($isNg);
        $staffCode = $this->uiService->defaultStaffCode();

        return view('admin.shifts.stores.show', [
            'calendar' => $calendar,
            'isNg' => $isNg,
            'loginUserName' => '近澤 幸次',
            'screen' => $this->uiService->makeStoreScreen($storeCode, $calendar, $isNg),
            'navigation' => [
                'previous' => route('admin.shifts.stores.show', [
                    'store' => $storeCode,
                    'month' => $calendar['previous_month'],
                    ...$query,
                ]),
                'next' => route('admin.shifts.stores.show', [
                    'store' => $storeCode,
                    'month' => $calendar['next_month'],
                    ...$query,
                ]),
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

    public function staff(Request $request, ?string $staff = null): View
    {
        $staffCode = $staff ?? $this->uiService->defaultStaffCode();

        abort_unless($this->uiService->hasStaff($staffCode), 404);

        [$calendar, $isNg] = $this->screenState($request);
        $query = $this->stateQuery($isNg);
        $storeCode = $this->uiService->defaultStoreCode();

        return view('admin.shifts.staff.show', [
            'calendar' => $calendar,
            'isNg' => $isNg,
            'loginUserName' => '近澤 幸次',
            'screen' => $this->uiService->makeStaffScreen($staffCode, $calendar, $isNg),
            'navigation' => [
                'previous' => route('admin.shifts.staff.show', [
                    'staff' => $staffCode,
                    'month' => $calendar['previous_month'],
                    ...$query,
                ]),
                'next' => route('admin.shifts.staff.show', [
                    'staff' => $staffCode,
                    'month' => $calendar['next_month'],
                    ...$query,
                ]),
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
     * @return array{array<string, mixed>, bool}
     */
    private function screenState(Request $request): array
    {
        $now = new DateTimeImmutable(
            'today',
            new DateTimeZone((string) config('app.timezone', 'Asia/Tokyo')),
        );
        $month = $this->validMonth((string) $request->query('month', ''))
            ?? $now->format('Y-m');
        $isNg = $request->query('state') === 'ng';

        return [
            $this->calendarService->make($month, $now->format('Y-m-d')),
            $isNg,
        ];
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

    private function validMonth(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');

        return $date !== false && $date->format('Y-m') === $value ? $value : null;
    }
}
