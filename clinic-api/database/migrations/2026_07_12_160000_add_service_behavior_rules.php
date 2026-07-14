<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'usage_type')) {
                $table->string('usage_type', 20)->default('single')->after('total_minutes');
            }

            if (! Schema::hasColumn('services', 'minimum_interval_days')) {
                $table->unsignedSmallInteger('minimum_interval_days')->default(0)->after('usage_type');
            }

            if (! Schema::hasColumn('services', 'deduction_method')) {
                $table->string('deduction_method', 40)->default('automatic_on_completion')->after('minimum_interval_days');
            }

            if (! Schema::hasColumn('services', 'staff_policy')) {
                $table->string('staff_policy', 40)->default('per_appointment')->after('deduction_method');
            }
        });

        DB::table('services')
            ->where('is_package', true)
            ->whereNotNull('total_sessions')
            ->whereNull('total_minutes')
            ->update([
                'usage_type' => 'session',
                'deduction_method' => 'automatic_on_completion',
                'staff_policy' => 'any_qualified_staff',
            ]);

        DB::table('services')
            ->where('is_package', true)
            ->whereNotNull('total_minutes')
            ->whereNull('total_sessions')
            ->update([
                'usage_type' => 'minutes',
                'deduction_method' => 'manual',
                'staff_policy' => 'any_qualified_staff',
                'is_bookable' => false,
            ]);

        DB::table('services')
            ->where(function ($query) {
                $query->where('is_package', false)
                    ->orWhereNull('is_package');
            })
            ->update([
                'usage_type' => 'single',
                'deduction_method' => 'automatic_on_completion',
                'staff_policy' => 'per_appointment',
            ]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('services', 'usage_type') ? 'usage_type' : null,
                Schema::hasColumn('services', 'minimum_interval_days') ? 'minimum_interval_days' : null,
                Schema::hasColumn('services', 'deduction_method') ? 'deduction_method' : null,
                Schema::hasColumn('services', 'staff_policy') ? 'staff_policy' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
