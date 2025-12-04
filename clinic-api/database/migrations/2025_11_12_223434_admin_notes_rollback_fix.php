<?php
// database/migrations/2025_11_12_000003_admin_notes_rollback_fix.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // No-op on up; column already exists from your earlier migration.
    }

    public function down(): void
    {
        // Provide a clean rollback that drops the column.
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'admin_notes')) {
                $table->dropColumn('admin_notes');
            }
        });
    }
};

