<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('service_packages', 'assigned_staff_id')) {
                $table->unsignedBigInteger('assigned_staff_id')->nullable()->after('service_id');
                $table->index('assigned_staff_id');
            }

            if (! Schema::hasColumn('service_packages', 'snapshot_usage_type')) {
                $table->string('snapshot_usage_type', 20)->nullable()->after('snapshot_total_minutes');
            }

            if (! Schema::hasColumn('service_packages', 'snapshot_minimum_interval_days')) {
                $table->unsignedSmallInteger('snapshot_minimum_interval_days')->nullable()->after('snapshot_usage_type');
            }

            if (! Schema::hasColumn('service_packages', 'snapshot_deduction_method')) {
                $table->string('snapshot_deduction_method', 40)->nullable()->after('snapshot_minimum_interval_days');
            }

            if (! Schema::hasColumn('service_packages', 'snapshot_staff_policy')) {
                $table->string('snapshot_staff_policy', 40)->nullable()->after('snapshot_deduction_method');
            }

            if (! Schema::hasColumn('service_packages', 'snapshot_duration_minutes')) {
                $table->unsignedSmallInteger('snapshot_duration_minutes')->nullable()->after('snapshot_staff_policy');
            }
        });

        DB::table('service_packages')->where('status', 'used')->update(['status' => 'exhausted']);
        DB::table('service_packages')->where('status', 'frozen')->update(['status' => 'paused']);

        $services = DB::table('services')->get()->keyBy('id');

        DB::table('service_packages')
            ->orderBy('id')
            ->chunkById(100, function ($packages) use ($services) {
                foreach ($packages as $package) {
                    $service = $services->get($package->service_id);

                    $usageType = $service->usage_type ?? null;
                    if (! $usageType) {
                        $usageType = $package->remaining_minutes !== null ? 'minutes' : 'session';
                    }

                    DB::table('service_packages')
                        ->where('id', $package->id)
                        ->update([
                            'snapshot_usage_type' => $package->snapshot_usage_type ?? $usageType,
                            'snapshot_minimum_interval_days' => $package->snapshot_minimum_interval_days
                                ?? (int) ($service->minimum_interval_days ?? 0),
                            'snapshot_deduction_method' => $package->snapshot_deduction_method
                                ?? ($service->deduction_method ?? ($usageType === 'minutes' ? 'manual' : 'automatic_on_completion')),
                            'snapshot_staff_policy' => $package->snapshot_staff_policy
                                ?? ($service->staff_policy ?? ($usageType === 'session' ? 'any_qualified_staff' : 'per_appointment')),
                            'snapshot_duration_minutes' => $package->snapshot_duration_minutes
                                ?? ($service->duration_minutes ?? null),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            if (Schema::hasColumn('service_packages', 'assigned_staff_id')) {
                try {
                    $table->dropIndex(['assigned_staff_id']);
                } catch (Throwable) {
                    // Some SQLite installations rebuild indexes differently.
                }
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('service_packages', 'assigned_staff_id') ? 'assigned_staff_id' : null,
                Schema::hasColumn('service_packages', 'snapshot_usage_type') ? 'snapshot_usage_type' : null,
                Schema::hasColumn('service_packages', 'snapshot_minimum_interval_days') ? 'snapshot_minimum_interval_days' : null,
                Schema::hasColumn('service_packages', 'snapshot_deduction_method') ? 'snapshot_deduction_method' : null,
                Schema::hasColumn('service_packages', 'snapshot_staff_policy') ? 'snapshot_staff_policy' : null,
                Schema::hasColumn('service_packages', 'snapshot_duration_minutes') ? 'snapshot_duration_minutes' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
