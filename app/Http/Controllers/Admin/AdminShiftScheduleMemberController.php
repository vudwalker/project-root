<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddShiftScheduleMemberRequest;
use App\Http\Requests\Admin\RemoveShiftScheduleMemberRequest;
use App\Http\Requests\Admin\ReorderShiftScheduleMembersRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminShiftScheduleMemberService;
use App\Services\TargetMonthService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class AdminShiftScheduleMemberController extends Controller
{
    public function __construct(
        private readonly AdminShiftScheduleMemberService $memberService,
        private readonly TargetMonthService $targetMonthService,
    ) {}

    public function index(Request $request, string $store): View|RedirectResponse
    {
        $actor = $this->actor($request);
        $targetStore = $this->resolveStore($actor, $store);
        Gate::forUser($actor)->authorize('viewAdminShifts', $targetStore);
        $targetMonth = $this->targetMonthService->resolve($request);
        $baseUrl = route('admin.shifts.members', ['store' => $targetStore->code]);

        if ($targetMonth['requires_canonical_redirect']) {
            return redirect()->to(
                $this->targetMonthService->url($baseUrl, $targetMonth['value']),
            );
        }

        $screen = $this->memberService->screen(
            $targetStore,
            $actor,
            $targetMonth['date'],
        );

        return view('admin.shifts.members.index', [
            'loginUserName' => $actor->name,
            'screen' => $screen,
            'monthNavigation' => $this->targetMonthService->navigation(
                $baseUrl,
                $targetMonth,
            ),
            'shiftEditorUrl' => route('admin.shifts.stores.show', [
                'store' => $targetStore->code,
                'month' => $targetMonth['value'],
            ]),
        ]);
    }

    public function add(
        AddShiftScheduleMemberRequest $request,
        string $store,
    ): JsonResponse {
        $actor = $this->actor($request);
        $targetStore = $this->editableStore($actor, $store);
        $targetMonth = $this->validatedMonth($request->targetMonth());

        return response()->json($this->memberService->add(
            $targetStore,
            $actor,
            $targetMonth,
            (int) $request->validated('user_id'),
            (int) $request->validated('expected_monthly_members_version'),
        ));
    }

    public function remove(
        RemoveShiftScheduleMemberRequest $request,
        string $store,
        string $user,
    ): JsonResponse {
        $actor = $this->actor($request);
        $targetStore = $this->editableStore($actor, $store);
        $targetMonth = $this->validatedMonth($request->targetMonth());
        abort_unless(ctype_digit($user), 404);

        return response()->json($this->memberService->remove(
            $targetStore,
            $actor,
            $targetMonth,
            (int) $user,
            (int) $request->validated('expected_monthly_members_version'),
        ));
    }

    public function reorder(
        ReorderShiftScheduleMembersRequest $request,
        string $store,
    ): JsonResponse {
        $actor = $this->actor($request);
        $targetStore = $this->editableStore($actor, $store);
        $targetMonth = $this->validatedMonth($request->targetMonth());

        return response()->json($this->memberService->reorder(
            $targetStore,
            $actor,
            $targetMonth,
            array_map('intval', $request->validated('user_ids', [])),
            (int) $request->validated('expected_monthly_members_version'),
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function resolveStore(User $actor, string $storeCode): Store
    {
        $store = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->where('code', $storeCode)
            ->first();

        if (! $store instanceof Store) {
            abort_if(Store::query()->where('code', $storeCode)->exists(), 403);
            abort(404);
        }

        return $store;
    }

    private function editableStore(User $actor, string $storeCode): Store
    {
        $store = $this->resolveStore($actor, $storeCode);
        Gate::forUser($actor)->authorize('editAdminShifts', $store);

        return $store;
    }

    private function validatedMonth(?CarbonImmutable $targetMonth): CarbonImmutable
    {
        abort_unless($targetMonth instanceof CarbonImmutable, 422);

        return $targetMonth;
    }
}
