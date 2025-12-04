<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Many-to-many: which staff can perform which services
        Schema::create('service_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unique(['service_id','staff_id']);
            $table->timestamps();
        });

        // Weekly recurring schedule per staff (0=Sun … 6=Sat)
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0..6
            $table->time('start_time'); // HH:MM:SS
            $table->time('end_time');   // HH:MM:SS
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['staff_id','weekday']);
        });

        // Specific absences/exceptions (full day or partial)
        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable(); // null => whole day off
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['staff_id','date']);
        });

        // Add FK to appointments.staff_id now that staff exists
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
            
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropIndex(['staff_id','date']);
        });

        Schema::dropIfExists('staff_time_off');
        Schema::dropIfExists('staff_schedules');
        Schema::dropIfExists('service_staff');
        Schema::dropIfExists('staff');
    }

};
