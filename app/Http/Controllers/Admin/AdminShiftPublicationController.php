<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishStoreShiftRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Admin\AdminShiftPublishService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AdminShiftPublicationController extends Controller
{
    public function __construct(
        private readonly AdminShiftPublishService $publishService,
    ) {}

    public function store(
        PublishStoreShiftRequest $request,
        string $store,
    ): JsonResponse {
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        $targetStore = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->where('code', $store)
            ->first();

        if (! $targetStore instanceof Store) {
            abort_if(Store::query()->where('code', $store)->exists(), 403);
            abort(404);
        }

        Gate::forUser($actor)->authorize('publishAdminShifts', $targetStore);

        $targetMonth = $request->targetMonth();

        abort_unless($targetMonth instanceof CarbonImmutable, 422);

        return response()->json(
            $this->publishService->publish(
                $targetStore,
                $actor,
                $targetMonth,
                (int) $request->validated('expected_draft_version'),
            ),
        );
    }
}
