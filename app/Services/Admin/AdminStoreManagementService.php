<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminStoreManagementService
{
    /**
     * @param  array{manager_id?: int|null, area?: string|null, query?: string|null}  $filters
     * @return Collection<int, Store>
     */
    public function accessibleStores(User $actor, array $filters): Collection
    {
        $today = $this->today();

        return $this->accessibleStoreQuery($actor)
            ->with([
                'shiftManagers' => function (BelongsToMany $builder) use ($today): void {
                    $this->applyCurrentAssignmentScope($builder, $today)
                        ->select(['users.id', 'users.name'])
                        ->orderBy('users.name')
                        ->orderBy('users.id');
                },
            ])
            ->when(
                $filters['manager_id'] ?? null,
                function (Builder $query, int $managerId) use ($today): void {
                    $query->whereHas(
                        'shiftManagers',
                        function (Builder $managerQuery) use ($managerId, $today): void {
                            $this->applyCurrentAssignmentScope(
                                $managerQuery,
                                $today,
                            )->whereKey($managerId);
                        },
                    );
                },
            )
            ->when(
                array_key_exists('area', $filters)
                    && $filters['area'] !== null
                    && $filters['area'] !== '',
                function (Builder $query) use ($filters): void {
                    if ($filters['area'] === '__unset__') {
                        $query->whereNull('area');

                        return;
                    }

                    $query->where('area', $filters['area']);
                },
            )
            ->when(
                $filters['query'] ?? null,
                function (Builder $query, string $search): void {
                    $query->where(function (Builder $searchQuery) use ($search): void {
                        $searchQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
                },
            )
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function managerFilterOptions(User $actor): Collection
    {
        $today = $this->today();
        $storeIds = $this->accessibleStoreQuery($actor)->pluck('stores.id');

        return User::query()
            ->where('organization_id', $actor->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'code',
                'shift_manager',
            ))
            ->whereHas(
                'managedStores',
                function (Builder $query) use ($storeIds, $today): void {
                    $this->applyCurrentAssignmentScope($query, $today)
                        ->whereKey($storeIds);
                },
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get(['users.id', 'users.name']);
    }

    /**
     * @return SupportCollection<int, string|null>
     */
    public function areaFilterOptions(User $actor): SupportCollection
    {
        return $this->accessibleStoreQuery($actor)
            ->select('area')
            ->groupBy('area')
            ->orderByRaw('CASE WHEN area IS NULL THEN 1 ELSE 0 END')
            ->orderBy('area')
            ->pluck('area');
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
     * @return array{
     *     shiftManagers: Collection<int, User>,
     *     shiftPatterns: Collection<int, StoreShiftPattern>,
     *     staffMembers: Collection<int, User>,
     *     staffingRequirement: StoreStaffingRequirement|null,
     *     store: Store
     * }
     */
    public function detailData(Store $store, array $oldInput = []): array
    {
        $today = $this->today();

        $store->load([
            'staffMembers' => function (BelongsToMany $query) use ($today): void {
                $this->applyCurrentStaffMembershipScope($query, $today)
                    ->select([
                        'users.id',
                        'users.name',
                        'users.email',
                        'users.status',
                    ])
                    ->orderBy('store_user.display_order')
                    ->orderBy('users.name')
                    ->orderBy('users.id');
            },
            'shiftManagers' => function (BelongsToMany $query) use ($today): void {
                $this->applyCurrentAssignmentScope($query, $today)
                    ->select(['users.id', 'users.name', 'users.email'])
                    ->orderBy('users.name')
                    ->orderBy('users.id');
            },
            'shiftPatterns' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('code')
                ->orderBy('id'),
        ]);

        $staffingRequirement = $store->staffingRequirements()
            ->whereNull('work_date')
            ->whereNull('weekday')
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $this->today());
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $this->today());
            })
            ->with([
                'options.patterns.shiftPattern',
            ])
            ->orderByDesc('effective_from')
            ->orderBy('id')
            ->first();

        $staffMembers = $this->oldSelectedUsers(
            $store,
            $oldInput,
            'staff_user_ids',
            'staff',
        ) ?? $store->staffMembers;
        $shiftManagers = $this->oldSelectedUsers(
            $store,
            $oldInput,
            'manager_user_ids',
            'shift_manager',
        ) ?? $store->shiftManagers;
        $shiftPatterns = $store->shiftPatterns;

        return compact(
            'shiftManagers',
            'shiftPatterns',
            'staffMembers',
            'staffingRequirement',
            'store',
        );
    }

    /**
     * @return Collection<int, User>
     */
    public function searchUnassignedStaff(
        Store $store,
        string $search,
    ): Collection {
        $today = $this->today();

        return User::query()
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'code',
                'staff',
            ))
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->whereDoesntHave(
                'stores',
                function (Builder $query) use ($store, $today): void {
                    $query->whereKey($store->getKey());
                    $this->applyCurrentStaffMembershipScope($query, $today);
                },
            )
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get([
                'users.id',
                'users.name',
                'users.email',
            ]);
    }

    /**
     * @return Collection<int, User>
     */
    public function searchUnassignedManagers(
        Store $store,
        string $search,
    ): Collection {
        $today = $this->today();

        return User::query()
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'code',
                'shift_manager',
            ))
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->whereDoesntHave(
                'managedStores',
                function (Builder $query) use ($store, $today): void {
                    $query->whereKey($store->getKey());
                    $this->applyCurrentAssignmentScope($query, $today);
                },
            )
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * @param  array{name: string, code: string, area: string}  $attributes
     */
    public function create(User $actor, array $attributes): Store
    {
        return DB::transaction(fn (): Store => Store::query()->create([
            'organization_id' => $actor->organization_id,
            'code' => trim($attributes['code']),
            'name' => trim($attributes['name']),
            'area' => trim($attributes['area']),
            'display_order' => 0,
            'staffing_check_mode' => 'disabled',
            'required_staff_count' => null,
        ]));
    }

    private function accessibleStoreQuery(User $actor): Builder
    {
        $query = Store::query()
            ->where('organization_id', $actor->organization_id);

        if ($actor->hasRole('system_admin')) {
            return $query;
        }

        $today = $this->today();

        return $query->whereHas(
            'shiftManagers',
            function (Builder $managerQuery) use ($actor, $today): void {
                $this->applyCurrentAssignmentScope($managerQuery, $today)
                    ->whereKey($actor->getKey());
            },
        );
    }

    private function applyCurrentAssignmentScope(
        Builder|BelongsToMany $query,
        string $today,
    ): Builder|BelongsToMany {
        return $query
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
    }

    private function applyCurrentStaffMembershipScope(
        Builder|BelongsToMany $query,
        string $today,
    ): Builder|BelongsToMany {
        return $query
            ->where('store_user.is_active', true)
            ->where(function (Builder $period) use ($today): void {
                $period
                    ->whereNull('store_user.started_on')
                    ->orWhereDate('store_user.started_on', '<=', $today);
            })
            ->where(function (Builder $period) use ($today): void {
                $period
                    ->whereNull('store_user.ended_on')
                    ->orWhereDate('store_user.ended_on', '>=', $today);
            });
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }

    /**
     * @return Collection<int, User>|null
     */
    private function oldSelectedUsers(
        Store $store,
        array $oldInput,
        string $field,
        string $roleCode,
    ): ?Collection {
        if (! array_key_exists($field, $oldInput) || ! is_array($oldInput[$field])) {
            return null;
        }

        $ids = collect($oldInput[$field])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $order = $ids->flip();

        return User::query()
            ->where('organization_id', $store->organization_id)
            ->whereKey($ids)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'code',
                $roleCode,
            ))
            ->get(['users.id', 'users.name', 'users.email'])
            ->sortBy(fn (User $user): int => (int) $order->get($user->getKey()))
            ->values();
    }
}
