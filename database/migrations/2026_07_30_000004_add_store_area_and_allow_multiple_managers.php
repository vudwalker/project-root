<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('area', 100)->nullable()->after('name');
            $table->index(
                ['organization_id', 'area'],
                'stores_organization_id_area_index',
            );
        });

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'DROP INDEX IF EXISTS store_shift_manager_one_active_per_store',
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX store_shift_manager_one_active_per_store '
                .'ON store_shift_manager (store_id) WHERE is_active = true',
            );
        }

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex('stores_organization_id_area_index');
            $table->dropColumn('area');
        });
    }
};
