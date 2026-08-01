<?php

use App\Models\Shift;
use App\Models\Store;
use App\Services\Admin\DraftShiftWarningService;
use App\Services\Admin\PatternStaffingWarningEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = Store::query()->where('code', 'noda')->firstOrFail();
$date = CarbonImmutable::parse('2026-07-08');
$shifts = Shift::query()
    ->whereDate('work_date', $date->toDateString())
    ->whereHas('schedule', fn ($query) => $query->where('store_id', $store->getKey()))
    ->with(['schedule.store', 'user.roles', 'user.stores'])
    ->get();
$method = new ReflectionMethod(PatternStaffingWarningEvaluator::class, 'isEligibleStaffShift');
$evaluator = app(PatternStaffingWarningEvaluator::class);
$warningResult = app(DraftShiftWarningService::class)->evaluate(
    $store,
    $date->startOfMonth(),
);

echo json_encode([
    'shifts' => $shifts->map(fn (Shift $shift): array => [
        'id' => $shift->getKey(),
        'user_id' => $shift->user_id,
        'name' => $shift->user?->name,
        'code' => $shift->pattern_code,
        'roles' => $shift->user?->roles->pluck('code')->all(),
        'membership' => $shift->user?->stores
            ->firstWhere('id', $store->getKey())?->pivot?->only([
                'is_active',
                'started_on',
                'ended_on',
            ]),
        'eligible' => $method->invoke($evaluator, $shift, $store, $date),
    ])->all(),
    'warnings' => collect($warningResult['warnings'])
        ->where('work_date', $date->toDateString())
        ->values()
        ->all(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
