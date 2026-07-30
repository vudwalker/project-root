<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminStoreCandidateSearchRequest;
use App\Http\Requests\Admin\AdminStoreIndexRequest;
use App\Http\Requests\Admin\StoreAdminStoreRequest;
use App\Http\Requests\Admin\UpdateAdminStoreDetailsRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminStoreAssociationService;
use App\Services\Admin\AdminStoreManagementService;
use Illuminate\Http\JsonResponse;
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

    public function edit(Request $request, string $store): View
    {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);

        return view('admin.stores.edit', [
            ...$this->storeService->detailData(
                $targetStore,
                $request->session()->getOldInput(),
            ),
            'canManageManagers' => Gate::forUser($actor)->allows(
                'manageAdminStoreManagers',
                $targetStore,
            ),
            'loginUserName' => $actor->name,
        ]);
    }

    public function update(
        UpdateAdminStoreDetailsRequest $request,
        string $store,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);
        $this->associationService->updateStoreDetails(
            $targetStore,
            $actor,
            $request->validated(),
            Gate::forUser($actor)->allows(
                'manageAdminStoreManagers',
                $targetStore,
            ),
        );

        return $this->updatedRedirect($targetStore, '店舗情報を保存しました。');
    }

    public function staffCandidates(
        AdminStoreCandidateSearchRequest $request,
        string $store,
    ): JsonResponse {
        $targetStore = $this->resolveForUpdate($request, $store);

        return response()->json([
            'data' => $this->storeService
                ->searchUnassignedStaff($targetStore, $request->searchTerm())
                ->map(fn (User $user): array => $this->candidatePayload($user))
                ->values(),
        ]);
    }

    public function managerCandidates(
        AdminStoreCandidateSearchRequest $request,
        string $store,
    ): JsonResponse {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);
        Gate::forUser($actor)->authorize(
            'manageAdminStoreManagers',
            $targetStore,
        );

        return response()->json([
            'data' => $this->storeService
                ->searchUnassignedManagers(
                    $targetStore,
                    $request->searchTerm(),
                )
                ->map(fn (User $user): array => $this->candidatePayload($user))
                ->values(),
        ]);
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

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function candidatePayload(User $user): array
    {
        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
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
