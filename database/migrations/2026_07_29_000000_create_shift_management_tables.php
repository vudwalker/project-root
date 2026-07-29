<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('store_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index(['store_id', 'is_active', 'display_order']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('store_shift_manager', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index(['store_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('store_shift_patterns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('code', 20);
            $table->integer('work_minutes')->default(0);
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'is_active', 'display_order']);
        });

        Schema::create('shift_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('target_month');
            $table->unsignedBigInteger('draft_version')->default(0);
            $table->unsignedBigInteger('published_version')->nullable();
            $table->timestamp('shift_updated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'target_month']);
            $table->index('target_month');
            $table->index('published_at');
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_schedule_id')
                ->constrained('shift_schedules')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('store_shift_pattern_id')
                ->constrained('store_shift_patterns')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->smallInteger('sequence');
            $table->uuid('entry_uuid')->unique();
            $table->string('pattern_code', 20);
            $table->integer('work_minutes');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['shift_schedule_id', 'user_id', 'work_date', 'sequence'],
                'shifts_cell_sequence_unique',
            );
            $table->index('store_shift_pattern_id');
            $table->index(['user_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::create('published_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_schedule_id')
                ->constrained('shift_schedules')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('work_date');
            $table->smallInteger('sequence');
            $table->string('pattern_code', 20);
            $table->integer('work_minutes');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(
                ['shift_schedule_id', 'user_id', 'work_date', 'sequence'],
                'published_shifts_cell_sequence_unique',
            );
            $table->index(['user_id', 'work_date']);
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_shifts');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_schedules');
        Schema::dropIfExists('store_shift_patterns');
        Schema::dropIfExists('store_shift_manager');
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
