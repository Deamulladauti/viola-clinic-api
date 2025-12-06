<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_logs', function (Blueprint $table) {
            // Make columns nullable
            $table->integer('used_sessions')->nullable()->change();
            $table->integer('used_minutes')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('package_logs', function (Blueprint $table) {
            // Revert back to NOT NULL with default 0
            $table->integer('used_sessions')->default(0)->nullable(false)->change();
            $table->integer('used_minutes')->default(0)->nullable(false)->change();
        });
    }
};

