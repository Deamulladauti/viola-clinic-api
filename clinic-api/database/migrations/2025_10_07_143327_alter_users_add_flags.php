<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users','phone'))     $t->string('phone',30)->nullable()->unique();
            if (!Schema::hasColumn('users','is_active')) $t->boolean('is_active')->default(true)->index();
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users','phone'))     $t->dropColumn('phone');
            if (Schema::hasColumn('users','is_active')) $t->dropColumn('is_active');
        });
    }
};
