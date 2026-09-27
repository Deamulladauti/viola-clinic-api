<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->decimal('initial_value', 12, 2);
            $table->decimal('remaining_value', 12, 2);
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 24)->default('active'); // active, exhausted, disabled
            $table->timestamp('issued_at');
            $table->timestamp('purchased_at')->nullable();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('recipient_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
