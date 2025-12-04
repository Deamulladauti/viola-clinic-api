<?php
// database/migrations/2025_11_12_000004_package_hot_index.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->index(['user_id','service_id','status'], 'packages_user_service_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            try { $table->dropIndex('packages_user_service_status_idx'); } catch (\Throwable $e) {}
        });
    }
};
