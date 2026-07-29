<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->string('status', 30)->default('active');
            $table->integer('display_order')->default(0);
            $table->string('staffing_check_mode', 30)->default('disabled');
            $table->integer('required_staff_count')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index('display_order');
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('primary_store_id')
                ->nullable()
                ->constrained('stores')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('status', 30)->default('active');
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('primary_store_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['primary_store_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['primary_store_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'organization_id',
                'primary_store_id',
                'status',
                'deleted_at',
            ]);
        });

        Schema::dropIfExists('stores');
        Schema::dropIfExists('organizations');
    }
};
