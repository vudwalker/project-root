<?php

namespace App\Services\Staff;

use App\Models\PublishedShift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class PublishedShiftReadService
{
    /**
     * @return array{
     *     stores: array<string, array{id: int, code: string, name: string}>,
     *     personalShifts: array<string, list<array<string, mixed>>>
     * }
     */
    public function personalScreen(
        User $user,
        CarbonImmutable $targetMonth,
    ): array {
        return [
            'stores' => $this->projectStores($this->accessibleStores($user)),
            'personalShifts' => $this->personalShifts($user, $targetMonth),
        ];
    }

    /**
     * @return array{
     *     stores: array<string, array{id: int, code: string, name: string}>,
     *     store: array{id: int, code: string, name: string, staff: list<array<string, mixed>>}
     * }|null
     */
    public function storeScreen(
        User $user,
        string $storeCode,
        CarbonImmutable $targetMonth,
    ): ?array {
        $accessibleStores = $this->accessibleStores($user);
        $store = $accessibleStores->first(
            fn (Store $availableStore): bool => $availableStore->code === $storeCode,
        );

        if (! $store instanceof Store) {
            return null;
        }

        return [
            'stores' => $this->projectStores($accessibleStores),
            'store' => $this->storeWithPublishedShifts($store, $targetMonth),
        ];
    }

    /**
     * @return EloquentCollection<int, Store>
     */
    private function accessibleStores(User $user): EloquentCollection
    {
        $today = $this->today();

        return $user->stores()
            ->where('stores.organization_id', $user->organization_id)
            ->where('stores.status', 'active')
            ->where('store_user.is_active', true)
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('store_user.started_on')
                    ->orWhereDate('store_user.started_on', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('store_user.ended_on')
                    ->orWhereDate('store_user.ended_on', '>=', $today);
            })
            ->orderBy('store_user.display_order')
            ->orderBy('stores.display_order')
            ->orderBy('stores.name')
            ->orderBy('stores.id')
            ->get([
                'stores.id',
                'stores.organization_id',
                'stores.code',
                'stores.name',
                'stores.status',
                'stores.display_order',
            ]);
    }

    /**
     * @param  EloquentCollection<int, Store>  $stores
     * @return array<string, array{id: int, code: string, name: string}>
     */
    private function projectStores(EloquentCollection $stores): array
    {
        $projected = [];

        foreach ($stores as $store) {
            $projected[$store->code] = [
                'id' => (int) $store->getKey(),
                'code' => $store->code,
                'name' => $store->name,
            ];
        }

        return $projected;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function personalShifts(
        User $user,
        CarbonImmutable $targetMonth,
    ): array {
        $rows = $this->publishedRows($targetMonth)
            ->join(
                'stores as publication_stores',
                'publication_stores.id',
                '=',
                'publication_schedules.store_id',
            )
            ->where('published_shifts.user_id', $user->getKey())
            ->where('publication_stores.organization_id', $user->organization_id)
            ->whereNull('publication_stores.deleted_at')
            ->orderBy('published_shifts.work_date')
            ->orderBy('publication_stores.display_order')
            ->orderBy('publication_schedules.store_id')
            ->orderBy('published_shifts.sequence')
            ->orderBy('published_shifts.id')
            ->get([
                'published_shifts.id',
                'published_shifts.shift_schedule_id',
                'published_shifts.user_id',
                'published_shifts.work_date',
                'published_shifts.sequence',
                'published_shifts.pattern_code',
                'publication_schedules.store_id',
                'publication_stores.code as store_code',
                'publication_stores.name as store_name',
            ]);
        $projected = [];

        foreach ($rows as $row) {
            $workDate = $row->work_date->toDateString();
            $projected[$workDate][] = [
                'shift_schedule_id' => (int) $row->shift_schedule_id,
                'user_id' => (int) $row->user_id,
                'work_date' => $workDate,
                'sequence' => (int) $row->sequence,
                'store_id' => (int) $row->store_id,
                'store_code' => (string) $row->store_code,
                'store_name' => (string) $row->store_name,
                'shift_type' => $this->shiftType((string) $row->pattern_code),
            ];
        }

        return $projected;
    }

    /**
     * @return array{id: int, code: string, name: string, staff: list<array<string, mixed>>}
     */
    private function storeWithPublishedShifts(
        Store $store,
        CarbonImmutable $targetMonth,
    ): array {
        $staffMembers = $this->currentStaffMembers($store);
        $rows = $staffMembers->isEmpty()
            ? collect()
            : $this->publishedRows($targetMonth)
                ->where('publication_schedules.store_id', $store->getKey())
                ->whereIn('published_shifts.user_id', $staffMembers->modelKeys())
                ->orderBy('published_shifts.user_id')
                ->orderBy('published_shifts.work_date')
                ->orderBy('published_shifts.sequence')
                ->orderBy('published_shifts.id')
                ->get([
                    'published_shifts.id',
                    'published_shifts.shift_schedule_id',
                    'published_shifts.user_id',
                    'published_shifts.work_date',
                    'published_shifts.sequence',
                    'published_shifts.pattern_code',
                ]);
        $rowsByUser = $rows->groupBy(
            fn (PublishedShift $row): int => (int) $row->user_id,
        );
        $staff = [];

        foreach ($staffMembers as $staffMember) {
            $shifts = [];
            $userRows = $rowsByUser->get((int) $staffMember->getKey(), collect());

            foreach ($userRows->groupBy(
                fn (PublishedShift $row): string => $row->work_date->toDateString(),
            ) as $workDate => $dayRows) {
                /** @var PublishedShift|null $row */
                $row = $dayRows->first();

                if (! $row instanceof PublishedShift) {
                    continue;
                }

                $shifts[$workDate] = [
                    'shift_schedule_id' => (int) $row->shift_schedule_id,
                    'user_id' => (int) $row->user_id,
                    'work_date' => $workDate,
                    'sequence' => (int) $row->sequence,
                    'shift_type' => $this->shiftType($row->pattern_code),
                ];
            }

            $staff[] = [
                'id' => (int) $staffMember->getKey(),
                'name' => $staffMember->name,
                'shifts' => $shifts,
            ];
        }

        return [
            'id' => (int) $store->getKey(),
            'code' => $store->code,
            'name' => $store->name,
            'staff' => $staff,
        ];
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function currentStaffMembers(Store $store): EloquentCollection
    {
        $today = $this->today();

        return $store->staffMembers()
            ->where('users.organization_id', $store->organization_id)
            ->where('users.status', 'active')
            ->where('store_user.is_active', true)
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('store_user.started_on')
                    ->orWhereDate('store_user.started_on', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('store_user.ended_on')
                    ->orWhereDate('store_user.ended_on', '>=', $today);
            })
            ->whereHas(
                'roles',
                fn (Builder $query): Builder => $query->where('roles.code', 'staff'),
            )
            ->orderBy('store_user.display_order')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.organization_id',
                'users.name',
                'users.status',
            ]);
    }

    /**
     * 公開メタデータと同じ配布時刻を持つ、現在の公開スナップショットだけを読む。
     *
     * @return Builder<PublishedShift>
     */
    private function publishedRows(CarbonImmutable $targetMonth): Builder
    {
        return PublishedShift::query()
            ->join(
                'shift_schedules as publication_schedules',
                'publication_schedules.id',
                '=',
                'published_shifts.shift_schedule_id',
            )
            ->whereDate(
                'publication_schedules.target_month',
                $targetMonth->startOfMonth()->toDateString(),
            )
            ->whereBetween('published_shifts.work_date', [
                $targetMonth->startOfMonth()->toDateString(),
                $targetMonth->endOfMonth()->toDateString(),
            ])
            ->where('publication_schedules.published_version', '>=', 1)
            ->whereNotNull('publication_schedules.published_draft_version')
            ->whereNotNull('publication_schedules.published_at')
            ->whereColumn(
                'publication_schedules.published_draft_version',
                '<=',
                'publication_schedules.draft_version',
            )
            ->whereColumn(
                'published_shifts.published_at',
                'publication_schedules.published_at',
            );
    }

    /**
     * @return array{code: string}
     */
    private function shiftType(string $patternCode): array
    {
        return ['code' => $patternCode];
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }
}
