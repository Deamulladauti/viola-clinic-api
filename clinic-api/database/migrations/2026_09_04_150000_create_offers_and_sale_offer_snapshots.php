<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('pricing_type', 32); // percent | fixed_discount | fixed_price
            $table->decimal('value', 10, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_on', 'ends_on']);
        });

        Schema::create('offer_service', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['offer_id', 'service_id']);
            $table->index('service_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_offer_id')->nullable()->after('sale_final_price');
            $table->string('sale_offer_name')->nullable()->after('sale_offer_id');
            $table->index('sale_offer_id');
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_offer_id')->nullable()->after('sale_final_price');
            $table->string('sale_offer_name')->nullable()->after('sale_offer_id');
            $table->index('sale_offer_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['sale_offer_id']);
            $table->dropColumn(['sale_offer_id', 'sale_offer_name']);
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropIndex(['sale_offer_id']);
            $table->dropColumn(['sale_offer_id', 'sale_offer_name']);
        });

        Schema::dropIfExists('offer_service');
        Schema::dropIfExists('offers');
    }
};
