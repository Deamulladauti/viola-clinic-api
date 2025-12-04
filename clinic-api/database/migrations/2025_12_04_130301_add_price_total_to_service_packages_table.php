<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ Only add the column if it does NOT exist
        if (!Schema::hasColumn('service_packages', 'price_total')) {
            Schema::table('service_packages', function (Blueprint $table) {
                $table->decimal('price_total', 10, 2)
                      ->nullable()
                      ->after('price_paid');
            });
        }
    }

    public function down(): void
    {
        // Safe: only drop if it exists
        if (Schema::hasColumn('service_packages', 'price_total')) {
            Schema::table('service_packages', function (Blueprint $table) {
                $table->dropColumn('price_total');
            });
        }
    }
};
