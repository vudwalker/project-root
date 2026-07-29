<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropForeign(['shift_schedule_id']);
            $table->foreign('shift_schedule_id', 'shifts_shift_schedule_id_foreign')
                ->references('id')
                ->on('shift_schedules')
                ->restrictOnDelete();
        });

        Schema::table('published_shifts', function (Blueprint $table): void {
            $table->dropForeign(['shift_schedule_id']);
            $table->foreign('shift_schedule_id', 'published_shifts_shift_schedule_id_foreign')
                ->references('id')
                ->on('shift_schedules')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('published_shifts', function (Blueprint $table): void {
            $table->dropForeign(['shift_schedule_id']);
            $table->foreign('shift_schedule_id', 'published_shifts_shift_schedule_id_foreign')
                ->references('id')
                ->on('shift_schedules')
                ->cascadeOnDelete();
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropForeign(['shift_schedule_id']);
            $table->foreign('shift_schedule_id', 'shifts_shift_schedule_id_foreign')
                ->references('id')
                ->on('shift_schedules')
                ->cascadeOnDelete();
        });
    }
};
