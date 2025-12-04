<?php
// database/migrations/2025_11_12_000001_fix_staff_fk_on_appointments.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('appointments', function (Blueprint $table) use ($driver) {
            // Always ensure an index for lookups
            if (!Schema::hasColumn('appointments', 'staff_id')) {
                // (unlikely) create if missing
                $table->foreignId('staff_id')->nullable()->after('service_id');
            }
            $table->index('staff_id', 'appointments_staff_id_idx');
        });

        // Add real FK only on drivers that support altering constraints sanely
        if (in_array($driver, ['mysql', 'pgsql'])) {
            Schema::table('appointments', function (Blueprint $table) {
                // Drop existing foreign key if a wrong one exists (ignore errors silently)
                try { $table->dropForeign(['staff_id']); } catch (\Throwable $e) {}
                $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
            });
        }
        // On sqlite: we rely on validation + index (no-op for FK).
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'])) {
            Schema::table('appointments', function (Blueprint $table) {
                try { $table->dropForeign(['staff_id']); } catch (\Throwable $e) {}
            });
        }
        Schema::table('appointments', function (Blueprint $table) {
            try { $table->dropIndex('appointments_staff_id_idx'); } catch (\Throwable $e) {}
        });
    }
};
