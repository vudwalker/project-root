<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'DROP CONSTRAINT shift_schedules_published_version_check',
            );
        }

        Schema::table('shift_schedules', function (Blueprint $table): void {
            $table->unsignedBigInteger('published_draft_version')
                ->nullable()
                ->after('published_version');
        });

        Schema::table('shift_schedules', function (Blueprint $table): void {
            $table->renameColumn('published_by', 'published_by_user_id');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'RENAME CONSTRAINT shift_schedules_published_by_foreign '
                .'TO shift_schedules_published_by_user_id_foreign',
            );
        }

        DB::table('shift_schedules')
            ->whereNotNull('published_version')
            ->update([
                'published_draft_version' => DB::raw('published_version'),
                'published_version' => 1,
            ]);

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'ADD CONSTRAINT shift_schedules_published_version_check '
                .'CHECK (published_version IS NULL OR published_version >= 1)',
            );
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'ADD CONSTRAINT shift_schedules_published_draft_version_check '
                .'CHECK (published_draft_version IS NULL OR published_draft_version >= 0)',
            );
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'ADD CONSTRAINT shift_schedules_publication_state_check CHECK ('
                .'(published_version IS NULL '
                .'AND published_draft_version IS NULL '
                .'AND published_at IS NULL '
                .'AND published_by_user_id IS NULL) '
                .'OR (published_version IS NOT NULL '
                .'AND published_draft_version IS NOT NULL '
                .'AND published_at IS NOT NULL)'
                .')',
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'DROP CONSTRAINT shift_schedules_publication_state_check',
            );
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'DROP CONSTRAINT shift_schedules_published_draft_version_check',
            );
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'DROP CONSTRAINT shift_schedules_published_version_check',
            );
        }

        DB::table('shift_schedules')
            ->whereNotNull('published_draft_version')
            ->update([
                'published_version' => DB::raw('published_draft_version'),
            ]);

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'RENAME CONSTRAINT shift_schedules_published_by_user_id_foreign '
                .'TO shift_schedules_published_by_foreign',
            );
        }

        Schema::table('shift_schedules', function (Blueprint $table): void {
            $table->renameColumn('published_by_user_id', 'published_by');
        });

        Schema::table('shift_schedules', function (Blueprint $table): void {
            $table->dropColumn('published_draft_version');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE shift_schedules '
                .'ADD CONSTRAINT shift_schedules_published_version_check '
                .'CHECK (published_version IS NULL OR published_version >= 0)',
            );
        }
    }
};
