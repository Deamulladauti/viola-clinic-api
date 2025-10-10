<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();

            // Core fields
            $table->string('name', 120)->unique();   // unique (case-insensitive on MySQL by default)
            $table->string('slug', 120)->unique();   // kebab-case recommended at app level
            $table->text('description')->nullable();

            // Status
            $table->boolean('is_active')->default(true)->index();

            // Bookkeeping
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
