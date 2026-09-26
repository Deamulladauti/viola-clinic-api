<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('package_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('penalty_trigger_appointment_id')->nullable()->unique('package_logs_penalty_trigger_unique');
            $table->json('penalty_policy_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('package_logs', function (Blueprint $table) {
            $table->dropUnique('package_logs_penalty_trigger_unique');
            $table->dropColumn(['penalty_trigger_appointment_id', 'penalty_policy_snapshot']);
        });
    }
};
