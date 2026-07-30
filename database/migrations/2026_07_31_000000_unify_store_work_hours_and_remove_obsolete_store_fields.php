<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const WORK_HOUR_CONSTRAINTS = [
        'store_shift_patterns' => 'patterns_work_hours_check',
        'shifts' => 'shifts_work_hours_check',
        'published_shifts' => 'published_shifts_work_hours_check',
    ];

    /**
     * @var array<string, string>
     */
    private const WORK_MINUTE_CONSTRAINTS = [
        'store_shift_patterns' => 'patterns_work_minutes_check',
        'shifts' => 'shifts_work_minutes_check',
        'published_shifts' => 'published_shifts_work_minutes_check',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_status_check',
            );

            foreach (self::WORK_MINUTE_CONSTRAINTS as $table => $constraint) {
                DB::statement(
                    sprintf(
                        'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                        $table,
                        $constraint,
                    ),
                );
            }
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['primary_store_id']);
            $table->dropIndex(['primary_store_id']);
            $table->dropColumn('primary_store_id');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });

        foreach (array_keys(self::WORK_HOUR_CONSTRAINTS) as $table) {
            $this->convertMinutesToHours($table, $driver);
        }

        if ($driver === 'pgsql') {
            foreach (self::WORK_HOUR_CONSTRAINTS as $table => $constraint) {
                DB::statement(
                    sprintf(
                        'ALTER TABLE %s ADD CONSTRAINT %s CHECK (work_hours >= 0)',
                        $table,
                        $constraint,
                    ),
                );
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (array_keys(self::WORK_MINUTE_CONSTRAINTS) as $table) {
            $this->assertMinutePrecision($table);
        }

        if ($driver === 'pgsql') {
            foreach (self::WORK_HOUR_CONSTRAINTS as $table => $constraint) {
                DB::statement(
                    sprintf(
                        'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                        $table,
                        $constraint,
                    ),
                );
            }
        }

        foreach (array_keys(self::WORK_MINUTE_CONSTRAINTS) as $table) {
            $this->convertHoursToMinutes($table, $driver);
        }

        Schema::table('stores', function (Blueprint $table): void {
            $table->string('status', 30)
                ->default('active')
                ->after('name');
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('primary_store_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->index('primary_store_id');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE stores ADD CONSTRAINT stores_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );

            foreach (self::WORK_MINUTE_CONSTRAINTS as $table => $constraint) {
                DB::statement(
                    sprintf(
                        'ALTER TABLE %s ADD CONSTRAINT %s CHECK (work_minutes >= 0)',
                        $table,
                        $constraint,
                    ),
                );
            }
        }
    }

    private function convertMinutesToHours(string $table, string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::statement(
                sprintf(
                    'ALTER TABLE %s RENAME COLUMN work_minutes TO work_hours',
                    $table,
                ),
            );
            DB::statement(
                sprintf(
                    'ALTER TABLE %s ALTER COLUMN work_hours TYPE numeric(6,2) '
                    .'USING (work_hours::numeric / 60)',
                    $table,
                ),
            );

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->renameColumn('work_minutes', 'work_hours');
        });
        DB::table($table)->update([
            'work_hours' => DB::raw('work_hours / 60.0'),
        ]);
        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $column = $blueprint->decimal('work_hours', 6, 2)->change();

            if ($table === 'store_shift_patterns') {
                $column->default(0);
            }
        });
    }

    private function convertHoursToMinutes(string $table, string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::statement(
                sprintf(
                    'ALTER TABLE %s ALTER COLUMN work_hours TYPE integer '
                    .'USING (work_hours * 60)::integer',
                    $table,
                ),
            );
            DB::statement(
                sprintf(
                    'ALTER TABLE %s RENAME COLUMN work_hours TO work_minutes',
                    $table,
                ),
            );

            return;
        }

        DB::table($table)->update([
            'work_hours' => DB::raw('work_hours * 60'),
        ]);
        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $column = $blueprint->integer('work_hours')->change();

            if ($table === 'store_shift_patterns') {
                $column->default(0);
            }
        });
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->renameColumn('work_hours', 'work_minutes');
        });
    }

    private function assertMinutePrecision(string $table): void
    {
        $hasFractionalMinute = DB::table($table)
            ->pluck('work_hours')
            ->contains(function (mixed $hours): bool {
                $normalized = number_format((float) $hours, 2, '.', '');
                [, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
                $hundredths = (int) str_pad($fraction, 2, '0');

                return ($hundredths * 60) % 100 !== 0;
            });

        if ($hasFractionalMinute) {
            throw new RuntimeException(
                "{$table}.work_hoursに整数分へ戻せない値があるため、ロールバックできません。",
            );
        }
    }
};
