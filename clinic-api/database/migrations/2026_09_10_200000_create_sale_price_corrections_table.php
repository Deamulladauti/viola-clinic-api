<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_price_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32); // appointment | package
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('before_terms');
            $table->json('after_terms');
            $table->decimal('amount_paid_at_correction', 10, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'sale_price_corrections_subject_idx');
            $table->index(['client_id', 'created_at'], 'sale_price_corrections_client_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_price_corrections');
    }
};
