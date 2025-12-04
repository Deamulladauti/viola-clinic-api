<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_payments', function (Blueprint $table) {
            // make service_package_id nullable so we can store appointment-only payments
            $table->unsignedBigInteger('service_package_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('package_payments', function (Blueprint $table) {
            // revert if needed (back to NOT NULL) – be careful if you have nulls
            $table->unsignedBigInteger('service_package_id')->nullable(false)->change();
        });
    }
};
