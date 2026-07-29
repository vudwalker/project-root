<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $statements = [
                "ALTER TABLE stores ADD CONSTRAINT stores_status_check CHECK (status IN ('active', 'inactive'))",
                "ALTER TABLE stores ADD CONSTRAINT stores_staffing_mode_check CHECK (staffing_check_mode IN ('disabled', 'fixed_total'))",
                'ALTER TABLE stores ADD CONSTRAINT stores_required_staff_count_check CHECK (required_staff_count IS NULL OR required_staff_count >= 0)',
                "ALTER TABLE stores ADD CONSTRAINT stores_fixed_staff_count_check CHECK (staffing_check_mode <> 'fixed_total' OR required_staff_count IS NOT NULL)",
                "ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'on_leave', 'retired'))",
                'ALTER TABLE store_user ADD CONSTRAINT store_user_period_check CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)',
                'ALTER TABLE store_shift_manager ADD CONSTRAINT store_shift_manager_period_check CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)',
                'ALTER TABLE store_shift_patterns ADD CONSTRAINT patterns_work_minutes_check CHECK (work_minutes >= 0)',
                'ALTER TABLE shift_schedules ADD CONSTRAINT shift_schedules_draft_version_check CHECK (draft_version >= 0)',
                'ALTER TABLE shift_schedules ADD CONSTRAINT shift_schedules_published_version_check CHECK (published_version IS NULL OR published_version >= 0)',
                "ALTER TABLE shift_schedules ADD CONSTRAINT shift_schedules_target_month_check CHECK (target_month = date_trunc('month', target_month)::date)",
                'ALTER TABLE shifts ADD CONSTRAINT shifts_sequence_check CHECK (sequence >= 1)',
                'ALTER TABLE shifts ADD CONSTRAINT shifts_work_minutes_check CHECK (work_minutes >= 0)',
                'ALTER TABLE published_shifts ADD CONSTRAINT published_shifts_sequence_check CHECK (sequence >= 1)',
                'ALTER TABLE published_shifts ADD CONSTRAINT published_shifts_work_minutes_check CHECK (work_minutes >= 0)',
            ];

            foreach ($statements as $statement) {
                DB::statement($statement);
            }
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX store_shift_manager_one_active_per_store '
                .'ON store_shift_manager (store_id) WHERE is_active = true',
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS store_shift_manager_one_active_per_store');
        }

        if ($driver !== 'pgsql') {
            return;
        }

        $constraints = [
            'published_shifts' => [
                'published_shifts_work_minutes_check',
                'published_shifts_sequence_check',
            ],
            'shifts' => [
                'shifts_work_minutes_check',
                'shifts_sequence_check',
            ],
            'shift_schedules' => [
                'shift_schedules_target_month_check',
                'shift_schedules_published_version_check',
                'shift_schedules_draft_version_check',
            ],
            'store_shift_patterns' => ['patterns_work_minutes_check'],
            'store_shift_manager' => ['store_shift_manager_period_check'],
            'store_user' => ['store_user_period_check'],
            'users' => ['users_status_check'],
            'stores' => [
                'stores_fixed_staff_count_check',
                'stores_required_staff_count_check',
                'stores_staffing_mode_check',
                'stores_status_check',
            ],
        ];

        foreach ($constraints as $table => $names) {
            foreach ($names as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }
    }
};
