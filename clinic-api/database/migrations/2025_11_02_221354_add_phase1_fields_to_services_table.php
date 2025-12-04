<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Base short description (used as fallback)
            if (!Schema::hasColumn('services', 'short_description')) {
                $table->text('short_description')->nullable()->after('name');
            }

            // Phase 1 fields
            if (!Schema::hasColumn('services', 'is_bookable')) {
                $table->boolean('is_bookable')->default(true)->after('is_active');
            }
            if (!Schema::hasColumn('services', 'name_i18n')) {
                $table->json('name_i18n')->nullable()->after('name');
            }
            if (!Schema::hasColumn('services', 'short_description_i18n')) {
                $table->json('short_description_i18n')->nullable()->after('short_description');
            }
            if (!Schema::hasColumn('services', 'description_i18n')) {
                $table->json('description_i18n')->nullable()->after('description');
            }
            if (!Schema::hasColumn('services', 'prep_instructions')) {
                $table->json('prep_instructions')->nullable()->after('description_i18n');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'prep_instructions')) {
                $table->dropColumn('prep_instructions');
            }
            if (Schema::hasColumn('services', 'description_i18n')) {
                $table->dropColumn('description_i18n');
            }
            if (Schema::hasColumn('services', 'short_description_i18n')) {
                $table->dropColumn('short_description_i18n');
            }
            if (Schema::hasColumn('services', 'name_i18n')) {
                $table->dropColumn('name_i18n');
            }
            if (Schema::hasColumn('services', 'is_bookable')) {
                $table->dropColumn('is_bookable');
            }
            if (Schema::hasColumn('services', 'short_description')) {
                $table->dropColumn('short_description');
            }
        });
    }
};
