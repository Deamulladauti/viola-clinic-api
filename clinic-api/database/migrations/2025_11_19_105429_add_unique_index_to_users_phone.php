<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Ensure the phone column exists
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 50)->nullable()->after('email');
            }
        });

        // 2) Add unique index ONLY if it doesn't exist yet
        if (! Schema::hasIndex('users', 'users_phone_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('phone', 'users_phone_unique');
            });
        }
    }

    public function down(): void
    {
        // Drop the unique index if it exists
        if (Schema::hasIndex('users', 'users_phone_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_phone_unique');
            });
        }
    }
};
