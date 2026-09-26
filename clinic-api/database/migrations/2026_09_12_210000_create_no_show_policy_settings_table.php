<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('no_show_policy_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('misses_before_penalty')->default(2);
            $table->unsignedSmallInteger('sessions_to_deduct')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('no_show_policy_settings')->insert([
            'id' => 1,
            'is_enabled' => false,
            'misses_before_penalty' => 2,
            'sessions_to_deduct' => 1,
            'updated_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_policy_settings');
    }
};
