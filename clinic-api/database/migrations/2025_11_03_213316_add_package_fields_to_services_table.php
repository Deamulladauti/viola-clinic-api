<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // package flags (generic so we can support sessions OR minutes)
            $table->boolean('is_package')->default(false)->after('is_bookable');
            $table->unsignedInteger('total_sessions')->nullable()->after('is_package'); // e.g., laser 6 sessions
            $table->unsignedInteger('total_minutes')->nullable()->after('total_sessions'); // e.g., solarium 100 minutes
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['is_package', 'total_sessions', 'total_minutes']);
        });
    }
};

