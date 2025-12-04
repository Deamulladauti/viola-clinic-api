<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            // Admin or staff who performed the action (nullable if system)
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 64); // e.g., assigned, reassigned, confirmed, cancelled
            $t->json('meta')->nullable(); // { "old_staff_id": 1, "new_staff_id": 3 }
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_logs');
    }
};
