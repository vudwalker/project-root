<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminStaffIndexRequest;
use App\Http\Requests\Admin\StoreShiftManagerRequest;
use App\Http\Requests\Admin\UpdateShiftManagerProfileRequest;
use App\Http\Requests\Admin\UpdateShiftManagersRequest;
use App\Models\User;
use App\Services\Admin\AdminShiftManagerManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class AdminShiftManagerController extends Controller
{
    public function __construct(
        private readonly AdminShiftManagerManagementService $service,
    ) {}

    public function index(AdminStaffIndexRequest $request): View
    {
        $actor = $this->actor($request);
        $this->authorize($actor);
        $filters = $request->filters();
        $screen = $this->service->screen($actor, $filters);

        return view('admin.shift-managers.index', [
            ...$screen,
            'canEditManagers' => $screen['managers']->mapWithKeys(
                fn (User $manager): array => [
                    (int) $manager->getKey() => Gate::forUser($actor)->allows(
                        'manageShiftManagerProfile',
                        $manager,
                    ),
                ],
            ),
            'filters' => $filters,
            'loginUserName' => $actor->name,
            'roleLabels' => $this->service->roleLabels(),
            'statusLabels' => $this->service->statusLabels(),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorize($actor);

        return view('admin.shift-managers.form', [
            'formAction' => route('admin.shift-managers.store'),
            'formMethod' => 'POST',
            'formTitle' => '専任シフト管理者追加',
            'isCreate' => true,
            'loginUserName' => $actor->name,
            'manager' => null,
            'roleLabels' => $this->service->roleLabels(),
            'selectedStoreIds' => [],
            'statusLabels' => $this->service->statusLabels(),
            'storeOptions' => $this->service->screen($actor)['stores'],
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('manageShiftManagerProfile', $user);
        $user->load('roles');

        return view('admin.shift-managers.form', [
            'formAction' => route('admin.shift-managers.profile.update', ['user' => $user]),
            'formMethod' => 'PATCH',
            'formTitle' => 'シフト管理者編集',
            'isCreate' => false,
            'loginUserName' => $actor->name,
            'manager' => $user,
            'roleLabels' => $this->service->roleLabels(),
            'selectedStoreIds' => $this->service->selectedManagedStoreIds($user),
            'statusLabels' => $this->service->statusLabels(),
            'storeOptions' => $this->service->screen($actor)['stores'],
        ]);
    }

    public function update(UpdateShiftManagersRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor);
        $this->service->update($actor, $request->validated());

        return redirect()
            ->route('admin.shift-managers.index')
            ->with('status', 'シフト管理者の設定を保存しました。');
    }

    public function store(StoreShiftManagerRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorize($actor);
        $manager = $this->service->create($actor, $request->validated());

        return redirect()
            ->route('admin.shift-managers.index')
            ->with('status', $manager->name.'さんをシフト管理者として登録しました。');
    }

    public function updateProfile(
        UpdateShiftManagerProfileRequest $request,
        User $user,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $this->authorize($actor);
        $manager = $this->service->updateProfile(
            $actor,
            $user,
            $request->validated(),
        );

        return redirect()
            ->route('admin.shift-managers.index')
            ->with('status', $manager->name.'さんの情報を保存しました。');
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageShiftManagers', User::class);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
