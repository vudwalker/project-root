<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteStoreShiftRequest;
use App\Http\Requests\Admin\StoreShiftRequest;
use App\Http\Requests\Admin\UpdateStoreShiftRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminShiftWriteService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminShiftMutationController extends Controller
{
    public function __construct(
        private readonly AdminShiftWriteService $writeService,
    ) {}

    public function store(StoreShiftRequest $request, string $store): JsonResponse
    {
        $actor = $this->actor($request);
        $targetStore = $this->editableStore($actor, $store);
        $targetMonth = $request->targetMonth();

        abort_unless($targetMonth instanceof CarbonImmutable, 422);

        $workDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $request->validated('work_date'),
            (string) config('app.timezone', 'Asia/Tokyo'),
        );
        $payload = $this->writeService->create(
            $targetStore,
            $actor,
            $targetMonth,
            (int) $request->validated('user_id'),
            $workDate,
            (int) $request->validated('shift_pattern_id'),
            (string) $request->validated('entry_uuid'),
        );

        return response()->json($payload, $payload['created'] ? 201 : 200);
    }

    public function update(
        UpdateStoreShiftRequest $request,
        string $store,
        string $shift,
    ): JsonResponse {
        $actor = $this->actor($request);
        $targetStore = $this->editableStore($actor, $store);
        $targetMonth = $request->targetMonth();

        abort_unless($targetMonth instanceof CarbonImmutable, 422);

        return response()->json(
            $this->writeService->update(
                $targetStore,
                $actor,
                $targetMonth,
                $this->shiftId($shift),
                (int) $request->validated('shift_pattern_id'),
            ),
        );
    }

    public function destroy(
        DeleteStoreShiftRequest $request,
        string $store,
        string $shift,
    ): JsonResponse {
        $actor = $this->actor($request);
        $targetStore = $this->editableStore($actor, $store);
        $targetMonth = $request->targetMonth();

        abort_unless($targetMonth instanceof CarbonImmutable, 422);

        return response()->json(
            $this->writeService->delete(
                $targetStore,
                $actor,
                $targetMonth,
                $this->shiftId($shift),
            ),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function editableStore(User $actor, string $storeCode): Store
    {
        $store = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->where('code', $storeCode)
            ->first();

        if (! $store instanceof Store) {
            abort_if(
                Store::query()->where('code', $storeCode)->exists(),
                403,
            );
            abort(404);
        }

        Gate::forUser($actor)->authorize('editAdminShifts', $store);

        return $store;
    }

    private function shiftId(string $shift): int
    {
        abort_unless(ctype_digit($shift), 404);

        return (int) $shift;
    }
}
