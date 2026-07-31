<?php

namespace App\Services\Admin;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminStaffWriteService
{
    public function __construct(
        private readonly StaffStoreMembershipService $membershipService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $attributes): User {
            $this->lockOrganization((int) $actor->organization_id);
            $email = $this->normalizeEmail((string) $attributes['email']);
            $this->assertEmailAvailable(
                $email,
                (int) $actor->organization_id,
            );

            try {
                $staff = User::query()->create([
                    'organization_id' => $actor->organization_id,
                    'name' => trim((string) $attributes['name']),
                    'email' => $email,
                    'password' => Hash::make((string) $attributes['password']),
                    'status' => (string) $attributes['status'],
                ]);
            } catch (QueryException $exception) {
                $this->throwEmailValidationException($exception);
            }

            $this->assignInitialRoles($staff, $actor, $attributes);
            $staff->load('roles');
            $this->syncMemberships(
                $staff,
                $this->integerIds($attributes['store_ids'] ?? []),
            );

            return $staff->refresh()->load(['roles', 'stores']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        User $actor,
        User $target,
        array $attributes,
    ): User {
        return DB::transaction(function () use (
            $actor,
            $target,
            $attributes,
        ): User {
            $this->lockOrganization((int) $actor->organization_id);
            $staff = User::query()
                ->whereKey($target->getKey())
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->first();

            if (! $staff instanceof User) {
                abort(403);
            }

            $staff->load('roles');
            Gate::forUser($actor)->authorize('update', $staff);
            $this->assertSystemAdminProtection(
                $actor,
                $staff,
                (string) $attributes['status'],
            );

            $email = $this->normalizeEmail((string) $attributes['email']);
            $this->assertEmailAvailable(
                $email,
                (int) $staff->organization_id,
                (int) $staff->getKey(),
            );

            $staff->forceFill([
                'name' => trim((string) $attributes['name']),
                'email' => $email,
                'status' => (string) $attributes['status'],
            ]);

            if (is_string($attributes['password'] ?? null)) {
                $staff->password = Hash::make($attributes['password']);
            }

            try {
                $staff->save();
            } catch (QueryException $exception) {
                $this->throwEmailValidationException($exception);
            }

            $this->updateRoles($staff, $actor, $attributes);
            $staff->unsetRelation('roles')->load('roles');
            $this->syncMemberships(
                $staff,
                $this->integerIds($attributes['store_ids'] ?? []),
            );

            return $staff->refresh()->load(['roles', 'stores']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assignInitialRoles(
        User $staff,
        User $actor,
        array $attributes,
    ): void {
        $roleIds = [$this->roleId('staff')];

        if (
            $actor->hasRole('system_admin')
            && (bool) ($attributes['shift_manager_role'] ?? false)
        ) {
            $roleIds[] = $this->roleId('shift_manager');
        }

        $staff->roles()->syncWithoutDetaching($roleIds);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateRoles(
        User $staff,
        User $actor,
        array $attributes,
    ): void {
        if ($actor->hasRole('system_admin')) {
            $this->setRole(
                $staff,
                'staff',
                (bool) ($attributes['staff_role'] ?? false),
            );
            $this->setRole(
                $staff,
                'shift_manager',
                (bool) ($attributes['shift_manager_role'] ?? false),
            );

            return;
        }

        if ((bool) ($attributes['staff_role'] ?? false)) {
            $staff->roles()->syncWithoutDetaching([$this->roleId('staff')]);
        }
    }

    private function setRole(User $staff, string $code, bool $enabled): void
    {
        $roleId = $this->roleId($code);

        if ($enabled) {
            $staff->roles()->syncWithoutDetaching([$roleId]);

            return;
        }

        $staff->roles()->detach($roleId);
    }

    private function roleId(string $code): int
    {
        return (int) Role::query()
            ->where('code', $code)
            ->firstOrFail()
            ->getKey();
    }

    /**
     * @param  list<int>  $desiredStoreIds
     */
    private function syncMemberships(
        User $staff,
        array $desiredStoreIds,
    ): void {
        $desiredStoreIds = collect($desiredStoreIds)->unique()->values()->all();
        $stores = Store::query()
            ->where('organization_id', $staff->organization_id)
            ->whereKey($desiredStoreIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Store $store): int => (int) $store->getKey());

        if ($stores->count() !== count($desiredStoreIds)) {
            throw ValidationException::withMessages([
                'store_ids' => '同一組織の有効な店舗だけを指定してください。',
            ]);
        }

        $existing = DB::table('store_user')
            ->where('user_id', $staff->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('store_id');
        $existingStoreIds = $existing
            ->pluck('store_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $existingStores = Store::query()
            ->where('organization_id', $staff->organization_id)
            ->whereKey($existingStoreIds)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Store $store): int => (int) $store->getKey());
        $today = $this->today();

        foreach ($desiredStoreIds as $storeId) {
            $store = $stores->get($storeId);
            $membership = $existing->get($storeId);

            if (! $store instanceof Store) {
                continue;
            }

            if (
                $membership !== null
                && $this->isCurrentMembership($membership, $today)
            ) {
                continue;
            }

            $this->membershipService->activate($store, $staff, $today);
        }

        foreach ($existing as $membership) {
            $storeId = (int) $membership->store_id;

            if (
                ! (bool) $membership->is_active
                || in_array($storeId, $desiredStoreIds, true)
            ) {
                continue;
            }

            $store = $existingStores->get($storeId);

            if ($store instanceof Store) {
                $this->membershipService->deactivate(
                    $store,
                    $staff,
                    $today,
                );
            }
        }
    }

    private function assertSystemAdminProtection(
        User $actor,
        User $target,
        string $nextStatus,
    ): void {
        if (! $target->hasRole('system_admin') || $nextStatus === 'active') {
            return;
        }

        if ((int) $actor->getKey() === (int) $target->getKey()) {
            throw ValidationException::withMessages([
                'status' => 'システム管理者自身を非在籍へ変更できません。',
            ]);
        }

        $activeSystemAdmins = User::query()
            ->where('organization_id', $target->organization_id)
            ->where('status', 'active')
            ->whereHas(
                'roles',
                fn ($query) => $query->where('roles.code', 'system_admin'),
            )
            ->count();

        if ($activeSystemAdmins <= 1) {
            throw ValidationException::withMessages([
                'status' => '同一組織の最後の有効なシステム管理者を非在籍へ変更できません。',
            ]);
        }
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

        if ($existing->trashed()) {
            $message = '過去に登録されたメールアドレスです。通常の新規登録では使用できません。';
        } elseif ((int) $existing->organization_id === $organizationId) {
            $message = '同一組織に既に登録されています。既存スタッフを編集してください。';
        } else {
            $message = 'このメールアドレスは使用できません。';
        }

        throw ValidationException::withMessages(['email' => $message]);
    }

    private function throwEmailValidationException(
        QueryException $exception,
    ): never {
        if (! $this->isUniqueConstraintViolation($exception)) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'email' => 'このメールアドレスは使用できません。',
        ]);
    }

    private function lockOrganization(int $organizationId): Organization
    {
        return Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function integerIds(array $ids): array
    {
        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function isCurrentMembership(object $membership, string $today): bool
    {
        return (bool) $membership->is_active
            && ($membership->started_on === null || $membership->started_on <= $today)
            && ($membership->ended_on === null || $membership->ended_on >= $today);
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array(
            (string) ($exception->errorInfo[0] ?? $exception->getCode()),
            ['23000', '23505'],
            true,
        );
    }
}
