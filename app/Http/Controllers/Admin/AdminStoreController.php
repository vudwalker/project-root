<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminStoreIndexRequest;
use App\Http\Requests\Admin\UpdateAdminStoreRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminStoreManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class AdminStoreController extends Controller
{
    public function __construct(
        private readonly AdminStoreManagementService $storeService,
    ) {}

    public function index(AdminStoreIndexRequest $request): View
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Store::class);

        $statusFilter = $request->statusFilter($actor);

        return view('admin.stores.index', [
            'canFilterInactive' => $actor->hasRole('system_admin'),
            'loginUserName' => $actor->name,
            'statusFilter' => $statusFilter,
            'stores' => $this->storeService->accessibleStores(
                $actor,
                $statusFilter,
            ),
        ]);
    }

    public function edit(Request $request, string $store): View
    {
        $actor = $this->actor($request);
        $targetStore = $this->storeService->resolveEditableStore($actor, $store);

        return view('admin.stores.edit', [
            'canChangeStatus' => Gate::forUser($actor)->allows(
                'changeAdminStoreStatus',
                $targetStore,
            ),
            'filterStatus' => $this->filterStatus($request, $actor),
            'loginUserName' => $actor->name,
            'store' => $targetStore,
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

        $this->storeService->update($targetStore, $attributes);

        return redirect()
            ->route('admin.stores.index', [
                'status' => $this->filterStatus($request, $actor),
            ])
            ->with('status', '店舗情報を更新しました。');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function filterStatus(Request $request, User $actor): string
    {
        if (! $actor->hasRole('system_admin')) {
            return 'active';
        }

        $status = (string) $request->input(
            'filter_status',
            $request->query('status', 'all'),
        );

        return in_array($status, ['all', 'active', 'inactive'], true)
            ? $status
            : 'all';
    }
}
