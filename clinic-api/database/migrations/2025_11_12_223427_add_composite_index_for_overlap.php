<?php
// database/migrations/2025_11_12_000002_add_composite_index_for_overlap.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['staff_id','date','starts_at'], 'appointments_staff_date_start_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            try { $table->dropIndex('appointments_staff_date_start_idx'); } catch (\Throwable $e) {}
        });
    }
};
