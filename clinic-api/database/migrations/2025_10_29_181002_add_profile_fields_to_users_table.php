<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'address_line1')) {
                $table->string('address_line1')->nullable()->after('avatar_path');
            }
            if (!Schema::hasColumn('users', 'address_line2')) {
                $table->string('address_line2')->nullable()->after('address_line1');
            }
            if (!Schema::hasColumn('users', 'city')) {
                $table->string('city')->nullable()->after('address_line2');
            }
            if (!Schema::hasColumn('users', 'country_code')) {
                $table->string('country_code', 2)->nullable()->after('city');
            }
            if (!Schema::hasColumn('users', 'preferred_language')) {
                $table->string('preferred_language', 5)->default('en')->after('country_code');
            }
            if (!Schema::hasColumn('users', 'marketing_opt_in')) {
                $table->boolean('marketing_opt_in')->default(false)->after('preferred_language');
            }
            if (!Schema::hasColumn('users', 'notification_prefs')) {
                $table->json('notification_prefs')->nullable()->after('marketing_opt_in');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $drops = [];

            foreach ([
                'phone',
                'avatar_path',
                'address_line1',
                'address_line2',
                'city',
                'country_code',
                'preferred_language',
                'marketing_opt_in',
                'notification_prefs',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $drops[] = $col;
                }
            }

            if (!empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }
};
