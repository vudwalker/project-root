<?php

namespace App\Services\Admin;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminShiftManagerManagementService
{
    /**
     * @param  array{query?: string|null, status?: string|null, store_id?: int|null, role?: string|null}  $filters
     * @return array{stores: Collection<int, Store>, managers: Collection<int, User>}
     */
    public function screen(User $actor, array $filters = []): array
    {
        $today = $this->today();
        $stores = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->orderBy('code')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);
        $managers = User::query()
            ->where('organization_id', $actor->organization_id)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'roles.code',
                'shift_manager',
            ))
            ->when(
                $filters['query'] ?? null,
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
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where(
                    'users.status',
                    $status,
                ),
            )
            ->when(
                $filters['store_id'] ?? null,
                function (Builder $query, int $storeId) use ($today): void {
                    $query->whereHas(
                        'managedStores',
                        function (Builder $stores) use ($storeId, $today): void {
                            $stores->whereKey($storeId);
                            $this->applyManagedStoreScope($stores, $today);
                        },
                    );
                },
            )
            ->with([
                'roles:id,code,name',
                'managedStores' => function ($query) use ($actor, $today): void {
                    $query->where('stores.organization_id', $actor->organization_id);
                    $this->applyManagedStoreScope($query, $today);
                    $query->orderBy('stores.code')->orderBy('stores.id');
                },
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'name', 'email', 'status']);

        return compact('stores', 'managers');
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
        ];
    }

    /**
     * @return list<int>
     */
    public function selectedManagedStoreIds(User $target): array
    {
        return $target->managedStores()
            ->wherePivot('is_active', true)
            ->pluck('stores.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array{name: string, email: string, password: string, store_ids?: list<int>}  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $attributes): User {
            $organizationId = (int) $actor->organization_id;
            $email = $this->normalizeEmail((string) $attributes['email']);
            $this->assertEmailAvailable($email, $organizationId);
            $storeIds = $this->normalizeIds($attributes['store_ids'] ?? []);
            $this->validateStoreIds(['new' => $storeIds], $organizationId);

            try {
                $manager = User::query()->create([
                    'organization_id' => $organizationId,
                    'name' => trim((string) $attributes['name']),
                    'email' => $email,
                    'password' => Hash::make((string) $attributes['password']),
                    'status' => 'active',
                ]);
            } catch (QueryException $exception) {
                $this->throwEmailValidationException($exception);
            }

            $manager->roles()->syncWithoutDetaching([$this->roleId('shift_manager')]);
            $this->syncAssignments((int) $manager->getKey(), $storeIds);

            return $manager->refresh()->load('roles');
        }, 3);
    }

    /**
     * @param  array{name: string, email: string, status: string, password?: string|null}  $attributes
     */
    public function updateProfile(
        User $actor,
        User $target,
        array $attributes,
    ): User {
        return DB::transaction(function () use (
            $actor,
            $target,
            $attributes,
        ): User {
            $manager = User::query()
                ->whereKey($target->getKey())
                ->where('organization_id', $actor->organization_id)
                ->with('roles')
                ->lockForUpdate()
                ->first();

            if (! $manager instanceof User
                || ! $manager->hasRole('shift_manager')
                || $manager->hasRole('system_admin')
            ) {
                abort(404);
            }

            $email = $this->normalizeEmail((string) $attributes['email']);
            $this->assertEmailAvailable(
                $email,
                (int) $manager->organization_id,
                (int) $manager->getKey(),
            );

            $manager->forceFill([
                'name' => trim((string) $attributes['name']),
                'email' => $email,
                'status' => (string) $attributes['status'],
            ]);

            if (is_string($attributes['password'] ?? null)) {
                $manager->password = Hash::make($attributes['password']);
            }

            try {
                $manager->save();
            } catch (QueryException $exception) {
                $this->throwEmailValidationException($exception);
            }

            if (array_key_exists('store_ids', $attributes)) {
                $storeIds = $this->normalizeIds($attributes['store_ids'] ?? []);
                $this->validateStoreIds(
                    ['manager' => $storeIds],
                    (int) $manager->organization_id,
                );
                $this->syncAssignments(
                    (int) $manager->getKey(),
                    $storeIds,
                );
            }

            return $manager->refresh()->load(['roles', 'managedStores']);
        }, 3);
    }

    /**
     * @param  array{manager_user_ids?: list<int>, store_ids?: array<string, list<int>>}  $attributes
     */
    public function update(User $actor, array $attributes): void
    {
        DB::transaction(function () use ($actor, $attributes): void {
            $organizationId = (int) $actor->organization_id;
            $managerIds = collect($attributes['manager_user_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $storeIdsByUser = $attributes['store_ids'] ?? [];
            $currentManagerIds = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->join('users', 'users.id', '=', 'role_user.user_id')
                ->where('users.organization_id', $organizationId)
                ->where('roles.code', 'shift_manager')
                ->lockForUpdate()
                ->pluck('role_user.user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $affectedIds = array_values(array_unique([
                ...$currentManagerIds,
                ...$managerIds,
            ]));
            $users = User::query()
                ->where('organization_id', $organizationId)
                ->whereKey($affectedIds)
                ->with('roles')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): int => (int) $user->getKey());

            if ($users->count() !== count($affectedIds)) {
                throw ValidationException::withMessages([
                    'manager_user_ids' => '同一組織のユーザーだけを選択してください。',
                ]);
            }

            foreach ($managerIds as $managerId) {
                $user = $users->get($managerId);

                if (! $user instanceof User || $user->hasRole('system_admin')) {
                    throw ValidationException::withMessages([
                        'manager_user_ids' => 'システム管理者をシフト管理者には設定できません。',
                    ]);
                }

                if (! in_array($managerId, $currentManagerIds, true)
                    && $user->status !== 'active'
                ) {
                    throw ValidationException::withMessages([
                        'manager_user_ids' => '新しく追加できるのは在籍中のユーザーだけです。',
                    ]);
                }
            }

            $shiftManagerRoleId = (int) Role::query()
                ->where('code', 'shift_manager')
                ->value('id');
            $this->validateStoreIds($storeIdsByUser, $organizationId);

            foreach ($users as $user) {
                $userId = (int) $user->getKey();
                $isManager = in_array($userId, $managerIds, true);

                if ($isManager) {
                    $user->roles()->syncWithoutDetaching([$shiftManagerRoleId]);
                } else {
                    $user->roles()->detach($shiftManagerRoleId);
                }

                $this->syncAssignments(
                    $userId,
                    $isManager ? ($storeIdsByUser[(string) $userId] ?? []) : [],
                );
            }
        }, 3);
    }

    /**
     * @param  array<string, list<int>>  $storeIdsByUser
     */
    private function validateStoreIds(array $storeIdsByUser, int $organizationId): void
    {
        $storeIds = collect($storeIdsByUser)
            ->flatten()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $actualCount = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeIds->all())
            ->count();

        if ($actualCount !== $storeIds->count()) {
            throw ValidationException::withMessages([
                'store_ids' => '同一組織の店舗だけを選択してください。',
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function roleId(string $code): int
    {
        return (int) Role::query()
            ->where('code', $code)
            ->firstOrFail()
            ->getKey();
    }

    private function applyManagedStoreScope(mixed $query, string $today): void
    {
        $query
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

    private function assertEmailAvailable(
        string $email,
        int $organizationId,
        ?int $exceptUserId = null,
    ): void {
        $existing = User::withTrashed()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->when(
                $exceptUserId !== null,
                fn ($query) => $query->whereKeyNot($exceptUserId),
            )
            ->lockForUpdate()
            ->first();

        if (! $existing instanceof User) {
            return;
        }

        $message = $existing->trashed()
            ? '過去に登録されたメールアドレスです。通常の新規登録では使用できません。'
            : ((int) $existing->organization_id === $organizationId
                ? '同一組織に既に登録されています。既存ユーザーを編集してください。'
                : 'このメールアドレスは使用できません。');

        throw ValidationException::withMessages(['email' => $message]);
    }

    private function throwEmailValidationException(QueryException $exception): never
    {
        $message = $exception->getCode() === '23505'
            || str_contains(strtolower($exception->getMessage()), 'unique');

        if (! $message) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'email' => 'このメールアドレスは使用できません。',
        ]);
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @param  list<int>  $desiredStoreIds
     */
    private function syncAssignments(int $userId, array $desiredStoreIds): void
    {
        $today = $this->today();
        $now = now();
        $desiredStoreIds = collect($desiredStoreIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $existing = DB::table('store_shift_manager')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->get()
            ->keyBy('store_id');

        foreach ($desiredStoreIds as $storeId) {
            $pivot = $existing->get($storeId);

            if ($pivot !== null) {
                DB::table('store_shift_manager')
                    ->where('id', $pivot->id)
                    ->update([
                        'is_active' => true,
                        'started_on' => $pivot->started_on !== null
                            && $pivot->started_on <= $today
                                ? $pivot->started_on
                                : $today,
                        'ended_on' => null,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('store_shift_manager')->insert([
                'store_id' => $storeId,
                'user_id' => $userId,
                'is_active' => true,
                'started_on' => $today,
                'ended_on' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($existing as $pivot) {
            if (in_array((int) $pivot->store_id, $desiredStoreIds, true)) {
                continue;
            }

            if (! $pivot->is_active && $pivot->ended_on !== null) {
                continue;
            }

            DB::table('store_shift_manager')
                ->where('id', $pivot->id)
                ->update([
                    'is_active' => false,
                    'ended_on' => $pivot->ended_on !== null
                        && $pivot->ended_on < $today
                            ? $pivot->ended_on
                            : $today,
                    'updated_at' => $now,
                ]);
        }
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }
}
