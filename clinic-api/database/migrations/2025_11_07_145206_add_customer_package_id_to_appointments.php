<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'service_package_id')) {
                $table->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete()->after('staff_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'service_package_id')) {
                $table->dropConstrainedForeignId('service_package_id');
            }
        });
    }
};