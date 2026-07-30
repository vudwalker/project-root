<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\StoreStaffingRequirementOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminStoreAssociationService
{
    public function addStaffMembership(Store $store, int $userId): void
    {
        $this->assertEligibleUsers($store, [$userId], 'staff');

        DB::transaction(function () use ($store, $userId): void {
            $this->lockStore($store);
            $existing = DB::table('store_user')
                ->where('store_id', $store->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            $today = $this->today();

            if ($this->isCurrentMembership($existing, $today)) {
                throw ValidationException::withMessages([
                    'staff_user_id' => 'このスタッフは既に所属しています。',
                ]);
            }

            $now = now();

            if ($existing !== null) {
                DB::table('store_user')
                    ->where('id', $existing->id)
                    ->update([
                        'is_active' => true,
                        'started_on' => $existing->started_on !== null
                            && $existing->started_on <= $today
                                ? $existing->started_on
                                : $today,
                        'ended_on' => null,
                        'updated_at' => $now,
                    ]);

                return;
            }

            $nextOrder = (int) DB::table('store_user')
                ->where('store_id', $store->getKey())
                ->max('display_order');

            DB::table('store_user')->insert([
                'store_id' => $store->getKey(),
                'user_id' => $userId,
                'display_order' => $nextOrder + 1,
                'is_active' => true,
                'started_on' => $today,
                'ended_on' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function removeStaffMembership(Store $store, int $userId): void
    {
        DB::transaction(function () use ($store, $userId): void {
            $this->lockStore($store);
            $existing = DB::table('store_user')
                ->where('store_id', $store->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            $today = $this->today();

            if (! $this->isCurrentMembership($existing, $today)) {
                throw ValidationException::withMessages([
                    'staff_user_id' => '現在所属しているスタッフではありません。',
                ]);
            }

            $staff = User::query()
                ->whereKey($userId)
                ->where('organization_id', $store->organization_id)
                ->first();

            if (! $staff instanceof User) {
                throw ValidationException::withMessages([
                    'staff_user_id' => '対象店舗の所属スタッフではありません。',
                ]);
            }

            if ((int) $staff->primary_store_id === (int) $store->getKey()) {
                throw ValidationException::withMessages([
                    'staff_user_id' => '主所属店舗になっているスタッフは所属解除できません。',
                ]);
            }

            $endedOn = $existing->started_on !== null
                && $existing->started_on > $today
                    ? $existing->started_on
                    : $today;

            DB::table('store_user')
                ->where('id', $existing->id)
                ->update([
                    'is_active' => false,
                    'ended_on' => $endedOn,
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    public function updateShiftManagers(Store $store, array $userIds): void
    {
        $this->assertEligibleUsers($store, $userIds, 'shift_manager');

        DB::transaction(function () use ($store, $userIds): void {
            $this->lockStore($store);
            $existing = DB::table('store_shift_manager')
                ->where('store_id', $store->getKey())
                ->get()
                ->keyBy('user_id');
            $today = $this->today();
            $now = now();

            foreach ($userIds as $userId) {
                $pivot = $existing->get($userId);

                if ($pivot !== null) {
                    DB::table('store_shift_manager')
                        ->where('id', $pivot->id)
                        ->update([
                            'is_active' => true,
                            'started_on' => $pivot->started_on ?? $today,
                            'ended_on' => null,
                            'updated_at' => $now,
                        ]);

                    continue;
                }

                DB::table('store_shift_manager')->insert([
                    'store_id' => $store->getKey(),
                    'user_id' => $userId,
                    'is_active' => true,
                    'started_on' => $today,
                    'ended_on' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $removedPivots = $existing
                ->where('is_active', true)
                ->reject(
                    fn (object $pivot): bool => in_array(
                        (int) $pivot->user_id,
                        $userIds,
                        true,
                    ),
                );

            foreach ($removedPivots as $pivot) {
                $endedOn = $pivot->started_on !== null
                    && $pivot->started_on > $today
                        ? $pivot->started_on
                        : $today;

                DB::table('store_shift_manager')
                    ->where('id', $pivot->id)
                    ->update([
                        'is_active' => false,
                        'ended_on' => $endedOn,
                        'updated_at' => $now,
                    ]);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $patterns
     */
    public function updateShiftPatterns(Store $store, array $patterns): void
    {
        DB::transaction(function () use ($store, $patterns): void {
            $this->lockStore($store);
            $existing = $store->shiftPatterns()
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $submittedCodes = [];

            foreach ($patterns as $index => $attributes) {
                $code = trim((string) ($attributes['code'] ?? ''));
                $id = isset($attributes['id'])
                    ? (int) $attributes['id']
                    : null;

                if ($code === '' && $id === null) {
                    continue;
                }

                if ($code === '') {
                    throw ValidationException::withMessages([
                        "patterns.{$index}.code" => 'パターンコードを入力してください。',
                    ]);
                }

                if (in_array($code, $submittedCodes, true)) {
                    throw ValidationException::withMessages([
                        "patterns.{$index}.code" => 'パターンコードが重複しています。',
                    ]);
                }
                $submittedCodes[] = $code;

                $pattern = $id === null ? null : $existing->get($id);

                if ($id !== null && ! $pattern instanceof StoreShiftPattern) {
                    throw ValidationException::withMessages([
                        "patterns.{$index}.id" => '対象店舗のシフトパターンではありません。',
                    ]);
                }

                if (
                    $store->shiftPatterns()
                        ->withTrashed()
                        ->where('code', $code)
                        ->when($pattern, fn ($query) => $query->whereKeyNot(
                            $pattern->getKey(),
                        ))
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        "patterns.{$index}.code" => 'このパターンコードは既に使用されています。',
                    ]);
                }

                $startTime = $attributes['start_time'] ?? null;
                $endTime = $attributes['end_time'] ?? null;

                if (($startTime === null) xor ($endTime === null)) {
                    throw ValidationException::withMessages([
                        "patterns.{$index}.start_time" => '開始時刻と終了時刻は両方入力してください。',
                    ]);
                }

                $values = [
                    'code' => $code,
                    'start_time' => $startTime,
                    'start_day_offset' => $startTime === null ? null : 0,
                    'end_time' => $endTime,
                    'end_day_offset' => $endTime === null
                        ? null
                        : (int) ($attributes['ends_next_day'] ?? 0),
                    'display_order' => (int) ($attributes['display_order'] ?? 0),
                    'is_active' => (bool) ($attributes['is_active'] ?? false),
                ];

                if ($pattern instanceof StoreShiftPattern) {
                    $pattern->fill($values)->save();

                    continue;
                }

                $store->shiftPatterns()->create([
                    ...$values,
                    'work_minutes' => 0,
                ]);
            }
        });
    }

    /**
     * @param  array{
     *     staffing_check_mode: string,
     *     required_staff_count?: int|null,
     *     staffing_options?: list<array<string, mixed>>
     * }  $attributes
     */
    public function updateStaffing(Store $store, array $attributes): void
    {
        DB::transaction(function () use ($store, $attributes): void {
            $lockedStore = $this->lockStore($store);
            $mode = $attributes['staffing_check_mode'];

            $lockedStore->forceFill([
                'staffing_check_mode' => $mode,
                'required_staff_count' => $mode === 'fixed_total'
                    ? $attributes['required_staff_count']
                    : null,
            ])->save();

            if ($mode !== 'pattern_combinations') {
                return;
            }

            $options = $attributes['staffing_options'] ?? [];

            if ($options === []) {
                return;
            }

            $requirement = $lockedStore->staffingRequirements()
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
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $requirement instanceof StoreStaffingRequirement) {
                $requirement = $lockedStore->staffingRequirements()->create([
                    'work_date' => null,
                    'weekday' => null,
                    'effective_from' => $this->today(),
                    'effective_to' => null,
                    'is_active' => true,
                ]);
            }

            $existingOptions = $requirement->options()
                ->with('patterns')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $codes = [];

            foreach ($options as $index => $optionAttributes) {
                $id = isset($optionAttributes['id'])
                    ? (int) $optionAttributes['id']
                    : null;
                $option = $id === null ? null : $existingOptions->get($id);

                if (
                    $id !== null
                    && ! $option instanceof StoreStaffingRequirementOption
                ) {
                    throw ValidationException::withMessages([
                        "staffing_options.{$index}.id" => '対象店舗の人数配置選択肢ではありません。',
                    ]);
                }

                if ((bool) ($optionAttributes['remove'] ?? false)) {
                    $option?->delete();

                    continue;
                }

                $code = trim((string) ($optionAttributes['code'] ?? ''));

                if ($code === '' && $id === null) {
                    continue;
                }

                if ($code === '' || in_array($code, $codes, true)) {
                    throw ValidationException::withMessages([
                        "staffing_options.{$index}.code" => '選択肢コードを重複なく入力してください。',
                    ]);
                }
                $codes[] = $code;

                if (
                    $requirement->options()
                        ->where('code', $code)
                        ->when($option, fn ($query) => $query->whereKeyNot(
                            $option->getKey(),
                        ))
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        "staffing_options.{$index}.code" => 'この選択肢コードは既に使用されています。',
                    ]);
                }

                if (! $option instanceof StoreStaffingRequirementOption) {
                    $option = $requirement->options()->create([
                        'code' => $code,
                        'display_order' => (int) ($optionAttributes['display_order'] ?? 0),
                    ]);
                } else {
                    $option->fill([
                        'code' => $code,
                        'display_order' => (int) ($optionAttributes['display_order'] ?? 0),
                    ])->save();
                }

                $patternCounts = collect($optionAttributes['pattern_counts'] ?? [])
                    ->filter(fn (mixed $count): bool => $count !== null && $count !== '')
                    ->mapWithKeys(
                        fn (mixed $count, mixed $patternId): array => [
                            (int) $patternId => (int) $count,
                        ],
                    );
                $patternIds = $patternCounts->keys()->all();
                $eligibleCount = $lockedStore->shiftPatterns()
                    ->whereKey($patternIds)
                    ->count();

                if ($eligibleCount !== count($patternIds)) {
                    throw ValidationException::withMessages([
                        "staffing_options.{$index}.pattern_counts" => '対象店舗のシフトパターンだけを指定してください。',
                    ]);
                }

                $option->patterns()
                    ->when(
                        $patternIds !== [],
                        fn ($query) => $query->whereNotIn(
                            'store_shift_pattern_id',
                            $patternIds,
                        ),
                    )
                    ->delete();

                foreach ($patternCounts as $patternId => $requiredCount) {
                    $option->patterns()->updateOrCreate(
                        ['store_shift_pattern_id' => $patternId],
                        ['required_count' => $requiredCount],
                    );
                }
            }
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertEligibleUsers(
        Store $store,
        array $userIds,
        string $roleCode,
    ): void {
        $eligibleCount = User::query()
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereKey($userIds)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'code',
                $roleCode,
            ))
            ->count();

        if ($eligibleCount !== count($userIds)) {
            throw ValidationException::withMessages([
                'users' => '同一組織の有効な対象ユーザーだけを指定してください。',
            ]);
        }
    }

    private function lockStore(Store $store): Store
    {
        return Store::query()
            ->whereKey($store->getKey())
            ->where('organization_id', $store->organization_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }

    private function isCurrentMembership(?object $membership, string $today): bool
    {
        return $membership !== null
            && (bool) $membership->is_active
            && (
                $membership->started_on === null
                || $membership->started_on <= $today
            )
            && (
                $membership->ended_on === null
                || $membership->ended_on >= $today
            );
    }
}
