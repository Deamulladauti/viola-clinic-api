<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('appointment_id')
                ->nullable()
                ->constrained('appointments')
                ->nullOnDelete();

            $table->foreignId('author_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('staff')
                ->nullOnDelete();

            $table->string('author_role')->default('admin');
            $table->string('type')->default('general');
            $table->text('note');
            $table->boolean('pinned')->default(false);

            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['appointment_id', 'created_at']);
            $table->index(['staff_id', 'created_at']);
            $table->index(['pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
    }
};