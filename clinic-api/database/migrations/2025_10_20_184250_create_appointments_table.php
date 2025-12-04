<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('appointments', function (Blueprint $table) {
        $table->id();

        // Links
        $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
        $table->unsignedBigInteger('staff_id')->nullable(); // FK later when Staff exists

        // When
        $table->date('date');                // YYYY-MM-DD
        $table->time('starts_at');           // HH:MM:SS
        $table->unsignedSmallInteger('duration_minutes'); // e.g., 30, 45, 60

        // Money at time of booking
        $table->decimal('price', 10, 2);

        // Customer snapshot (guest booking)
        $table->string('customer_name');
        $table->string('customer_phone')->nullable();
        $table->string('customer_email')->nullable();

        // Status (use CHECK for safety; SQLite supports it; MySQL ignores invalid values via app validation anyway)
        $table->enum('status', ['pending','confirmed','cancelled','completed','no_show'])->default('pending');
        $table->text('notes')->nullable();

        // Nice-to-have: short reference code for guests (unique)
        $table->string('reference_code', 12)->unique();

        // Indexes for speed
        $table->index(['date', 'starts_at']);
        $table->index(['service_id', 'date']);
        $table->index(['staff_id', 'date']);
        $table->index('status');

        // Soft deletes now (safe) — you can remove if you prefer hard delete only
        $table->softDeletes();

        $table->timestamps();
    });

}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
