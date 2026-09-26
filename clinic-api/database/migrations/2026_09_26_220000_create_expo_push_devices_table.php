<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('expo_push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('device_id', 120)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'device_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('expo_push_devices'); }
};
