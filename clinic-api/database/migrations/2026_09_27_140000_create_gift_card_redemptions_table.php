<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gift_card_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->constrained('gift_cards')->restrictOnDelete();
            $table->foreignId('package_payment_id')->unique()->constrained('package_payments')->restrictOnDelete();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->decimal('amount_eur', 12, 2);
            $table->decimal('amount_mkd', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_id')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['gift_card_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('gift_card_redemptions'); }
};
