<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_package_id')->constrained('service_packages')->cascadeOnDelete();

            // who applied the usage (optional)
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();

            // optional link to an appointment (future use)
            $table->unsignedBigInteger('appointment_id')->nullable(); // no FK yet to keep this phase independent
            $table->string('appointment_ref')->nullable();            // optional string reference if you prefer

            // the deduction (either sessions OR minutes can be > 0)
            $table->unsignedInteger('used_sessions')->default(0);
            $table->unsignedInteger('used_minutes')->default(0);

            $table->timestamp('used_at')->useCurrent();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_package_id', 'used_at']);
            $table->index(['staff_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_logs');
    }
};
