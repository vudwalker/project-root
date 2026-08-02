<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminStaffReadService
{
    /**
     * @param  array{query: string|null, status: string|null, store_id: int|null, role: string|null}  $filters
     * @return Collection<int, User>
     */
    public function staffMembers(User $actor, array $filters): Collection
    {
        $today = $this->today();

        return User::query()
            ->where('organization_id', $actor->organization_id)
            ->whereHas(
                'roles',
                fn (Builder $query): Builder => $query->where(
                    'roles.code',
                    'staff',
                ),
            )
            ->with([
                'roles:id,code,name',
                'stores' => function ($query) use ($today): void {
                    $this->applyCurrentMembershipScope($query, $today);
                    $query
                        ->orderBy('stores.code')
                        ->orderBy('stores.id');
                },
            ])
            ->when(
                $filters['query'],
                function (Builder $query, string $search): void {
                    $term = '%'.Str::lower($search).'%';
                    $query->where(function (Builder $match) use ($term): void {
                        $match
                            ->whereRaw('LOWER(users.name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(users.email) LIKE ?', [$term]);
                    });
                },
            )
            ->when(
                $filters['status'],
                fn (Builder $query, string $status): Builder => $query->where(
                    'users.status',
                    $status,
                ),
            )
            ->when(
                $filters['role'],
                fn (Builder $query, string $role): Builder => $query->whereHas(
                    'roles',
                    fn (Builder $roles): Builder => $roles->where(
                        'roles.code',
                        $role,
                    ),
                ),
            )
            ->when(
                $filters['store_id'],
                fn (Builder $query, int $storeId): Builder => $query->whereHas(
                    'stores',
                    function (Builder $stores) use ($storeId, $today): void {
                        $stores->whereKey($storeId);
                        $this->applyCurrentMembershipScope($stores, $today);
                    },
                ),
            )
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.organization_id',
                'users.name',
                'users.email',
                'users.status',
            ]);
    }

    /**
     * @return Collection<int, Store>
     */
    public function storeOptions(User $actor): Collection
    {
        return Store::query()
            ->where('organization_id', $actor->organization_id)
            ->orderBy('code')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'code', 'name']);
    }

    /**
     * @return list<int>
     */
    public function selectedStoreIds(User $target): array
    {
        return DB::table('store_user')
            ->join('stores', 'stores.id', '=', 'store_user.store_id')
            ->where('store_user.user_id', $target->getKey())
            ->where('store_user.is_active', true)
            ->where('stores.organization_id', $target->organization_id)
            ->whereNull('stores.deleted_at')
            ->orderBy('stores.code')
            ->pluck('store_user.store_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function statusLabels(): array
    {
        return [
            'active' => '在籍',
            'on_leave' => '休職',
            'retired' => '退職',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function roleLabels(): array
    {
        return [
            'staff' => 'スタッフ',
            'shift_manager' => 'シフト管理者',
            'system_admin' => 'システム管理者',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function staffListRoleLabels(): array
    {
        return array_intersect_key(
            $this->roleLabels(),
            array_flip(['staff', 'shift_manager']),
        );
    }

    private function applyCurrentMembershipScope(
        mixed $query,
        string $today,
    ): void {
        $query
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
}
