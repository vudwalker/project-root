<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminStaffIndexRequest;
use App\Http\Requests\Admin\StoreAdminStaffRequest;
use App\Http\Requests\Admin\UpdateAdminStaffRequest;
use App\Models\User;
use App\Services\Admin\AdminStaffReadService;
use App\Services\Admin\AdminStaffWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class AdminStaffController extends Controller
{
    public function __construct(
        private readonly AdminStaffReadService $readService,
        private readonly AdminStaffWriteService $writeService,
    ) {}

    public function index(AdminStaffIndexRequest $request): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', User::class);
        $filters = $request->filters();
        $staffMembers = $this->readService->staffMembers($actor, $filters);

        return view('admin.staff.index', [
            'canEditStaff' => $staffMembers->mapWithKeys(
                fn (User $staff): array => [
                    (int) $staff->getKey() => Gate::forUser($actor)->allows(
                        'update',
                        $staff,
                    ),
                ],
            ),
            'filters' => $filters,
            'loginUserName' => $actor->name,
            'roleLabels' => $this->readService->staffListRoleLabels(),
            'staffMembers' => $staffMembers,
            'statusLabels' => $this->readService->statusLabels(),
            'storeOptions' => $this->readService->storeOptions($actor),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('create', User::class);

        return view('admin.staff.create', [
            ...$this->formData($actor),
            'loginUserName' => $actor->name,
        ]);
    }

    public function store(StoreAdminStaffRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('create', User::class);
        $staff = $this->writeService->create($actor, $request->validated());

        return redirect()
            ->route('admin.staff.edit', ['user' => $staff->getKey()])
            ->with('status', 'スタッフを登録しました。');
    }

    public function edit(Request $request, User $user): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('update', $user);
        $user->load('roles');

        return view('admin.staff.edit', [
            ...$this->formData($actor, $user),
            'loginUserName' => $actor->name,
        ]);
    }

    public function update(
        UpdateAdminStaffRequest $request,
        User $user,
    ): RedirectResponse {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('update', $user);
        $staff = $this->writeService->update(
            $actor,
            $user,
            $request->validated(),
        );

        if (! $staff->hasRole('staff', 'shift_manager', 'system_admin')) {
            return redirect()
                ->route('admin.staff.index')
                ->with('status', 'スタッフ情報を保存しました。');
        }

        return redirect()
            ->route('admin.staff.edit', ['user' => $staff->getKey()])
            ->with('status', 'スタッフ情報を保存しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $actor, ?User $target = null): array
    {
        return [
            'actor' => $actor,
            'canManageShiftManagerRole' => $actor->hasRole('system_admin'),
            'roleLabels' => $this->readService->roleLabels(),
            'selectedStoreIds' => $target instanceof User
                ? $this->readService->selectedStoreIds($target)
                : [],
            'staff' => $target,
            'statusLabels' => $this->readService->statusLabels(),
            'storeOptions' => $this->readService->storeOptions($actor),
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
