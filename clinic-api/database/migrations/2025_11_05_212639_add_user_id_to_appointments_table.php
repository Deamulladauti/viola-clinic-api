<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // nullable in case you ever allow true guest bookings
            $table->foreignId('user_id')
                  ->nullable()
                  ->after('staff_id')
                  ->constrained('users')
                  ->nullOnDelete(); // if user is deleted, keep the appointment but null the link
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
