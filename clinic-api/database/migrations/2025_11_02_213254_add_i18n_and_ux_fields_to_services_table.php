<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // UX flags
            $table->boolean('is_bookable')->default(true)->after('is_active');

            // i18n JSON fields (SQLite will store as TEXT; that’s fine)
            $table->json('name_i18n')->nullable()->after('name');
            $table->json('short_description_i18n')->nullable()->after('short_description');
            $table->json('description_i18n')->nullable()->after('description');

            // Prep instructions (translated)
            $table->json('prep_instructions')->nullable()->after('description_i18n');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'is_bookable',
                'name_i18n',
                'short_description_i18n',
                'description_i18n',
                'prep_instructions',
            ]);
        });
    }
};
