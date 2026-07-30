<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminStoreEditRequest;
use App\Http\Requests\Admin\AdminStoreIndexRequest;
use App\Http\Requests\Admin\StoreAdminStoreRequest;
use App\Http\Requests\Admin\StoreAdminStoreStaffRequest;
use App\Http\Requests\Admin\UpdateAdminStoreManagersRequest;
use App\Http\Requests\Admin\UpdateAdminStorePatternsRequest;
use App\Http\Requests\Admin\UpdateAdminStoreRequest;
use App\Http\Requests\Admin\UpdateAdminStoreStaffingRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminStoreAssociationService;
use App\Services\Admin\AdminStoreManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class AdminStoreController extends Controller
{
    public function __construct(
        private readonly AdminStoreManagementService $storeService,
        private readonly AdminStoreAssociationService $associationService,
    ) {}

    public function index(AdminStoreIndexRequest $request): View
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Store::class);
        $filters = $request->filters();

        return view('admin.stores.index', [
            'areaOptions' => $this->storeService->areaFilterOptions($actor),
            'canCreateStore' => Gate::forUser($actor)->allows(
                'createAdminStore',
                Store::class,
            ),
            'filters' => $filters,
            'loginUserName' => $actor->name,
            'managerOptions' => $this->storeService->managerFilterOptions($actor),
            'stores' => $this->storeService->accessibleStores($actor, $filters),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('createAdminStore', Store::class);

        return view('admin.stores.create', [
            'loginUserName' => $actor->name,
        ]);
    }

    public function store(StoreAdminStoreRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $store = $this->storeService->create($actor, $request->validated());

        return redirect()
            ->route('admin.stores.edit', ['store' => $store->code])
            ->with('status', '店舗を追加しました。詳細設定を続けてください。');
    }

    public function edit(AdminStoreEditRequest $request, string $store): View
    {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);
        $staffAddOpen = $request->staffAddOpen();
        $staffQuery = $request->staffQuery();
        $staffSearchResults = $staffAddOpen && $staffQuery !== ''
            ? $this->storeService->searchUnassignedStaff(
                $targetStore,
                $staffQuery,
            )
            : collect();

        return view('admin.stores.edit', [
            ...$this->storeService->detailData($targetStore),
            'canChangeStatus' => Gate::forUser($actor)->allows(
                'changeAdminStoreStatus',
                $targetStore,
            ),
            'canManageManagers' => Gate::forUser($actor)->allows(
                'manageAdminStoreManagers',
                $targetStore,
            ),
            'loginUserName' => $actor->name,
            'staffAddOpen' => $staffAddOpen,
            'staffQuery' => $staffQuery,
            'staffSearchResults' => $staffSearchResults,
        ]);
    }

    public function update(
        UpdateAdminStoreRequest $request,
        string $store,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);
        $attributes = $request->validated();

        if (array_key_exists('status', $attributes)) {
            Gate::forUser($actor)->authorize(
                'changeAdminStoreStatus',
                $targetStore,
            );
        }

        $this->storeService->updateBasic($targetStore, $attributes);

        return $this->updatedRedirect($targetStore, '基本情報を更新しました。');
    }

    public function addStaff(
        StoreAdminStoreStaffRequest $request,
        string $store,
    ): RedirectResponse {
        $targetStore = $this->resolveForUpdate($request, $store);
        $this->associationService->addStaffMembership(
            $targetStore,
            $request->staffUserId(),
        );

        return $this->updatedRedirect(
            $targetStore,
            'スタッフを所属に追加しました。',
            'staff-members',
        );
    }

    public function removeStaff(
        Request $request,
        string $store,
        string $staff,
    ): RedirectResponse {
        $targetStore = $this->resolveForUpdate($request, $store);
        $this->associationService->removeStaffMembership(
            $targetStore,
            (int) $staff,
        );

        return $this->updatedRedirect(
            $targetStore,
            'スタッフの所属を解除しました。',
            'staff-members',
        );
    }

    public function updateManagers(
        UpdateAdminStoreManagersRequest $request,
        string $store,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);

        Gate::forUser($actor)->authorize(
            'manageAdminStoreManagers',
            $targetStore,
        );
        $this->associationService->updateShiftManagers(
            $targetStore,
            $request->managerUserIds(),
        );

        return $this->updatedRedirect(
            $targetStore,
            '担当シフト管理者を更新しました。',
            'shift-managers',
        );
    }

    public function updatePatterns(
        UpdateAdminStorePatternsRequest $request,
        string $store,
    ): RedirectResponse {
        $targetStore = $this->resolveForUpdate($request, $store);
        $this->associationService->updateShiftPatterns(
            $targetStore,
            $request->patterns(),
        );

        return $this->updatedRedirect(
            $targetStore,
            '使用シフトパターンを更新しました。',
            'shift-patterns',
        );
    }

    public function updateStaffing(
        UpdateAdminStoreStaffingRequest $request,
        string $store,
    ): RedirectResponse {
        $targetStore = $this->resolveForUpdate($request, $store);
        $this->associationService->updateStaffing(
            $targetStore,
            $request->validated(),
        );

        return $this->updatedRedirect(
            $targetStore,
            '人数配置判定を更新しました。',
            'staffing-settings',
        );
    }

    private function resolveForUpdate(Request $request, string $store): Store
    {
        return $this->storeService->resolveEditableStore(
            $this->actor($request),
            $store,
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function updatedRedirect(
        Store $store,
        string $message,
        ?string $fragment = null,
    ): RedirectResponse {
        $url = route('admin.stores.edit', ['store' => $store->code]);

        if ($fragment !== null) {
            $url .= "#{$fragment}";
        }

        return redirect()->to($url)->with('status', $message);
    }
}
