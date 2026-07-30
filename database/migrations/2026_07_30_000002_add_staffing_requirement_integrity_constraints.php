<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE store_shift_patterns '
            .'ADD CONSTRAINT patterns_day_offsets_check '
            .'CHECK ('
            .'(start_day_offset IS NULL AND end_day_offset IS NULL) '
            .'OR '
            .'(start_day_offset BETWEEN 0 AND 1 AND end_day_offset BETWEEN 0 AND 1)'
            .')',
        );
        DB::statement(
            'ALTER TABLE store_staffing_requirement_options '
            .'ADD CONSTRAINT staffing_requirement_options_display_order_check '
            .'CHECK (display_order >= 0)',
        );
        DB::statement(
            'ALTER TABLE store_staffing_requirement_option_patterns '
            .'ADD CONSTRAINT staffing_option_patterns_required_count_check '
            .'CHECK (required_count >= 0)',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE store_staffing_requirement_option_patterns '
            .'DROP CONSTRAINT IF EXISTS staffing_option_patterns_required_count_check',
        );
        DB::statement(
            'ALTER TABLE store_staffing_requirement_options '
            .'DROP CONSTRAINT IF EXISTS staffing_requirement_options_display_order_check',
        );
        DB::statement(
            'ALTER TABLE store_shift_patterns '
            .'DROP CONSTRAINT IF EXISTS patterns_day_offsets_check',
        );
    }
};
