<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminStoreManagementService
{
    /**
     * @return Collection<int, Store>
     */
    public function accessibleStores(User $actor, string $statusFilter): Collection
    {
        $today = $this->today();
        $query = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->with([
                'shiftManagers' => function (BelongsToMany $builder) use ($today): void {
                    $builder
                        ->select(['users.id', 'users.name'])
                        ->where('store_shift_manager.is_active', true)
                        ->where(function (Builder $period) use ($today): void {
                            $period
                                ->whereNull('store_shift_manager.started_on')
                                ->orWhereDate('store_shift_manager.started_on', '<=', $today);
                        })
                        ->where(function (Builder $period) use ($today): void {
                            $period
                                ->whereNull('store_shift_manager.ended_on')
                                ->orWhereDate('store_shift_manager.ended_on', '>=', $today);
                        })
                        ->orderBy('users.name')
                        ->orderBy('users.id');
                },
            ])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id');

        if ($actor->hasRole('system_admin')) {
            return $query
                ->when(
                    $statusFilter !== 'all',
                    fn (Builder $builder): Builder => $builder->where(
                        'status',
                        $statusFilter,
                    ),
                )
                ->get();
        }

        return $query
            ->where('status', 'active')
            ->whereHas(
                'shiftManagers',
                function (Builder $builder) use ($actor, $today): void {
                    $builder
                        ->whereKey($actor->getKey())
                        ->where('store_shift_manager.is_active', true)
                        ->where(function (Builder $period) use ($today): void {
                            $period
                                ->whereNull('store_shift_manager.started_on')
                                ->orWhereDate('store_shift_manager.started_on', '<=', $today);
                        })
                        ->where(function (Builder $period) use ($today): void {
                            $period
                                ->whereNull('store_shift_manager.ended_on')
                                ->orWhereDate('store_shift_manager.ended_on', '>=', $today);
                        });
                },
            )
            ->get();
    }

    public function resolveEditableStore(User $actor, string $storeCode): Store
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

        Gate::forUser($actor)->authorize(
            'updateAdminStoreManagement',
            $store,
        );

        return $store;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Store $store, array $attributes): Store
    {
        return DB::transaction(function () use ($store, $attributes): Store {
            $lockedStore = Store::query()
                ->whereKey($store->getKey())
                ->where('organization_id', $store->organization_id)
                ->lockForUpdate()
                ->firstOrFail();
            $mode = (string) $attributes['staffing_check_mode'];

            $lockedStore->fill([
                'name' => $attributes['name'],
                'display_order' => $attributes['display_order'],
                'staffing_check_mode' => $mode,
                'required_staff_count' => $mode === 'fixed_total'
                    ? $attributes['required_staff_count']
                    : null,
                ...array_key_exists('status', $attributes)
                    ? ['status' => $attributes['status']]
                    : [],
            ]);
            $lockedStore->save();

            return $lockedStore;
        });
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }
}
