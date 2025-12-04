<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'pref_lang')) {
                $table->string('pref_lang', 5)->default('en')->after('email');
            }
            if (!Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->change();
            }
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            // no down; optional
        });
    }
};
