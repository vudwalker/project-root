<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_schedules', function (Blueprint $table): void {
            $table->timestamp('monthly_members_initialized_at')
                ->nullable()
                ->after('draft_version');
            $table->integer('monthly_members_version')
                ->default(0)
                ->after('monthly_members_initialized_at');
        });

        Schema::create('shift_schedule_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_schedule_id')
                ->constrained('shift_schedules')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['shift_schedule_id', 'user_id'],
                'shift_schedule_users_schedule_user_unique',
            );
            $table->index(
                ['shift_schedule_id', 'display_order', 'user_id'],
                'shift_schedule_users_schedule_order_index',
            );
        });

        $this->backfillExistingSchedules();
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_schedule_users');

        Schema::table('shift_schedules', function (Blueprint $table): void {
            $table->dropColumn([
                'monthly_members_initialized_at',
                'monthly_members_version',
            ]);
        });
    }

    private function backfillExistingSchedules(): void
    {
        $timestamp = now();

        DB::table('shift_schedules')
            ->orderBy('id')
            ->chunkById(200, function ($schedules) use ($timestamp): void {
                foreach ($schedules as $schedule) {
                    $monthStart = date('Y-m-01', strtotime((string) $schedule->target_month));
                    $monthEnd = date('Y-m-t', strtotime($monthStart));

                    $members = DB::table('store_user')
                        ->join('users', 'users.id', '=', 'store_user.user_id')
                        ->join('stores', 'stores.id', '=', 'store_user.store_id')
                        ->join('role_user', 'role_user.user_id', '=', 'users.id')
                        ->join('roles', 'roles.id', '=', 'role_user.role_id')
                        ->where('store_user.store_id', $schedule->store_id)
                        ->whereColumn('users.organization_id', 'stores.organization_id')
                        ->where('store_user.is_active', true)
                        ->where('users.status', 'active')
                        ->where('roles.code', 'staff')
                        ->where(function ($query) use ($monthEnd): void {
                            $query
                                ->whereNull('store_user.started_on')
                                ->orWhereDate('store_user.started_on', '<=', $monthEnd);
                        })
                        ->where(function ($query) use ($monthStart): void {
                            $query
                                ->whereNull('store_user.ended_on')
                                ->orWhereDate('store_user.ended_on', '>=', $monthStart);
                        })
                        ->orderBy('store_user.display_order')
                        ->orderBy('users.name')
                        ->orderBy('users.id')
                        ->get([
                            'store_user.user_id',
                            'users.name',
                        ]);

                    $rows = $members
                        ->values()
                        ->map(fn ($member, int $index): array => [
                            'shift_schedule_id' => $schedule->id,
                            'user_id' => $member->user_id,
                            'display_order' => $index,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ])
                        ->all();

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('shift_schedule_users')->insert($chunk);
                    }

                    DB::table('shift_schedules')
                        ->where('id', $schedule->id)
                        ->update([
                            'monthly_members_initialized_at' => $timestamp,
                            'monthly_members_version' => 0,
                            'updated_at' => $timestamp,
                        ]);
                }
            });
    }
};
