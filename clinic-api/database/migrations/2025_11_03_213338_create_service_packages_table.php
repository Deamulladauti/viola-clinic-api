<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();

            // owner and source service
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();

            // snapshot fields to preserve what was sold at that time
            $table->string('service_name')->nullable();
            $table->unsignedInteger('snapshot_total_sessions')->nullable();
            $table->unsignedInteger('snapshot_total_minutes')->nullable();
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->string('currency', 3)->default('EUR');

            // live balances
            $table->unsignedInteger('remaining_sessions')->nullable();
            $table->unsignedInteger('remaining_minutes')->nullable();

            // lifecycle
            $table->string('status', 20)->default('active'); 
            // allowed: active, exhausted, expired, cancelled (kept as string for SQLite compatibility)

            // time bounds (optional)
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();

            // bookkeeping
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // quick lookups
            $table->index(['user_id', 'status']);
            $table->index(['service_id', 'status']);
            $table->index(['expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};

