<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_shift_patterns', function (Blueprint $table): void {
            $table->time('start_time')->nullable()->after('work_minutes');
            $table->unsignedSmallInteger('start_day_offset')->nullable()->after('start_time');
            $table->time('end_time')->nullable()->after('start_day_offset');
            $table->unsignedSmallInteger('end_day_offset')->nullable()->after('end_time');
        });

        Schema::create('store_staffing_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('work_date')->nullable();
            $table->unsignedSmallInteger('weekday')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['store_id', 'is_active', 'work_date'],
                'staffing_requirements_store_date_index',
            );
            $table->index(
                ['store_id', 'is_active', 'weekday'],
                'staffing_requirements_store_weekday_index',
            );
            $table->index(
                ['store_id', 'effective_from', 'effective_to'],
                'staffing_requirements_effective_period_index',
            );
        });

        Schema::create('store_staffing_requirement_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_staffing_requirement_id')
                ->constrained('store_staffing_requirements')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('code', 50);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['store_staffing_requirement_id', 'code'],
                'staffing_requirement_options_code_unique',
            );
        });

        Schema::create('store_staffing_requirement_option_patterns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_staffing_requirement_option_id')
                ->constrained('store_staffing_requirement_options')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('store_shift_pattern_id')
                ->constrained('store_shift_patterns')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedSmallInteger('required_count');
            $table->timestamps();

            $table->unique(
                [
                    'store_staffing_requirement_option_id',
                    'store_shift_pattern_id',
                ],
                'staffing_option_patterns_unique',
            );
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_staffing_mode_check',
        );
        DB::statement(
            'ALTER TABLE stores ADD CONSTRAINT stores_staffing_mode_check '
            ."CHECK (staffing_check_mode IN ('disabled', 'fixed_total', 'pattern_combinations'))",
        );
        DB::statement(
            'ALTER TABLE store_shift_patterns ADD CONSTRAINT patterns_time_window_check '
            .'CHECK ('
            .'(start_time IS NULL AND start_day_offset IS NULL AND end_time IS NULL AND end_day_offset IS NULL) '
            .'OR '
            .'(start_time IS NOT NULL AND start_day_offset IS NOT NULL '
            .'AND end_time IS NOT NULL AND end_day_offset IS NOT NULL '
            .'AND start_day_offset <= end_day_offset)'
            .')',
        );
        DB::statement(
            'ALTER TABLE store_staffing_requirements ADD CONSTRAINT staffing_requirements_selector_check '
            .'CHECK (work_date IS NULL OR weekday IS NULL)',
        );
        DB::statement(
            'ALTER TABLE store_staffing_requirements ADD CONSTRAINT staffing_requirements_weekday_check '
            .'CHECK (weekday IS NULL OR weekday BETWEEN 0 AND 6)',
        );
        DB::statement(
            'ALTER TABLE store_staffing_requirements ADD CONSTRAINT staffing_requirements_period_check '
            .'CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE store_staffing_requirements '
                .'DROP CONSTRAINT IF EXISTS staffing_requirements_period_check',
            );
            DB::statement(
                'ALTER TABLE store_staffing_requirements '
                .'DROP CONSTRAINT IF EXISTS staffing_requirements_weekday_check',
            );
            DB::statement(
                'ALTER TABLE store_staffing_requirements '
                .'DROP CONSTRAINT IF EXISTS staffing_requirements_selector_check',
            );
            DB::statement(
                'ALTER TABLE store_shift_patterns '
                .'DROP CONSTRAINT IF EXISTS patterns_time_window_check',
            );
            DB::statement(
                'ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_staffing_mode_check',
            );
            DB::table('stores')
                ->where('staffing_check_mode', 'pattern_combinations')
                ->update(['staffing_check_mode' => 'disabled']);
            DB::statement(
                'ALTER TABLE stores ADD CONSTRAINT stores_staffing_mode_check '
                ."CHECK (staffing_check_mode IN ('disabled', 'fixed_total'))",
            );
        }

        Schema::dropIfExists('store_staffing_requirement_option_patterns');
        Schema::dropIfExists('store_staffing_requirement_options');
        Schema::dropIfExists('store_staffing_requirements');

        Schema::table('store_shift_patterns', function (Blueprint $table): void {
            $table->dropColumn([
                'start_time',
                'start_day_offset',
                'end_time',
                'end_day_offset',
            ]);
        });
    }
};
