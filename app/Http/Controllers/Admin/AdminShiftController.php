<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminDraftShiftReadService;
use App\Services\CalendarService;
use App\Services\TargetMonthService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * 管理者用シフト画面の下書き読み取り表示を担当します。
 */
final class AdminShiftController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly AdminDraftShiftReadService $readService,
        private readonly TargetMonthService $targetMonthService,
    ) {}

    public function store(Request $request, ?string $store = null): View|RedirectResponse
    {
        $actor = $this->actor($request);
        $accessibleStores = $this->readService->accessibleStores($actor);

        if ($store === null && $accessibleStores->isEmpty()) {
            return view('admin.no-stores', [
                'loginUserName' => $actor->name,
            ]);
        }

        $selectedStore = $this->resolveStore($actor, $accessibleStores, $store);
        $targetMonth = $this->targetMonthService->resolve($request);
        $isNg = $request->query('state') === 'ng';
        $query = $this->stateQuery($isNg);
        $baseUrl = $store === null
            ? route('admin.top')
            : route('admin.shifts.stores.show', ['store' => $selectedStore->code]);

        if ($targetMonth['requires_canonical_redirect']) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value'], $query),
            );
        }

        $calendar = $this->calendarService->make(
            $targetMonth['value'],
            now((string) config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d'),
        );
        $staffMembers = $this->readService->staffForStore(
            $selectedStore,
            $targetMonth['date'],
        );
        $selectedStaff = $staffMembers->first();
        $screen = $this->readService->makeStoreScreen(
            $selectedStore,
            $targetMonth['date'],
            $calendar,
            $staffMembers,
            $isNg,
        );

        return view('admin.shifts.stores.show', [
            'calendar' => $calendar,
            'isNg' => $isNg,
            'loginUserName' => $actor->name,
            'screen' => $screen,
            'monthNavigation' => $this->targetMonthService->navigation(
                $baseUrl,
                $targetMonth,
                $query,
            ),
            'navigation' => [
                'storeView' => route('admin.shifts.stores.show', [
                    'store' => $selectedStore->code,
                    'month' => $calendar['month_value'],
                    ...$query,
                ]),
                'staffView' => route('admin.shifts.staff.show', [
                    'staff' => $selectedStaff?->getKey(),
                    'month' => $calendar['month_value'],
                    'store' => $selectedStore->code,
                    ...$query,
                ]),
            ],
            'contextOptions' => $this->storeOptions(
                $accessibleStores,
                $selectedStore,
                $calendar['month_value'],
                $query,
            ),
        ]);
    }

    public function staff(Request $request, ?string $staff = null): View|RedirectResponse
    {
        $actor = $this->actor($request);
        $accessibleStores = $this->readService->accessibleStores($actor);
        $storeCode = $request->query('store');

        abort_if($storeCode !== null && ! is_string($storeCode), 404);

        $selectedStore = $this->resolveStore(
            $actor,
            $accessibleStores,
            $storeCode,
        );
        $targetMonth = $this->targetMonthService->resolve($request);
        $staffMembers = $this->readService->staffForStore(
            $selectedStore,
            $targetMonth['date'],
        );
        $selectedStaff = $this->resolveStaff($staffMembers, $staff);
        $isNg = $request->query('state') === 'ng';
        $query = [
            'store' => $selectedStore->code,
            ...$this->stateQuery($isNg),
        ];
        $baseUrl = route('admin.shifts.staff.show', [
            'staff' => $selectedStaff?->getKey(),
        ]);
        $requiresStaffRedirect = $staff === null && $selectedStaff !== null;

        if ($targetMonth['requires_canonical_redirect'] || $requiresStaffRedirect) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value'], $query),
            );
        }

        $calendar = $this->calendarService->make(
            $targetMonth['value'],
            now((string) config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d'),
        );
        $screen = $this->readService->makeStaffScreen(
            $selectedStore,
            $selectedStaff,
            $targetMonth['date'],
            $calendar,
            $isNg,
        );

        return view('admin.shifts.staff.show', [
            'calendar' => $calendar,
            'isNg' => $isNg,
            'loginUserName' => $actor->name,
            'screen' => $screen,
            'monthNavigation' => $this->targetMonthService->navigation(
                $baseUrl,
                $targetMonth,
                $query,
            ),
            'navigation' => [
                'storeView' => route('admin.shifts.stores.show', [
                    'store' => $selectedStore->code,
                    'month' => $calendar['month_value'],
                    ...$this->stateQuery($isNg),
                ]),
                'staffView' => route('admin.shifts.staff.show', [
                    'staff' => $selectedStaff?->getKey(),
                    'month' => $calendar['month_value'],
                    ...$query,
                ]),
            ],
            'contextOptions' => $this->staffOptions(
                $staffMembers,
                $selectedStaff,
                $selectedStore,
                $calendar['month_value'],
                $isNg,
            ),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @param  Collection<int, Store>  $accessibleStores
     */
    private function resolveStore(
        User $actor,
        Collection $accessibleStores,
        ?string $storeCode,
    ): Store {
        if ($storeCode === null) {
            $store = $accessibleStores->first();

            abort_unless($store instanceof Store, 403);

            return $store;
        }

        $store = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->where('code', $storeCode)
            ->firstOrFail();

        Gate::forUser($actor)->authorize('viewAdminShifts', $store);

        return $store;
    }

    /**
     * @param  Collection<int, User>  $staffMembers
     */
    private function resolveStaff(Collection $staffMembers, ?string $staff): ?User
    {
        if ($staff === null) {
            return $staffMembers->first();
        }

        abort_unless(ctype_digit($staff), 404);

        $selectedStaff = $staffMembers->first(
            fn (User $candidate): bool => (int) $candidate->getKey() === (int) $staff,
        );

        abort_unless($selectedStaff instanceof User, 404);

        return $selectedStaff;
    }

    /**
     * @param  Collection<int, Store>  $stores
     * @param  array<string, string>  $query
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function storeOptions(
        Collection $stores,
        Store $selectedStore,
        string $month,
        array $query,
    ): array {
        return $stores
            ->map(fn (Store $store): array => [
                'label' => $store->name,
                'url' => route('admin.shifts.stores.show', [
                    'store' => $store->code,
                    'month' => $month,
                    ...$query,
                ]),
                'current' => $store->is($selectedStore),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, User>  $staffMembers
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function staffOptions(
        Collection $staffMembers,
        ?User $selectedStaff,
        Store $selectedStore,
        string $month,
        bool $isNg,
    ): array {
        $query = [
            'month' => $month,
            'store' => $selectedStore->code,
            ...$this->stateQuery($isNg),
        ];

        return $staffMembers
            ->map(fn (User $staff): array => [
                'label' => $staff->name,
                'url' => route('admin.shifts.staff.show', [
                    'staff' => $staff->getKey(),
                    ...$query,
                ]),
                'current' => $selectedStaff?->is($staff) ?? false,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function stateQuery(bool $isNg): array
    {
        return $isNg ? ['state' => 'ng'] : [];
    }
}
