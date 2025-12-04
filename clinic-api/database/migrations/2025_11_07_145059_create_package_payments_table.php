<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('package_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();     // payer (optional)
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('method', ['cash','card','bank','other']);
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index('service_package_id');
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_payments');
    }
};