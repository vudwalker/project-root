<?php

namespace App\Services\Admin;

use App\Exceptions\MonthlyMembersVersionConflictException;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleUser;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminShiftScheduleMemberService
{
    public function __construct(
        private readonly AdminShiftScheduleWriter $scheduleWriter,
    ) {}

    public function ensureInitialized(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
    ): ShiftSchedule {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
        ): ShiftSchedule {
            $schedule = $this->lockInitializedSchedule(
                $store,
                $actor,
                $targetMonth,
            );

            return $schedule->refresh();
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function screen(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
    ): array {
        $schedule = $this->ensureInitialized($store, $actor, $targetMonth);
        $scheduleId = (int) $schedule->getKey();
        $monthlyRows = ShiftScheduleUser::query()
            ->where('shift_schedule_id', $scheduleId)
            ->orderBy('display_order')
            ->orderBy('user_id')
            ->get();
        $monthlyUserIds = $monthlyRows->pluck('user_id')->map(
            fn (mixed $id): int => (int) $id,
        )->all();
        $shiftUserIds = Shift::query()
            ->where('shift_schedule_id', $scheduleId)
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $allUserIds = array_values(array_unique([
            ...$monthlyUserIds,
            ...$shiftUserIds,
        ]));
        $users = User::withTrashed()
            ->whereKey($allUserIds)
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->getKey());
        $eligibleIds = $this->eligibleStaffQuery($store, $targetMonth)
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $shiftLookup = array_fill_keys($shiftUserIds, true);

        $members = $monthlyRows
            ->map(function (ShiftScheduleUser $row) use (
                $users,
                $eligibleLookup,
                $shiftLookup,
            ): ?array {
                $user = $users->get((int) $row->user_id);

                if (! $user instanceof User) {
                    return null;
                }

                return [
                    'id' => (int) $user->getKey(),
                    'name' => (string) $user->name,
                    'status' => (string) $user->status,
                    'displayOrder' => (int) $row->display_order,
                    'canCreateShifts' => isset($eligibleLookup[(int) $user->getKey()]),
                    'hasExistingShifts' => isset($shiftLookup[(int) $user->getKey()]),
                    'isMonthlyMember' => true,
                ];
            })
            ->filter()
            ->values()
            ->all();
        $memberLookup = array_fill_keys($monthlyUserIds, true);
        $existingOnly = collect($shiftUserIds)
            ->filter(fn (int $userId): bool => ! isset($memberLookup[$userId]))
            ->map(fn (int $userId): ?array => $this->userRow(
                $users->get($userId),
                false,
                false,
                true,
                null,
            ))
            ->filter()
            ->sortBy(fn (array $row): string => sprintf(
                '%s|%010d',
                $row['name'],
                $row['id'],
            ))
            ->values()
            ->all();
        $candidateUsers = $this->eligibleStaffQuery($store, $targetMonth)
            ->whereNotIn('users.id', $monthlyUserIds === [] ? [0] : $monthlyUserIds)
            ->select(['users.id', 'users.name', 'users.status'])
            ->get()
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'status' => (string) $user->status,
            ])
            ->all();

        return [
            'scheduleId' => $scheduleId,
            'storeId' => (int) $store->getKey(),
            'storeCode' => (string) $store->code,
            'storeName' => (string) $store->name,
            'targetMonth' => $targetMonth->format('Y-m'),
            'monthlyMembersVersion' => (int) $schedule->monthly_members_version,
            'members' => $members,
            'existingOnly' => $existingOnly,
            'candidates' => $candidateUsers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        int $userId,
        int $expectedVersion,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $userId,
            $expectedVersion,
        ): array {
            $schedule = $this->lockInitializedSchedule($store, $actor, $targetMonth);
            $this->assertVersion($schedule, $expectedVersion);
            $staff = $this->eligibleStaffQuery($store, $targetMonth)
                ->whereKey($userId)
                ->first();

            if (! $staff instanceof User) {
                throw ValidationException::withMessages([
                    'user_id' => '対象月に追加できるスタッフではありません。',
                ]);
            }

            $exists = ShiftScheduleUser::query()
                ->where('shift_schedule_id', $schedule->getKey())
                ->where('user_id', $userId)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'user_id' => 'このスタッフは既に対象月へ追加されています。',
                ]);
            }

            $nextOrder = (int) ShiftScheduleUser::query()
                ->where('shift_schedule_id', $schedule->getKey())
                ->max('display_order') + 1;

            ShiftScheduleUser::query()->create([
                'shift_schedule_id' => $schedule->getKey(),
                'user_id' => $userId,
                'display_order' => $nextOrder,
            ]);

            $schedule = $this->markMembersChanged($schedule, $actor);

            return $this->payload($schedule);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        int $userId,
        int $expectedVersion,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $userId,
            $expectedVersion,
        ): array {
            $schedule = $this->lockInitializedSchedule($store, $actor, $targetMonth);
            $this->assertVersion($schedule, $expectedVersion);
            $member = ShiftScheduleUser::query()
                ->where('shift_schedule_id', $schedule->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $member instanceof ShiftScheduleUser) {
                throw ValidationException::withMessages([
                    'user_id' => '対象月の表示スタッフではありません。',
                ]);
            }

            $member->delete();
            $schedule = $this->markMembersChanged($schedule, $actor);

            return $this->payload($schedule);
        }, 3);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    public function reorder(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
        array $userIds,
        int $expectedVersion,
    ): array {
        return DB::transaction(function () use (
            $store,
            $actor,
            $targetMonth,
            $userIds,
            $expectedVersion,
        ): array {
            $schedule = $this->lockInitializedSchedule($store, $actor, $targetMonth);
            $this->assertVersion($schedule, $expectedVersion);
            $currentIds = ShiftScheduleUser::query()
                ->where('shift_schedule_id', $schedule->getKey())
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $requestedIds = array_map('intval', $userIds);

            if (
                count($requestedIds) !== count(array_unique($requestedIds))
                || count($requestedIds) !== count($currentIds)
                || array_diff($requestedIds, $currentIds) !== []
                || array_diff($currentIds, $requestedIds) !== []
            ) {
                throw ValidationException::withMessages([
                    'user_ids' => '対象月の表示スタッフだけを、重複なく指定してください。',
                ]);
            }

            foreach ($requestedIds as $index => $userId) {
                ShiftScheduleUser::query()
                    ->where('shift_schedule_id', $schedule->getKey())
                    ->where('user_id', $userId)
                    ->update([
                        'display_order' => $index,
                        'updated_at' => now(),
                    ]);
            }

            $schedule = $this->markMembersChanged($schedule, $actor);

            return $this->payload($schedule);
        }, 3);
    }

    public function assertMonthlyMember(ShiftSchedule $schedule, int $userId): void
    {
        $exists = ShiftScheduleUser::query()
            ->where('shift_schedule_id', $schedule->getKey())
            ->where('user_id', $userId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'user_id' => '対象月の表示スタッフではありません。',
            ]);
        }
    }

    private function lockInitializedSchedule(
        Store $store,
        User $actor,
        CarbonImmutable $targetMonth,
    ): ShiftSchedule {
        $schedule = $this->scheduleWriter->lockOrCreateSchedule(
            $store,
            $actor,
            $targetMonth,
        );

        if ($schedule->monthly_members_initialized_at === null) {
            $this->initializeLocked($schedule, $store, $targetMonth);
        }

        return $schedule->refresh();
    }

    private function initializeLocked(
        ShiftSchedule $schedule,
        Store $store,
        CarbonImmutable $targetMonth,
    ): void {
        $members = $this->eligibleStaffQuery($store, $targetMonth)
            ->select(['users.id'])
            ->get();
        $timestamp = now();

        foreach ($members->values() as $index => $member) {
            ShiftScheduleUser::query()->insert([
                'shift_schedule_id' => $schedule->getKey(),
                'user_id' => $member->getKey(),
                'display_order' => $index,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $schedule->forceFill([
            'monthly_members_initialized_at' => $timestamp,
            'monthly_members_version' => 0,
        ])->save();
    }

    /**
     * @return Builder<User>
     */
    private function eligibleStaffQuery(
        Store $store,
        CarbonImmutable $targetMonth,
    ): Builder {
        $monthStart = $targetMonth->startOfMonth()->toDateString();
        $monthEnd = $targetMonth->endOfMonth()->toDateString();

        return User::query()
            ->join('store_user', function ($join) use ($store): void {
                $join
                    ->on('store_user.user_id', '=', 'users.id')
                    ->where('store_user.store_id', '=', $store->getKey());
            })
            ->where('users.organization_id', $store->organization_id)
            ->where('users.status', 'active')
            ->where('store_user.is_active', true)
            ->whereHas(
                'roles',
                fn (Builder $query): Builder => $query->where('roles.code', 'staff'),
            )
            ->where(function (Builder $period) use ($monthEnd): void {
                $period
                    ->whereNull('store_user.started_on')
                    ->orWhereDate('store_user.started_on', '<=', $monthEnd);
            })
            ->where(function (Builder $period) use ($monthStart): void {
                $period
                    ->whereNull('store_user.ended_on')
                    ->orWhereDate('store_user.ended_on', '>=', $monthStart);
            })
            ->select('users.*')
            ->orderBy('store_user.display_order')
            ->orderBy('users.name')
            ->orderBy('users.id');
    }

    private function assertVersion(
        ShiftSchedule $schedule,
        int $expectedVersion,
    ): void {
        $currentVersion = (int) $schedule->monthly_members_version;

        if ($currentVersion !== $expectedVersion) {
            throw new MonthlyMembersVersionConflictException(
                $expectedVersion,
                $currentVersion,
            );
        }
    }

    private function markMembersChanged(
        ShiftSchedule $schedule,
        User $actor,
    ): ShiftSchedule {
        $schedule->forceFill([
            'monthly_members_version' => (int) $schedule->monthly_members_version + 1,
            'updated_by' => $actor->getKey(),
        ])->save();

        return $schedule->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ShiftSchedule $schedule): array
    {
        return [
            'shift_schedule_id' => (int) $schedule->getKey(),
            'monthly_members_version' => (int) $schedule->monthly_members_version,
            'members' => $schedule->scheduleUsers()
                ->with(['user' => fn ($query) => $query->withTrashed()])
                ->get()
                ->map(fn (ShiftScheduleUser $row): array => [
                    'id' => (int) $row->user_id,
                    'name' => (string) $row->user?->name,
                    'displayOrder' => (int) $row->display_order,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userRow(
        ?User $user,
        bool $canCreateShifts,
        bool $isMonthlyMember,
        bool $hasExistingShifts,
        ?int $displayOrder,
    ): ?array {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'status' => (string) $user->status,
            'displayOrder' => $displayOrder,
            'canCreateShifts' => $canCreateShifts,
            'hasExistingShifts' => $hasExistingShifts,
            'isMonthlyMember' => $isMonthlyMember,
        ];
    }
}
